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
    public function revise(int $id, int $revision, array $rows, string $note, User $actor): OperationWorkflow
    {
        Validator::make(compact('rows', 'note'), ['rows' => 'required|array|min:1|max:40', 'rows.*.activity' => 'required|string|max:180', 'rows.*.quantity' => 'required|numeric|min:0|max:1000000', 'rows.*.unit' => 'required|string|max:40', 'note' => 'required|string|min:5|max:2000'])->validate();

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($rows, $note, $actor) {
            abort_unless($r->kind === 'proof' && $r->user_id === $actor->id && in_array($r->status, ['draft', 'rejected']), 409);
            $p = $r->payload;
            $p['rows'] = $rows;
            $p['note'] = $note;
            unset($p['customer_decision'],$p['review'],$p['billing_cents']);
            $r->payload = $p;
            $r->status = 'draft';

            return 'revised';
        });
    }

    public function create(array $data, User $actor): OperationWorkflow
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['order_id' => 'required|integer|exists:orders,id', 'shift_id' => 'nullable|integer|exists:shifts,id', 'title' => 'required|string|max:180', 'rows' => 'required|array|min:1|max:40', 'rows.*.activity' => 'required|string|max:180', 'rows.*.quantity' => 'required|numeric|min:0|max:1000000', 'rows.*.unit' => 'required|string|max:40', 'note' => 'nullable|string|max:2000'])->validate();
        $assignment = ShiftAssignment::where('user_id', $actor->id)->whereIn('status', ['confirmed', 'requested'])->whereHas('shift', fn ($q) => $q->where('order_id', $data['order_id'])->where('status', '!=', 'cancelled')->where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')->when($data['shift_id'] ?? null, fn ($q, $id) => $q->where('id', $id)))->first();
        abort_unless($assignment, 403);

        return OperationsTransaction::run(function () use ($assignment, $data, $actor) {
            Order::lockForUpdate()->findOrFail($data['order_id']);
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $current = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($current->user_id === $actor->id && in_array($current->status->value, ['requested', 'confirmed']) && $shift->status->value !== 'cancelled' && $shift->order_id === (int) $data['order_id'] && $shift->revision === $shift->published_revision && $current->plan_revision === $shift->published_revision, 403);

            return app(OperationsWorkflowService::class)->create('proof', $data['title'], ['rows' => $data['rows'], 'note' => $data['note'] ?? '', 'attachments' => [], 'shift_revision' => $shift->published_revision], $actor, $actor->id, $data['order_id'], $shift->id);
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
            return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($file, $path) {
                abort_unless($r->kind === 'proof' && $r->status === 'draft' && count($r->payload['attachments']) < 20, 409);
                $payload = $r->payload;
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
        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) {
            abort_unless($r->kind === 'proof' && $r->status === 'draft', 409);
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
