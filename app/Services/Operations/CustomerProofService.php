<?php

namespace App\Services\Operations;

use App\Models\CustomerContact;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerProofService
{
    public function ownShift(string $publicId, User $actor): Shift
    {
        OperationsAccess::own($actor, $actor->id);

        return Shift::where('public_id', $publicId)->notCancelled()->where('published_revision', '>', 0)
            ->whereColumn('revision', 'published_revision')
            ->whereHas('assignments', fn ($q) => $q->where('user_id', $actor->id)->whereIn('status', ['confirmed', 'requested'])
                ->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision'))->firstOrFail();
    }

    public function dutyRule(Shift $shift, ?int $sectionId = null): array
    {
        $publishedRule = [];
        if ($shift->published_revision && $shift->revision === $shift->published_revision && isset($shift->published_snapshot['duty_proof_rules'])) {
            $publishedRule = collect($shift->published_snapshot['duty_proof_rules'])->first(fn ($row) => ($row['section_id'] ?? null) === $sectionId);
            abort_unless($publishedRule, 422, 'Der Leistungsabschnitt gehört nicht zur veröffentlichten Schicht.');
        }
        if ($sectionId) {
            $section = $shift->sectionActivityRules()->get($sectionId);
            abort_unless($section, 422, 'Der Leistungsabschnitt gehört nicht zu diesem Dienst.');

            return $publishedRule + $section;
        }
        $rule = $shift->activity_requirements;
        if ($rule === null && $shift->published_revision && $shift->exists) {
            $prior = OperationWorkflow::where('kind', 'proof')->where('order_id', $shift->order_id)->where('shift_id', $shift->id)->oldest('id')->get()
                ->first(fn ($r) => isset($r->payload['duty']) && empty($r->payload['duty']['section_id'])
                    && ($r->payload['shift_revision'] ?? null) === $shift->published_revision);
            $rule = $prior?->payload['duty'] ?? null;
        }
        $rule ??= $shift->currentActivityRequirements();

        return $publishedRule + $rule + ['title' => $shift->role_name ?: $shift->title, 'proof_type' => 'Einsatzbericht', 'wagon_list' => false, 'individual_proof' => false];
    }

    public function dutyRecord(Shift $shift, User $actor, ?int $sectionId = null): ?OperationWorkflow
    {
        $individual = $this->dutyRule($shift, $sectionId)['individual_proof'];

        return OperationWorkflow::where('kind', 'proof')->where('order_id', $shift->order_id)->where('shift_id', $shift->id)
            ->when($individual, fn ($q) => $q->where('user_id', $actor->id))->orderBy('id')->get()
            ->first(fn ($proof) => ($proof->payload['shift_revision'] ?? null) === $shift->published_revision && ($proof->payload['duty']['section_id'] ?? null) === $sectionId);
    }

    /** Freeze only the service-document fields, never employee records or attachments. */
    public function plannedProofRules(Shift $shift): array
    {
        $rule = $shift->planningActivityRequirements() ?? $shift->currentActivityRequirements();
        $rule += ['title' => $shift->role_name ?: $shift->title, 'proof_type' => 'Einsatzbericht', 'individual_proof' => false, 'wagon_list' => false];

        return $shift->sectionActivityRules()->prepend($rule)->map(fn ($row) => Arr::only($row, [
            'activity_id', 'code', 'title', 'section_id', 'section_label', 'proof_type', 'individual_proof', 'wagon_list', 'customer_acceptance',
        ]))->values()->all();
    }

    /** Stable contract used by the closing check. */
    public function coverage(Shift $shift): array
    {
        return array_map(fn ($line) => Arr::only($line, ['title', 'document', 'required', 'complete']), $this->proofOverview($shift)['lines']);
    }

    /** Null is the existing unrestricted personnel scope. An empty list grants no targets. */
    public function visibleProofOwners(?User $actor): ?array
    {
        if (! $actor?->status || ! $actor->can('operations.manage') || ! $actor->can('employees.master-data.view')) {
            return [];
        }

        return app(PersonnelScopeService::class)->visibleUserIds($actor, 'employees.master-data.view');
    }

    /** Read-only projection for the order, shift drawer and calendar. */
    public function proofOverview(Shift $shift, ?array $visibleUserIds = null): array
    {
        if ($visibleUserIds === []) {
            return ['notice' => 'Prüfstand außerhalb der eigenen Personalzuständigkeit.', 'lines' => []];
        }
        $revision = (int) $shift->published_revision;
        $futureDraft = $revision > 0 && $revision !== $shift->revision;
        $historical = in_array($shift->status->value, ['completed', 'cancelled'], true);
        $staff = $shift->assignments()->whereIn('status', $historical ? ['requested', 'confirmed', 'reported'] : ['requested', 'confirmed'])
            ->where('plan_revision', $revision)->pluck('user_id')->unique();
        $records = $revision ? OperationWorkflow::where('kind', 'proof')->where('order_id', $shift->order_id)
            ->where('shift_id', $shift->id)->orderBy('id')->get()
            ->filter(fn ($proof) => ($proof->payload['shift_revision'] ?? null) === $revision) : collect();
        if ($visibleUserIds !== null && $staff->merge($records->pluck('user_id'))->diff($visibleUserIds)->isNotEmpty()) {
            return ['notice' => 'Prüfstand außerhalb der eigenen Personalzuständigkeit.', 'lines' => []];
        }

        $snapshot = $shift->published_snapshot ?? [];
        if ($futureDraft && isset($snapshot['order_id']) && (int) $snapshot['order_id'] !== (int) $shift->order_id) {
            return ['notice' => 'Die Zuordnung zu diesem Auftrag ist noch nicht veröffentlicht.', 'lines' => []];
        }
        $notice = $revision ? 'Veröffentlichte Fassung '.$revision : 'Ohne veröffentlichte Fassung';
        $rules = $revision ? ($snapshot['duty_proof_rules'] ?? null) : null;
        if ($rules === null && $futureDraft) {
            // Older releases stored no document rules. Only records from that release can identify them.
            $rules = $records->map(fn ($record) => $record->payload['duty'] ?? null)->filter()
                ->unique(fn ($rule) => $rule['section_id'] ?? 0)->values()->all();
            $notice .= ' · Zu klären: Die vollständigen Nachweisanforderungen sind für diese Fassung nicht dokumentiert.';
        } elseif ($rules === null) {
            $rules = $shift->sectionActivityRules()->prepend($this->dutyRule($shift))->values()->all();
            if (($historical || $revision) && ! $shift->activity_requirements && ! $records->contains(fn ($proof) => isset($proof->payload['duty']))) {
                $notice .= ' · Zu klären: Für den Altbestand ist nur die heutige Katalogvorgabe verfügbar.';
            }
        }
        $recordsBySection = $records->groupBy(fn ($proof) => $proof->payload['duty']['section_id'] ?? 0);
        $rows = [];
        foreach ($rules as $rule) {
            $sectionRecords = $recordsBySection->get($rule['section_id'] ?? 0, collect());
            $rows[] = $this->proofLine($rule, $staff, $sectionRecords, $shift, $futureDraft);
        }

        $documented = $revision > 0 && ! $futureDraft && (! empty($snapshot['duty_proof_rules']) || $shift->activity_requirements !== null
            || $records->contains(fn ($proof) => isset($proof->payload['duty']) && empty($proof->payload['duty']['section_id'])));

        return ['notice' => $notice, 'lines' => $rows, 'documented' => $documented];
    }

    private function proofLine(array $rule, Collection $staff, Collection $records, Shift $shift, bool $futureDraft): array
    {
        $individual = $rule['individual_proof'] ?? false;
        $slots = $individual && $staff->isNotEmpty() ? $staff : collect([null]);
        $terminal = in_array($shift->status->value, ['completed', 'cancelled'], true);
        $states = [];
        $complete = 0;
        $approved = ($rule['customer_acceptance'] ?? false) ? ['accepted'] : ['reviewed', 'accepted'];
        foreach ($slots as $userId) {
            $matching = $individual ? $records->where('user_id', $userId) : $records;
            $record = $matching->first(fn ($p) => in_array($p->status, $approved, true)) ?? $matching->first();
            if ($record && in_array($record->status, $approved, true)) {
                $complete++;
            }
            $state = match ($record?->status) {
                'accepted' => 'Vom Kunden abgenommen',
                'reviewed' => ($rule['customer_acceptance'] ?? false) ? 'Kundenabnahme offen · Disposition' : 'Geprüft',
                'submitted' => 'In Prüfung · Prüfende Person',
                'rejected' => 'Vom Kunden zurückgewiesen · Bearbeitende Person',
                'draft' => isset($record->payload['review']['note']) ? 'Zur Korrektur · Bearbeitende Person' : 'Entwurf · Bearbeitende Person',
                null => match (true) {
                    $shift->status->value === 'cancelled' => 'Storniert · Keine Erfassung',
                    $terminal => $staff->isEmpty() ? 'Zuordnung zu klären · Disposition' : 'Beleg zum abgeschlossenen Dienst klären · Disposition',
                    ! $shift->published_revision => 'Vorgesehen · Disposition',
                    $staff->isEmpty() => 'Zuordnung zu klären · Disposition',
                    $futureDraft => 'Planänderung offen · Disposition',
                    default => 'Erwartet · Eingeteilte Person',
                },
                default => 'Prüfstand zu klären · Disposition',
            };
            if ($futureDraft && $record && in_array($record->status, ['draft', 'rejected'], true)) {
                $state = ($record->status === 'draft' ? 'Entwurf' : 'Vom Kunden zurückgewiesen').' · Planänderung bei der Disposition';
            }
            $states[$state] = ($states[$state] ?? 0) + 1;
        }

        $unassigned = $individual ? $records->whereNotIn('user_id', $staff->all())->count() : 0;

        return ['title' => $rule['section_label'] ?? $rule['title'], 'document' => $rule['proof_type'], 'required' => $slots->count(),
            'complete' => $complete, 'states' => $states, 'individual' => $individual, 'unassigned' => $unassigned];
    }

    public function currentDutyRevision(OperationWorkflow $proof, bool $lock = false): bool
    {
        if (! isset($proof->payload['duty']) && (! $proof->shift_id || ! isset($proof->payload['shift_revision']))) {
            return true;
        }
        $revision = $proof->payload['shift_revision'] ?? 0;

        return $revision > 0 && Shift::whereKey($proof->shift_id)->where('order_id', $proof->order_id)->notCancelled()
            ->where('published_revision', $revision)->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null;
    }

    private function ownDutyRecord(OperationWorkflow $record, User $actor): Shift
    {
        abort_unless($record->kind === 'proof' && (int) $record->user_id === $actor->id, 403);
        $shift = $this->ownShift(Shift::findOrFail($record->shift_id)->public_id, $actor);
        abort_unless((int) $record->order_id === (int) $shift->order_id && ($record->payload['shift_revision'] ?? null) === $shift->published_revision && ($shift->status->value !== 'completed' || $shift->activity_requirements === null), 409);

        return $shift;
    }

    public function authorizeWagon(OperationWorkflow $record, User $actor): Shift
    {
        $shift = $this->ownDutyRecord($record, $actor);
        abort_unless($record->status === 'draft' && ($record->payload['duty']['wagon_list'] ?? false), 409);

        return $shift;
    }

    public function revise(int $id, int $revision, array $rows, string $note, User $actor): OperationWorkflow
    {
        Validator::make(compact('rows', 'note'), ['rows' => 'required|array|min:1|max:40', 'rows.*.activity' => 'required|string|max:180', 'rows.*.quantity' => 'required|numeric|min:0|max:1000000', 'rows.*.unit' => 'required|string|max:40', 'note' => 'required|string|min:5|max:2000'])->validate();

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($rows, $note, $actor) {
            abort_unless($r->kind === 'proof' && $r->user_id === $actor->id && in_array($r->status, ['draft', 'rejected']), 409);
            if (isset($r->payload['duty'])) {
                $this->ownDutyRecord($r, $actor);
            }
            $p = $r->payload;
            $p['rows'] = $rows;
            $p['note'] = $note;
            unset($p['customer_decision'],$p['review'],$p['billing_cents']);
            $r->payload = $p;
            $r->status = 'draft';

            return 'revised';
        });
    }

    public function create(array $data, User $actor, bool $resume = false): OperationWorkflow
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['order_id' => 'required|integer|exists:orders,id', 'section_id' => 'nullable|integer|min:1', 'shift_id' => 'nullable|integer|exists:shifts,id', 'title' => 'required|string|max:180', 'rows' => 'required|array|min:1|max:40', 'rows.*.activity' => 'required|string|max:180', 'rows.*.quantity' => 'required|numeric|min:0|max:1000000', 'rows.*.unit' => 'required|string|max:40', 'note' => 'nullable|string|max:2000'])->validate();
        $assignments = ShiftAssignment::where('user_id', $actor->id)->whereIn('status', ['confirmed', 'requested'])->whereHas('shift', fn ($q) => $q->where('order_id', $data['order_id'])->where('status', '!=', 'cancelled')->where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')->when($data['shift_id'] ?? null, fn ($q, $id) => $q->where('id', $id)))->limit(2)->get();
        if (empty($data['shift_id']) && $assignments->count() > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['form.shift_id' => 'Bitte den konkreten Dienst zum Auftrag auswählen.']);
        }
        $assignment = $assignments->first();
        abort_unless($assignment, 403);

        return OperationsTransaction::run(function () use ($assignment, $data, $actor, $resume) {
            Order::lockForUpdate()->findOrFail($data['order_id']);
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $current = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($current->user_id === $actor->id && in_array($current->status->value, ['requested', 'confirmed']) && $shift->status->value !== 'cancelled' && $shift->order_id === (int) $data['order_id'] && $shift->revision === $shift->published_revision && $current->plan_revision === $shift->published_revision, 403);

            $payload = ['rows' => $data['rows'], 'note' => $data['note'] ?? '', 'attachments' => [], 'shift_revision' => $shift->published_revision];
            if (\Illuminate\Support\Facades\Schema::hasTable('duty_activities')) {
                $sectionId = isset($data['section_id']) ? (int) $data['section_id'] : null;
                $existing = $this->dutyRecord($shift, $actor, $sectionId);
                if ($existing) {
                    app(OperationsWorkflowService::class)->check((int) $existing->user_id === $actor->id, 'Ein Teammitglied bearbeitet den gemeinsamen Beleg.');

                    app(OperationsWorkflowService::class)->check($resume, 'Es gibt bereits einen Beleg. Bitte den vorhandenen Nachweis öffnen.');

                    return $existing;
                }
                abort_if($shift->status->value === 'completed' && $shift->activity_requirements !== null, 409, 'Der Dienst ist bereits abgeschlossen.');
                $payload['duty'] = $this->dutyRule($shift, $sectionId);
                $payload['participants'] = $payload['duty']['individual_proof'] ? [$actor->id] : $shift->assignments()
                    ->where('plan_revision', $shift->published_revision)->whereIn('status', ['requested', 'confirmed'])->pluck('user_id')->all();
            }

            return app(OperationsWorkflowService::class)->create('proof', $data['title'], $payload, $actor, $actor->id, $data['order_id'], $shift->id);
        }, 3);
    }

    public function attachment(int $id, int $revision, UploadedFile $file, User $actor): OperationWorkflow
    {
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
        $record = OperationWorkflow::findOrFail($id);
        app(OperationsWorkflowService::class)->authorize($record, $actor);
        abort_unless($record->kind === 'proof' && $record->status === 'draft', 409);
        $path = $file->store('operations/customer-proofs', 'local');
        try {
            return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($file, $path, $actor) {
                abort_unless($r->kind === 'proof' && $r->status === 'draft' && count($r->payload['attachments']) < 20, 409);
                $payload = $r->payload;
                if (isset($payload['duty'])) {
                    $this->ownDutyRecord($r, $actor);
                }
                $payload['attachments'][] = ['id' => (string) Str::uuid(), 'path' => $path, 'name' => basename($file->getClientOriginalName()), 'sha256' => hash_file('sha256', $file->getRealPath())];
                $r->payload = $payload;

                return 'attachment';
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function submit(int $id, int $revision, User $actor): OperationWorkflow
    {
        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($actor) {
            abort_unless($r->kind === 'proof' && $r->status === 'draft' && (int) $r->user_id === $actor->id, 409);
            if (isset($r->payload['duty'])) {
                $this->ownDutyRecord($r, $actor);
                app(OperationsWorkflowService::class)->check(! empty($r->payload['attachments']) || ! empty($r->payload['deviation']), 'Bitte den Dienstbeleg ergänzen oder die Abweichung begründen.');
            }
            $r->status = 'submitted';

            return 'submitted';
        });
    }

    public function decide(int $id, int $revision, string $action, string $note, ?int $contactId, User $actor): OperationWorkflow
    {
        Validator::make(compact('action', 'note', 'contactId'), ['action' => 'required|in:review,return,accept,reject', 'note' => 'required|string|min:5|max:2000', 'contactId' => 'nullable|integer'])->validate();

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($action, $note, $contactId, $actor, $revision) {
            abort_unless($r->kind === 'proof', 404);
            abort_unless($this->currentDutyRevision($r, lock: true), 409, 'Dieser Nachweis gehört nicht mehr zur veröffentlichten Schichtfassung.');
            $payload = $r->payload;
            if (in_array($action, ['accept', 'reject'], true)) {
                abort_unless($r->status === 'reviewed' && $contactId, 409);
                $contact = CustomerContact::where('customer_id', Order::findOrFail($r->order_id)->customer_id)->where('is_active', true)->findOrFail($contactId);
                abort_unless(in_array('acceptance', $contact->roles ?? [], true), 422);
                $payload['customer_decision'] = ['contact_id' => $contact->id, 'name' => $contact->name, 'note' => $note, 'decision' => $action, 'source' => 'documented_by_management', 'proof_revision' => $revision, 'recorded_by' => $actor->id, 'at' => now()->utc()->toIso8601String()];
                $r->status = $action === 'accept' ? 'accepted' : 'rejected';
            } else {
                abort_unless($r->status === 'submitted' && $r->created_by !== $actor->id, 409);
                $payload['review'] = ['note' => $note, 'actor_id' => $actor->id, 'at' => now()->utc()->toIso8601String()];
                $r->status = $action === 'review' ? 'reviewed' : 'draft';
            }
            $r->payload = $payload;

            return $action;
        }, true);
    }

    public function deviation(int $id, int $revision, string $reason, User $actor): OperationWorkflow
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($record) use ($actor, $reason) {
            abort_unless($record->kind === 'proof' && $record->status === 'draft' && $record->user_id == $actor->id && isset($record->payload['duty']), 403);
            $payload = $record->payload;
            $this->ownDutyRecord($record, $actor);
            $payload['deviation'] = $reason;
            $record->payload = $payload;

            return 'deviation.recorded';
        });
    }

    public function download(int $id, string $attachmentId, User $actor)
    {
        $record = OperationWorkflow::findOrFail($id);
        app(OperationsWorkflowService::class)->authorize($record, $actor);
        abort_unless($record->kind === 'proof', 404);
        $file = collect($record->payload['attachments'] ?? [])->firstWhere('id', $attachmentId);
        abort_unless($file && str_starts_with($file['path'], 'operations/customer-proofs/'), 404);
        abort_unless(hash_equals($file['sha256'], hash('sha256', Storage::disk('local')->get($file['path']))), 409);

        return Storage::disk('local')->download($file['path'], $file['name'], ['Cache-Control' => 'private, no-store']);
    }
}
