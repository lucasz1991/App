<?php

namespace App\Services\Operations;

use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeActivity;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeEvent;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkTimeActivityService
{
    public const KINDS = ['work' => 'Arbeit', 'preparation' => 'Vorbereitung', 'driving' => 'Fahrt', 'shunting' => 'Rangieren', 'travel' => 'Anreise', 'waiting' => 'Wartezeit', 'on_call' => 'Bereitschaft', 'break' => 'Pause', 'internal' => 'Interne Arbeit', 'training' => 'Schulung'];

    public function begin(WorkTimeEntry $entry, CarbonImmutable $at): void
    {
        app(PayrollClosingService::class)->assertMutable(User::findOrFail($entry->user_id), $at);
        $entry->activities()->create(['kind' => $this->initialKind($entry), 'starts_at' => $at, 'is_paid' => null]);
    }

    private function initialKind(WorkTimeEntry $entry): string
    {
        return empty($entry->plan_snapshot['day_clock']) && in_array($entry->work_context, ['internal', 'training'], true)
            ? $entry->work_context : 'work';
    }

    public function pickerState(WorkTimeEntry $entry, ?int $dutyAssignmentId = null): array
    {
        $state = ['kind' => null, 'activity_since' => null, 'previous_kind' => null, 'can_correct_activity' => false, 'recent_kinds' => [], 'suggested_kinds' => []];
        if (! in_array($entry->status, ['running', 'paused'], true) || ! WorkTimeSchema::ready()) {
            return $state;
        }
        $current = $entry->activities()->whereNull('ends_at')->latest('id')->first();
        $state['kind'] = $current?->kind;
        $state['activity_since'] = $current?->starts_at?->toIso8601String();
        if ($current?->kind === 'break') {
            $state['previous_kind'] = $entry->activities()->where('kind', '!=', 'break')->latest('id')->value('kind');
        } elseif ($current) {
            $state['can_correct_activity'] = $this->canCorrect($entry, $current);
        }
        $state['recent_kinds'] = DB::table('work_time_activities as activity')
            ->join('work_time_entries as entry', 'entry.id', '=', 'activity.work_time_entry_id')
            ->where('entry.user_id', $entry->user_id)->whereIn('activity.kind', array_diff(array_keys(self::KINDS), ['break']))
            ->groupBy('activity.kind')->orderByRaw('MAX(activity.starts_at) DESC, MAX(activity.id) DESC')->pluck('activity.kind')->all();

        $assignmentId = $dutyAssignmentId ?? $entry->shift_assignment_id;
        if ($assignmentId) {
            $assignment = ShiftAssignment::where('user_id', $entry->user_id)->with('shift')->find($assignmentId);
            // Published section "travel" is driving on duty; the capture type "travel" means Anreise.
            $mapping = ['preparation' => 'preparation', 'travel' => 'driving', 'shunting' => 'shunting'];
            $sections = $assignment?->shift?->published_snapshot['sections'] ?? [];
            $state['suggested_kinds'] = collect($sections)->map(fn ($part) => $mapping[$part['kind'] ?? ''] ?? null)
                ->filter()->unique()->take(3)->values()->all();
        }

        return $state;
    }

    private function canCorrect(WorkTimeEntry $entry, WorkTimeActivity $current): bool
    {
        $last = $entry->events()->where('kind', 'activity')->whereNull('data->no_change')->latest('id')->first();
        if (! $last || $current->kind === 'break') {
            return false;
        }
        if (isset($last->data['activity_id'])) {
            return (int) $last->data['activity_id'] === (int) $current->id;
        }

        return ($last->data['kind'] ?? null) === $current->kind && $last->occurred_at->equalTo($current->starts_at);
    }

    public function clockTransition(WorkTimeEntry $entry, string $action, CarbonImmutable $at): void
    {
        app(PayrollClosingService::class)->assertMutable(User::findOrFail($entry->user_id), $entry->starts_at, $at);
        $active = $entry->activities()->whereNull('ends_at')->lockForUpdate()->first();
        if ($active) {
            $active->update(['ends_at' => $at]);
        }
        if ($action !== 'stop') {
            $kind = $action === 'pause' ? 'break' : ($entry->activities()->where('kind', '!=', 'break')->latest('id')->value('kind') ?? $this->initialKind($entry));
            $entry->activities()->create(['kind' => $kind, 'starts_at' => $at, 'is_paid' => $action === 'pause' ? $this->breakPaid($entry) : null]);
        }
    }

    public function change(int $id, int $revision, string $kind, string $eventKey, User $actor, ?CarbonImmutable $capturedAt = null, bool $requireNewEvent = false, bool $correctCurrent = false): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        Validator::make(compact('kind', 'eventKey'), ['kind' => 'required|in:'.implode(',', array_diff(array_keys(self::KINDS), ['break'])), 'eventKey' => 'required|uuid'])->validate();

        return OperationsTransaction::run(function () use ($id, $revision, $kind, $eventKey, $actor, $capturedAt, $requireNewEvent, $correctCurrent) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                abort_if($requireNewEvent, 409, 'Ereignisschlüssel wurde bereits außerhalb dieser Erfassung verwendet.');
                abort_unless($event->work_time_entry_id === $entry->id && $event->actor_id === $actor->id && $event->kind === 'activity'
                    && ($event->data['kind'] ?? null) === $kind && ($event->data['correction'] ?? false) === $correctCurrent, 409);

                return $entry;
            }
            abort_unless($entry->revision === $revision && $entry->status === 'running', 409, 'Zeitstand wurde geändert.');
            $at = $capturedAt ?? CarbonImmutable::now('UTC');
            app(PayrollClosingService::class)->assertMutable($actor, $entry->starts_at, $at);
            $latest = $entry->events()->max('occurred_at');
            abort_if($at->lt($entry->starts_at) || ($latest && $at->lt(CarbonImmutable::parse($latest, 'UTC'))), 409, 'Ereigniszeit liegt vor dem aktuellen Zeitstand.');
            $active = $entry->activities()->whereNull('ends_at')->latest('id')->lockForUpdate()->first();
            $details = ['kind' => $kind];
            if ($correctCurrent) {
                abort_unless($active && $this->canCorrect($entry, $active), 409, 'Der letzte Abschnitt hat sich geändert.');
                $details += ['correction' => true, 'previous_kind' => $active->kind];
                $active->update(['kind' => $kind]);
            } elseif ($active?->kind === $kind) {
                // Older clients can already have queued subsequent events at the next revision.
                $details['no_change'] = true;
            } else {
                $active?->update(['ends_at' => $at]);
                $active = $entry->activities()->create(['kind' => $kind, 'starts_at' => $at, 'is_paid' => null]);
            }
            $details['activity_id'] = $active->id;
            $entry->increment('revision');
            $entry->events()->create(['event_key' => $eventKey, 'kind' => 'activity', 'actor_id' => $actor->id, 'occurred_at' => $at, 'received_at' => now()->utc(), 'data' => ['revision' => $entry->revision] + $details]);
            app(OperationsAuditService::class)->record($entry, $actor, $correctCurrent ? 'time.activity_corrected' : 'time.activity', $details);

            return $entry;
        }, 3);
    }

    public function replace(int $id, int $revision, array $sections, User $actor): void
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        Validator::make(['sections' => $sections], ['sections' => 'required|array|min:1|max:60', 'sections.*.kind' => 'required|in:'.implode(',', array_keys(self::KINDS)), 'sections.*.starts_at' => 'required|date', 'sections.*.ends_at' => 'required|date', 'sections.*.note' => 'nullable|string|max:500'])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $sections, $actor) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            app(PayrollClosingService::class)->assertMutable($actor, $entry->starts_at, $entry->ends_at);
            abort_unless($entry->revision === $revision && in_array($entry->status, ['completed', 'returned'], true), 409);
            $cursor = $entry->starts_at->utc();
            $pause = 0;
            $values = [];
            foreach ($sections as $section) {
                [$start, $end] = OperationsDateTime::interval($section['starts_at'], $section['ends_at'], $entry->timezone);
                if (! $start->equalTo($cursor) || ! $end->gt($start) || $end->gt($entry->ends_at)) {
                    throw ValidationException::withMessages(['sections' => 'Abschnitte müssen die tatsächliche Arbeitszeit lückenlos und ohne Überschneidung abdecken.']);
                }
                $cursor = $end;
                if ($section['kind'] === 'break') {
                    $pause += (int) $start->diffInSeconds($end);
                }
                $values[] = ['kind' => $section['kind'], 'starts_at' => $start, 'ends_at' => $end, 'is_paid' => $section['kind'] === 'break' ? $this->breakPaid($entry) : null, 'source' => 'reported', 'note' => $section['note'] ?? null];
            }
            if (! $cursor->equalTo($entry->ends_at) || $pause !== $entry->pause_seconds) {
                throw ValidationException::withMessages(['sections' => 'Ende und Pausensumme müssen mit der Zeitmeldung übereinstimmen.']);
            }
            $previous = $entry->activities()->get()->toArray();
            // Superseded sections remain in the immutable time-revision snapshot.
            $entry->revisions()->create(['revision' => $entry->revision, 'action' => 'before_sections', 'actor_id' => $actor->id, 'snapshot' => ['activities' => $previous, 'net_seconds' => $entry->netSeconds()], 'created_at' => now()->utc()]);
            $entry->activities()->delete();
            $entry->activities()->createMany($values);
            $entry->increment('revision');
            app(OperationsAuditService::class)->record($entry, $actor, 'time.sections_reported');
        }, 3);
    }

    private function breakPaid(WorkTimeEntry $entry): ?bool
    {
        if (array_key_exists('break', $entry->plan_snapshot['valuation'] ?? [])) {
            return (int) $entry->plan_snapshot['valuation']['break'] > 0;
        }

        return ($entry->work_context ?? 'shift') === 'shift' && ! array_key_exists('valuation', $entry->plan_snapshot ?? []) ? false : null;
    }
}
