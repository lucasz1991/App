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
        if ($sectionId) {
            $section = $shift->sectionActivityRules()->get($sectionId);
            abort_unless($section, 422, 'Der Leistungsabschnitt gehört nicht zu diesem Dienst.');

            return $section;
        }
        $rule = $shift->activity_requirements;
        if ($rule === null && $shift->published_revision && $shift->exists) {
            $prior = OperationWorkflow::where('kind', 'proof')->where('shift_id', $shift->id)->oldest('id')->get()
                ->first(fn ($r) => isset($r->payload['duty']) && empty($r->payload['duty']['section_id'])
                    && ($r->payload['shift_revision'] ?? null) === $shift->published_revision);
            $rule = $prior?->payload['duty'] ?? null;
        }
        $rule ??= $shift->currentActivityRequirements();

        return $rule + ['title' => $shift->role_name ?: $shift->title, 'proof_type' => 'Einsatzbericht', 'wagon_list' => false, 'individual_proof' => false];
    }

    public function dutyRecord(Shift $shift, User $actor, ?int $sectionId = null): ?OperationWorkflow
    {
        $individual = $this->dutyRule($shift, $sectionId)['individual_proof'];

        return OperationWorkflow::where('kind', 'proof')->where('shift_id', $shift->id)
            ->when($individual, fn ($q) => $q->where('user_id', $actor->id))->orderBy('id')->get()
            ->first(fn ($proof) => ($proof->payload['shift_revision'] ?? null) === $shift->published_revision && ($proof->payload['duty']['section_id'] ?? null) === $sectionId);
    }

    /** One row per expected service or section, with the number of reviewed results. */
    public function coverage(Shift $shift): array
    {
        $staff = $shift->assignments()->whereIn('status', ['requested', 'confirmed'])->where('plan_revision', $shift->published_revision)->pluck('user_id');
        $records = OperationWorkflow::where('kind', 'proof')->where('shift_id', $shift->id)->get()
            ->filter(fn ($r) => ($r->payload['shift_revision'] ?? null) === $shift->published_revision);
        $rules = $shift->sectionActivityRules()->prepend($this->dutyRule($shift));
        return $rules->map(function ($rule) use ($staff, $records) {
            $required = $rule['individual_proof'] ? max(1, $staff->count()) : 1;
            $completed = $records->filter(fn ($r) => ($r->payload['duty']['section_id'] ?? null) === ($rule['section_id'] ?? null)
                && in_array($r->status, ($rule['customer_acceptance'] ?? false) ? ['accepted'] : ['reviewed','accepted'], true)
                && (! $rule['individual_proof'] || $staff->contains($r->user_id)))->unique('user_id')->count();
            return ['title' => $rule['section_label'] ?? $rule['title'], 'document' => $rule['proof_type'],
                'required' => $required, 'complete' => min($required, $completed)];
        })->values()->all();
    }

    private function ownDutyRecord(OperationWorkflow $record, User $actor): Shift
    {
        abort_unless($record->kind === 'proof' && (int) $record->user_id === $actor->id, 403);
        $shift = $this->ownShift(Shift::findOrFail($record->shift_id)->public_id, $actor);
        abort_unless(($record->payload['shift_revision'] ?? null) === $shift->published_revision && ($shift->status->value !== 'completed' || $shift->activity_requirements === null), 409);

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
