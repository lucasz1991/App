<?php

namespace App\Services\Operations;

use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeEvent;
use App\Models\WorkTimeExport;
use App\Models\WorkTimeExportItem;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkTimeService
{
    public function manual(int $assignmentId, int $planRevision, array $data, string $eventKey, User $actor): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        $this->uuid($eventKey);
        $data = Validator::make($data, ['starts_at' => 'required|string', 'ends_at' => 'required|string', 'pause_minutes' => 'required|integer|min:0|max:1440', 'note' => 'required|string|min:5|max:1000'])->validate();
        $assignment = ShiftAssignment::where('user_id', $actor->id)->findOrFail($assignmentId);

        return OperationsTransaction::run(function () use ($assignment, $planRevision, $data, $eventKey, $actor) {
            Order::lockForUpdate()->findOrFail($assignment->shift->order_id);
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $assignment = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                $existing = WorkTimeEntry::findOrFail($event->work_time_entry_id);
                $this->check($existing->user_id === $actor->id && $existing->shift_assignment_id === $assignment->id && $event->kind === 'manual', 'Ereignisschlüssel wurde bereits verwendet.');

                return $existing;
            }
            $this->check($assignment->status->value === 'confirmed' && $assignment->plan_revision === $planRevision && $shift->revision === $planRevision && $shift->published_revision === $planRevision && ! in_array($shift->status->value, ['draft', 'cancelled'], true), 'Der aktuelle Dienst muss bestätigt sein.');
            $this->check($shift->starts_at->lte(now()) && ! WorkTimeEntry::where('shift_assignment_id', $assignment->id)->exists(), 'Dieser Dienst kann nicht nachgetragen werden.');
            [$start,$end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $shift->timezone);
            app(PayrollClosingService::class)->assertMutable($actor, $start, $end);
            $this->check($end->lte(now()->utc()) && $start->diffInMinutes($end) <= 1440 && $data['pause_minutes'] < $start->diffInMinutes($end), 'Zeitraum oder Pause sind ungültig.');
            $profile = $this->rules($actor, $start);
            $this->check($profile !== null, 'Regelprofil fehlt.');
            $entry = WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $actor->id, 'status' => 'completed', 'timezone' => $shift->timezone, 'starts_at' => $start, 'ends_at' => $end, 'pause_seconds' => $data['pause_minutes'] * 60, 'note' => $data['note'],
                'plan_snapshot' => ['shift_id' => $shift->id, 'revision' => $planRevision, 'title' => $shift->title, 'order_number' => $shift->order->order_number, 'customer' => $shift->order->customer?->company_name, 'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->ends_at->toIso8601String(), 'planned_break_minutes' => $shift->planned_break_minutes, 'rules' => $profile->only(['id', 'maximum_shift_minutes', 'break_after_minutes', 'minimum_break_minutes'])]]);
            $this->snapshotValuation($entry, $actor, $start);
            $this->assertNoTimeOverlap($entry);
            $this->snapshot($entry, 'manual', $actor);
            $this->event($entry, $actor, 'manual', $eventKey);

            return $entry;
        }, 3);
    }

    public function start(int $assignmentId, int $planRevision, string $eventKey, User $actor, ?CarbonImmutable $capturedAt = null, ?string $payloadHash = null, bool $requireNewEvent = false): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        $this->uuid($eventKey);
        $assignment = ShiftAssignment::where('user_id', $actor->id)->findOrFail($assignmentId);

        return OperationsTransaction::run(function () use ($assignment, $planRevision, $eventKey, $actor, $capturedAt, $payloadHash, $requireNewEvent) {
            Order::lockForUpdate()->findOrFail($assignment->shift->order_id);
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $assignment = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                $entry = WorkTimeEntry::findOrFail($event->work_time_entry_id);
                $this->check(! $requireNewEvent, 'Ereignisschlüssel wurde bereits außerhalb dieser Erfassung verwendet.');
                $this->check($entry->user_id === $actor->id && $entry->shift_assignment_id === $assignment->id && $event->kind === 'start', 'Ereignisschlüssel wurde bereits verwendet.');
                abort_if($payloadHash !== null && ($event->data['payload_hash'] ?? null) !== $payloadHash, 409, 'Wiederholung enthält geänderte Daten.');

                return $entry;
            }
            $this->check($assignment->status->value === 'confirmed' && $assignment->plan_revision === $planRevision && $shift->revision === $planRevision && $shift->published_revision === $planRevision, 'Der aktuelle Dienst muss bestätigt sein.');
            $this->check(! in_array($shift->status->value, ['draft', 'cancelled', 'completed'], true), 'Dieser Dienst kann nicht gestartet werden.');
            $now = $capturedAt ?? CarbonImmutable::now('UTC');
            app(PayrollClosingService::class)->assertMutable($actor, $now);
            $this->check($now->gte($shift->starts_at->subMinutes(config('operations.clock_start_early_minutes'))) && $now->lt($shift->ends_at), 'Der Dienst liegt außerhalb des Startzeitraums.');
            $this->check(! WorkTimeEntry::where('user_id', $actor->id)->whereIn('status', ['running', 'paused'])->exists(), 'Es läuft bereits eine Zeiterfassung.');
            $this->check(! WorkTimeEntry::where('shift_assignment_id', $assignment->id)->exists(), 'Für diesen Dienst existiert bereits eine Zeiterfassung.');
            app(StaffEligibilityService::class)->assertEligible($shift, $actor);
            $entry = WorkTimeEntry::create([
                'shift_assignment_id' => $assignment->id, 'user_id' => $actor->id, 'status' => 'running',
                'timezone' => $shift->timezone, 'starts_at' => $now,
                'plan_snapshot' => ['shift_id' => $shift->id, 'revision' => $planRevision, 'title' => $shift->title, 'order_number' => $shift->order->order_number, 'customer' => $shift->order->customer?->company_name, 'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->ends_at->toIso8601String(), 'planned_break_minutes' => $shift->planned_break_minutes, 'rules' => $this->rules($actor, $now)?->only(['id', 'maximum_shift_minutes', 'break_after_minutes', 'minimum_break_minutes'])],
            ]);
            $this->snapshotValuation($entry, $actor, $now);
            $this->openSession($entry, $now);
            $this->event($entry, $actor, 'start', $eventKey, $now, $payloadHash === null ? [] : ['payload_hash' => $payloadHash]);

            return $entry;
        }, 3);
    }

    public function clock(int $entryId, int $revision, string $action, string $eventKey, User $actor, ?CarbonImmutable $capturedAt = null, ?string $payloadHash = null, bool $requireNewEvent = false): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        $this->uuid($eventKey);

        return OperationsTransaction::run(function () use ($entryId, $revision, $action, $eventKey, $actor, $capturedAt, $payloadHash, $requireNewEvent) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($entryId);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                $this->check(! $requireNewEvent, 'Ereignisschlüssel wurde bereits außerhalb dieser Erfassung verwendet.');
                $this->check($event->work_time_entry_id === $entry->id && $event->actor_id === $actor->id && $event->kind === $action, 'Ereignisschlüssel wurde bereits verwendet.');
                abort_if($payloadHash !== null && ($event->data['payload_hash'] ?? null) !== $payloadHash, 409, 'Wiederholung enthält geänderte Daten.');

                return $entry;
            }
            $this->check($entry->revision === $revision, 'Zeitmeldung wurde geändert. Bitte neu laden.');
            $allowed = ['pause' => ['running'], 'resume' => ['paused'], 'stop' => ['running', 'paused'], 'submit' => ['completed']];
            $this->check(in_array($entry->status, $allowed[$action] ?? [], true), 'Aktion ist für diesen Zeitstatus nicht möglich.');
            $now = $capturedAt ?? CarbonImmutable::now('UTC');
            app(PayrollClosingService::class)->assertMutable($actor, $entry->starts_at, $entry->ends_at ?? $now);
            $latest = $entry->events()->max('occurred_at');
            $this->check($now->gte($entry->starts_at) && (! $latest || $now->gte(CarbonImmutable::parse($latest, 'UTC'))), 'Ereigniszeit liegt vor dem aktuellen Zeitstand.');
            if ($action === 'pause') {
                $entry->paused_at = $now;
                $entry->status = 'paused';
            } elseif (in_array($action, ['resume', 'stop'], true)) {
                if ($entry->paused_at) {
                    $entry->pause_seconds += max(0, (int) $entry->paused_at->diffInSeconds($now));
                    $entry->paused_at = null;
                }
                $entry->status = $action === 'stop' ? 'completed' : 'running';
                if ($action === 'stop') {
                    $entry->ends_at = $now;
                }
            } else {
                $this->check($entry->ends_at && $entry->netSeconds() > 0, 'Arbeitszeit muss größer als null sein.');
                $this->assertNoTimeOverlap($entry);
                $entry->status = 'submitted';
                $entry->submitted_at = $now;
            }
            $entry->revision++;
            $entry->save();
            if (WorkTimeSchema::ready() && in_array($action, ['pause', 'resume', 'stop'], true)) {
                app(WorkTimeActivityService::class)->clockTransition($entry, $action, $now);
                if ($action === 'stop') {
                    DB::table('work_time_active_sessions')->where('user_id', $entry->user_id)->where('work_time_entry_id', $entry->id)->delete();
                }
            }
            if ($action === 'submit') {
                $this->snapshot($entry, 'submitted', $actor);
            }
            $this->event($entry, $actor, $action, $eventKey, $now, $payloadHash === null ? [] : ['payload_hash' => $payloadHash]);

            return $entry;
        }, 3);
    }

    public function correct(int $id, int $revision, array $data, User $actor): void
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['starts_at' => 'required|string', 'ends_at' => 'required|string', 'pause_minutes' => 'required|integer|min:0|max:1440', 'note' => 'required|string|min:5|max:1000'])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $data, $actor) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            $this->check($entry->revision === $revision && in_array($entry->status, ['returned', 'completed'], true), 'Diese Zeitmeldung kann nicht geändert werden.');
            [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $entry->timezone);
            app(PayrollClosingService::class)->assertMutable($actor, $entry->starts_at, $entry->ends_at);
            app(PayrollClosingService::class)->assertMutable($actor, $start, $end);
            $this->check($end->lte(now()->utc()) && $start->diffInMinutes($end) <= 1440 && $data['pause_minutes'] < $start->diffInMinutes($end), 'Zeitraum oder Pause sind ungültig.');
            $this->snapshot($entry, 'before_correction', $actor);
            $entry->forceFill(['starts_at' => $start, 'ends_at' => $end, 'pause_seconds' => $data['pause_minutes'] * 60, 'note' => $data['note'], 'status' => 'completed', 'revision' => $revision + 1, 'submitted_at' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
            $this->assertNoTimeOverlap($entry);
            $entry->save();
            if (WorkTimeSchema::ready()) {
                // A corrected interval does not invent new section boundaries.
                $entry->activities()->update(['source' => 'needs_review']);
            }
            $this->snapshot($entry, 'corrected', $actor);
            app(OperationsAuditService::class)->record($entry, $actor, 'time.corrected', ['note' => $data['note']]);
        }, 3);
    }

    public function review(int $id, int $revision, bool $approve, string $note, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.time.review');
        Validator::make(['note' => $note], ['note' => ($approve ? 'nullable' : 'required').'|string|max:1000'])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $approve, $note, $actor) {
            $subject = User::lockForUpdate()->findOrFail(WorkTimeEntry::findOrFail($id)->user_id);
            $entry = WorkTimeEntry::lockForUpdate()->findOrFail($id);
            app(PersonnelScopeService::class)->authorize($actor, (int) $entry->user_id, 'operations.time.review');
            abort_if($entry->user_id === $actor->id, 403);
            app(PayrollClosingService::class)->assertMutable($subject, $entry->starts_at, $entry->ends_at);
            $this->check($entry->revision === $revision && $entry->status === 'submitted', 'Zeitmeldung wurde bereits bearbeitet. Bitte neu laden.');
            $warnings = $this->warnings($entry);
            $this->check(! $approve || ! $warnings || mb_strlen(trim($note)) >= 5, 'Abweichungen benötigen eine Begründung.');
            $entry->forceFill(['status' => $approve ? 'approved' : 'returned', 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'review_note' => $note, 'revision' => $revision + 1])->save();
            $this->snapshot($entry, $entry->status, $actor);
            app(OperationsAuditService::class)->record($entry, $actor, 'time.'.$entry->status, ['note' => $note, 'deviations' => $warnings]);
        }, 3);
    }

    public function warnings(WorkTimeEntry $entry): array
    {
        $rules = $entry->plan_snapshot['rules'] ?? [];
        $duration = $entry->ends_at ? $entry->starts_at->diffInMinutes($entry->ends_at) : 0;
        $warnings = [];
        if ($duration > ($rules['maximum_shift_minutes'] ?? PHP_INT_MAX)) {
            $warnings[] = 'Höchstdauer überschritten';
        }
        if ($duration > ($rules['break_after_minutes'] ?? PHP_INT_MAX) && $entry->pause_seconds < ($rules['minimum_break_minutes'] ?? 0) * 60) {
            $warnings[] = 'Pause unterschritten';
        }
        $plan = $entry->plan_snapshot;
        if ($entry->ends_at && isset($plan['starts_at'], $plan['ends_at']) && (! $entry->starts_at->equalTo(CarbonImmutable::parse($plan['starts_at'])) || ! $entry->ends_at->equalTo(CarbonImmutable::parse($plan['ends_at'])) || $entry->pause_seconds !== (int) ($plan['planned_break_minutes'] ?? 0) * 60)) {
            $warnings[] = 'Planabweichung';
        }
        if (($entry->work_context ?? 'shift') !== 'shift' && ! $rules) {
            $warnings[] = 'Regelgrundlage fehlt';
        }
        if (WorkTimeSchema::ready() && $entry->activities()->where('source', 'needs_review')->exists()) {
            $warnings[] = 'Tätigkeitsabschnitte prüfen';
        }
        if (WorkTimeSchema::ready() && $entry->activities()->where('kind', 'break')->whereNull('is_paid')->exists()) {
            $warnings[] = 'Pausenbewertung fehlt';
        }
        if (WorkTimeSchema::ready() && $entry->ends_at && $entry->creditedSeconds() === null) {
            $warnings[] = 'Zeitbewertung prüfen';
        }

        return $warnings;
    }

    public function comparison(WorkTimeEntry $entry): array
    {
        $plan = $entry->plan_snapshot;
        if (! isset($plan['starts_at'], $plan['ends_at'])) {
            return ['planned' => null, 'actual' => $entry->netSeconds(), 'delta' => null, 'warnings' => $this->warnings($entry)];
        }
        $seconds = max(0, (int) CarbonImmutable::parse($plan['starts_at'])->diffInSeconds(CarbonImmutable::parse($plan['ends_at'])) - (int) ($plan['planned_break_minutes'] ?? 0) * 60);

        return ['planned' => $seconds, 'actual' => $entry->netSeconds(), 'delta' => $entry->ends_at ? $entry->netSeconds() - $seconds : null, 'warnings' => $this->warnings($entry)];
    }

    public function reviewBatch(array $rows, bool $approve, string $note, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.time.review');
        Validator::make(['rows' => $rows, 'note' => $note], ['rows' => 'required|array|min:1|max:50', 'rows.*.id' => 'required|integer|distinct', 'rows.*.revision' => 'required|integer|min:1', 'note' => ($approve ? 'nullable' : 'required|min:5').'|string|max:1000'])->validate();
        OperationsTransaction::run(function () use ($rows, $approve, $note, $actor) {
            $expected = collect($rows)->keyBy('id');
            $subjects = WorkTimeEntry::whereIn('id', $expected->keys())->pluck('user_id')->unique()->sort()->values();
            User::whereIn('id', $subjects)->orderBy('id')->lockForUpdate()->get();
            $entries = WorkTimeEntry::whereIn('id', $expected->keys())->orderBy('id')->lockForUpdate()->get();
            $this->check($entries->count() === count($rows), 'Zeitmeldung nicht gefunden.');
            foreach ($entries as $entry) {
                abort_if($entry->user_id === $actor->id, 403);
                $this->check($entry->status === 'submitted' && $entry->revision === (int) $expected[$entry->id]['revision'], 'Auswahl wurde geändert. Bitte neu prüfen.');
                $this->check(! $approve || $this->warnings($entry) === [], 'Abweichende Zeitmeldungen bitte einzeln prüfen.');
                $this->review($entry->id, $entry->revision, $approve, $note, $actor);
            }
        }, 3);
    }

    public function export(array $ids, User $actor): WorkTimeExport
    {
        OperationsAccess::authorize($actor, 'operations.time.export');
        Validator::make(['ids' => $ids], ['ids' => 'required|array|min:1|max:500', 'ids.*' => 'required|integer|distinct'])->validate();

        return OperationsTransaction::run(function () use ($ids, $actor) {
            $entries = WorkTimeEntry::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->with('user')->get();
            $this->check($entries->count() === count($ids), 'Zeitmeldung nicht gefunden.');
            foreach ($entries as $entry) {
                $this->check(($entry->work_context ?? 'shift') === 'shift', 'Allgemeine Arbeit im separaten Arbeitszeit-Export ausgeben.');
                app(PersonnelScopeService::class)->authorize($actor, (int) $entry->user_id, 'operations.time.export');
                $this->check($entry->status === 'approved', 'Nur freigegebene Zeiten können exportiert werden.');
                $this->check(! WorkTimeExportItem::where('work_time_entry_id', $entry->id)->where('revision', $entry->revision)->exists(), 'Eine gewählte Zeitmeldung wurde bereits exportiert.');
            }
            $export = WorkTimeExport::create(['public_id' => (string) Str::uuid(), 'created_by' => $actor->id, 'schema_version' => 1, 'created_at' => now()->utc()]);
            foreach ($entries as $entry) {
                $export->items()->create(['work_time_entry_id' => $entry->id, 'revision' => $entry->revision, 'snapshot' => $this->values($entry) + ['employee' => $entry->user->name, 'employee_id' => $entry->user_id, 'payroll_reference' => app(PayrollReferenceService::class)->snapshot($entry->user_id)]]);
            }
            app(OperationsAuditService::class)->record($export, $actor, 'time.exported', ['entry_ids' => $ids, 'schema_version' => 1]);

            return $export;
        }, 3);
    }

    public function csv(WorkTimeExport $export, User $actor): string
    {
        OperationsAccess::authorize($actor, 'operations.time.export');
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Schema', 'Export', 'Zeit-ID', 'Revision', 'Mitarbeiter', 'Auftrag', 'Beginn', 'Ende', 'Zeitzone', 'Pause (Sek.)', 'Netto (Sek.)'], ';', '"', '');
        foreach ($export->items()->orderBy('id')->get() as $item) {
            app(PersonnelScopeService::class)->authorize($actor, (int) ($item->snapshot['employee_id'] ?? WorkTimeEntry::findOrFail($item->work_time_entry_id)->user_id), 'operations.time.export');
            $s = $item->snapshot;
            $row = [1, $export->public_id, $item->work_time_entry_id, $item->revision, $s['employee'], $s['plan_snapshot']['order_number'], $s['starts_at'], $s['ends_at'], $s['timezone'], $s['pause_seconds'], $s['net_seconds']];
            fputcsv($stream, array_map(fn ($v) => preg_match('/^[\s\x00-\x1f]*[=+@-]/u', (string) $v) ? "'".$v : $v, $row), ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function assertNoTimeOverlap(WorkTimeEntry $entry): void
    {
        $this->check(! WorkTimeEntry::where('user_id', $entry->user_id)->where('id', '!=', $entry->id)->where('starts_at', '<', $entry->ends_at->utc())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $entry->starts_at->utc()))->exists(), 'Die Arbeitszeit überschneidet sich mit einer anderen Zeitmeldung.');
    }

    private function values(WorkTimeEntry $entry): array
    {
        $values = $entry->only(['status', 'starts_at', 'ends_at', 'timezone', 'pause_seconds', 'note', 'plan_snapshot', 'reviewed_by', 'review_note']) + ['net_seconds' => $entry->netSeconds()];
        if (WorkTimeSchema::ready()) {
            $values += ['work_context' => $entry->work_context, 'credited_seconds' => $entry->creditedSeconds(), 'activities' => $entry->activities()->get()->toArray()];
        }

        return $values;
    }

    private function snapshot(WorkTimeEntry $entry, string $action, User $actor): void
    {
        $entry->revisions()->create(['revision' => $entry->revision, 'action' => $action, 'snapshot' => $this->values($entry), 'actor_id' => $actor->id, 'created_at' => now()->utc()]);
    }

    private function event(WorkTimeEntry $entry, User $actor, string $kind, string $key, ?CarbonImmutable $occurredAt = null, array $data = []): void
    {
        $entry->events()->create(['event_key' => $key, 'kind' => $kind, 'actor_id' => $actor->id, 'occurred_at' => $occurredAt ?? now()->utc(), 'received_at' => now()->utc(), 'data' => ['revision' => $entry->revision] + $data]);
        app(OperationsAuditService::class)->record($entry, $actor, 'time.'.$kind);
    }

    private function uuid(string $key): void
    {
        Validator::make(['eventKey' => $key], ['eventKey' => 'required|uuid'])->validate();
    }

    private function rules(User $actor, CarbonImmutable|CarbonInterface $at): ?OperationsRuleProfile
    {
        if (class_exists(WorkforceAccountService::class) && app(WorkforceAccountService::class)->ready()) {
            return app(WorkforceAccountService::class)->effectiveRules($actor, $at);
        }

        return OperationsRuleProfile::where('is_active', true)->first();
    }

    private function openSession(WorkTimeEntry $entry, CarbonImmutable $at): void
    {
        if (WorkTimeSchema::ready()) {
            $entry->forceFill(['capture_id' => $entry->capture_id ?: (string) Str::uuid()])->save();
            DB::table('work_time_active_sessions')->insert(['user_id' => $entry->user_id, 'work_time_entry_id' => $entry->id]);
            app(WorkTimeActivityService::class)->begin($entry, $at);
        }
    }

    private function snapshotValuation(WorkTimeEntry $entry, User $actor, CarbonImmutable $at): void
    {
        if (WorkTimeSchema::ready()) {
            $entry->forceFill(['plan_snapshot' => $entry->plan_snapshot + ['valuation' => app(WorkforceAccountService::class)->effectiveModel($actor, $at)?->valuation_rules ?? []]])->save();
        }
    }

    private function contextFingerprint(array $data): string
    {
        $data = array_intersect_key($data, array_flip(['work_context', 'title', 'timezone', 'order_id', 'training_session_id', 'starts_at', 'ends_at', 'pause_minutes', 'note']));
        foreach (['order_id', 'training_session_id', 'pause_minutes'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? (int) $data[$field] : null;
            }
        }
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function startContext(array $data, string $eventKey, User $actor, ?CarbonImmutable $capturedAt = null, bool $requireNewEvent = false): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        $this->uuid($eventKey);
        $data = Validator::make($data, ['work_context' => 'required|in:internal,training,unplanned', 'title' => 'required|string|min:3|max:180', 'timezone' => 'required|timezone', 'order_id' => 'nullable|integer|min:1', 'training_session_id' => 'nullable|integer|min:1'])->validate();

        return OperationsTransaction::run(function () use ($data, $eventKey, $actor, $capturedAt, $requireNewEvent) {
            User::lockForUpdate()->findOrFail($actor->id);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                $entry = WorkTimeEntry::findOrFail($event->work_time_entry_id);
                $this->check(! $requireNewEvent, 'Ereignisschlüssel wurde bereits außerhalb dieser Erfassung verwendet.');
                $this->check($entry->user_id === $actor->id && $entry->work_context === $data['work_context'] && $event->kind === 'start', 'Ereignisschlüssel wurde bereits verwendet.');
                abort_unless(($event->data['payload_hash'] ?? null) === $this->contextFingerprint($data), 409, 'Wiederholung enthält geänderte Daten.');

                return $entry;
            }
            $this->check(! WorkTimeEntry::where('user_id', $actor->id)->whereIn('status', ['running', 'paused'])->exists(), 'Es läuft bereits eine Zeiterfassung.');
            $at = $capturedAt ?? CarbonImmutable::now('UTC');
            app(PayrollClosingService::class)->assertMutable($actor, $at);
            $order = isset($data['order_id']) ? Order::findOrFail($data['order_id']) : null;
            if ($order) {
                $this->check(ShiftAssignment::where('user_id', $actor->id)->whereIn('status', ['requested', 'confirmed'])->whereHas('shift', fn ($q) => $q->where('order_id', $order->id)->where('published_revision', '>', 0))->exists(), 'Kein eigener Einsatzbezug zu dieser Leistung.');
            }
            if ($data['work_context'] === 'training') {
                $this->check(isset($data['training_session_id']), 'Schulung auswählen.');
                app(WorkforceAccountService::class)->assertTrainingClock($actor, (int) $data['training_session_id'], $at);
            } else {
                $this->check(empty($data['training_session_id']), 'Schulungsbezug ist nur bei Schulung zulässig.');
            }
            $entry = WorkTimeEntry::create(['user_id' => $actor->id, 'shift_assignment_id' => null, 'work_context' => $data['work_context'], 'timezone' => $data['timezone'], 'starts_at' => $at,
                'order_id' => $order?->id, 'training_session_id' => $data['training_session_id'] ?? null, 'plan_snapshot' => ['title' => $data['title'], 'order_number' => $order?->order_number, 'valuation' => app(WorkforceAccountService::class)->effectiveModel($actor, $at)?->valuation_rules ?? [], 'rules' => $this->rules($actor, $at)?->only(['id', 'maximum_shift_minutes', 'break_after_minutes', 'minimum_break_minutes'])]]);
            $this->openSession($entry, $at);
            $this->event($entry, $actor, 'start', $eventKey, $at, ['payload_hash' => $this->contextFingerprint($data)]);

            return $entry;
        }, 3);
    }

    public function manualContext(array $data, string $eventKey, User $actor): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        $this->uuid($eventKey);
        $data = Validator::make($data, ['work_context' => 'required|in:internal,training,unplanned', 'title' => 'required|string|min:3|max:180', 'order_id' => 'nullable|integer|min:1', 'training_session_id' => 'nullable|integer|min:1', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone', 'pause_minutes' => 'required|integer|min:0|max:1440', 'note' => 'required|string|min:5|max:1000'])->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);
        $this->check($end->lte(now()->utc()) && $start->diffInMinutes($end) <= 1440 && $data['pause_minutes'] < $start->diffInMinutes($end), 'Zeitraum oder Pause sind ungültig.');

        return OperationsTransaction::run(function () use ($data, $eventKey, $actor, $start, $end) {
            User::lockForUpdate()->findOrFail($actor->id);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                $existing = WorkTimeEntry::findOrFail($event->work_time_entry_id);
                $this->check($existing->user_id === $actor->id && $event->kind === 'manual', 'Ereignisschlüssel wurde bereits verwendet.');
                abort_unless(($event->data['payload_hash'] ?? null) === $this->contextFingerprint($data), 409, 'Wiederholung enthält geänderte Daten.');

                return $existing;
            }
            $entry = $this->startContext($data, (string) Str::uuid(), $actor, $start);
            $entry = $this->clock($entry->id, $entry->revision, 'stop', (string) Str::uuid(), $actor, $end);
            // Aggregate manual pause has no known position: do not fabricate activity boundaries.
            $entry->activities()->delete();
            $entry->forceFill(['pause_seconds' => (int) $data['pause_minutes'] * 60, 'note' => $data['note'], 'source' => 'manual'])->save();
            $this->assertNoTimeOverlap($entry);
            $this->snapshot($entry, 'manual', $actor);
            $this->event($entry, $actor, 'manual', $eventKey, $end, ['payload_hash' => $this->contextFingerprint($data)]);

            return $entry;
        }, 3);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
