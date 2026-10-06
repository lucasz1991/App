<?php

namespace App\Services\Operations;

use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OperationsTravelService
{
    public function request(array $data, User $actor): OperationWorkflow
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['shift_id' => 'required|integer|exists:shifts,id', 'title' => 'required|string|max:180', 'travel_kind' => 'required|in:train,hotel,car,other', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'destination' => 'required|string|max:180', 'note' => 'nullable|string|max:2000'])->validate();
        $assignment = ShiftAssignment::where('user_id', $actor->id)->where('shift_id', $data['shift_id'])->whereIn('status', ['requested', 'confirmed'])->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->where('status', '!=', 'cancelled')->whereColumn('revision', 'published_revision')->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision'))->firstOrFail();

        return OperationsTransaction::run(function () use ($assignment, $data, $actor) {
            Order::lockForUpdate()->findOrFail($assignment->shift->order_id);
            $shift = Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            User::lockForUpdate()->findOrFail($actor->id);
            $current = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($current->user_id === $actor->id && in_array($current->status->value, ['requested', 'confirmed']) && $shift->status->value !== 'cancelled' && $current->plan_revision === $shift->published_revision && $shift->revision === $shift->published_revision, 403);

            return app(OperationsWorkflowService::class)->create('travel', $data['title'], collect($data)->except(['title', 'shift_id'])->all() + ['receipts' => [], 'shift_revision' => $shift->published_revision], $actor, $actor->id, $shift->order_id, $data['shift_id']);
        }, 3);
    }

    public function decide(int $id, int $revision, string $action, array $data, User $actor): OperationWorkflow
    {
        Validator::make(compact('action'), ['action' => 'required|in:approve,return,book,cancel,cancel_requested,expense,expense_approve,expense_return'])->validate();
        $data = Validator::make($data, ['note' => 'required|string|min:5|max:2000', 'booking_reference' => 'nullable|required_if:action,book|string|max:180', 'cancel_until' => 'nullable|date', 'actual_cents' => 'nullable|integer|min:0|max:10000000'])->validate();
        $manage = ! in_array($action, ['cancel_requested', 'expense'], true);

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($action, $data, $actor) {
            abort_unless($r->kind === 'travel', 404);
            $allowed = ['approve' => ['draft'], 'return' => ['draft'], 'book' => ['approved'], 'cancel_requested' => ['approved', 'booked'], 'cancel' => ['approved', 'booked', 'cancel_requested'], 'expense' => ['booked'], 'expense_approve' => ['expense_submitted'], 'expense_return' => ['expense_submitted']];
            abort_unless(in_array($r->status, $allowed[$action], true), 409);
            if (in_array($action, ['approve', 'expense_approve', 'expense_return'], true)) {
                abort_if($r->user_id === $actor->id, 403);
            }
            if ($action === 'book') {
                abort_unless(filled($data['booking_reference'] ?? null), 422);
            }
            if ($action === 'expense') {
                abort_unless(isset($data['actual_cents']) && $r->user_id === $actor->id && ! empty($r->payload['receipts']), 422, 'Betrag und Beleg erforderlich.');
            }
            $payload = $r->payload;
            $payload['decisions'][] = ['action' => $action, 'actor_id' => $actor->id, 'at' => now()->utc()->toIso8601String()] + $data;
            if (isset($data['cancel_until'])) {
                $payload['cancel_until'] = CarbonImmutable::parse($data['cancel_until'])->utc()->toIso8601String();
            }
            if ($action === 'book') {
                $payload['booking_reference'] = $data['booking_reference'];
            }
            if ($action === 'expense') {
                $payload['actual_cents'] = $data['actual_cents'];
            }
            $r->payload = $payload;
            $r->status = ['approve' => 'approved', 'return' => 'returned', 'book' => 'booked', 'cancel' => 'cancelled', 'cancel_requested' => 'cancel_requested', 'expense' => 'expense_submitted', 'expense_approve' => 'expense_approved', 'expense_return' => 'booked'][$action];

            return $action;
        }, $manage);
    }

    public function receipt(int $id, int $revision, UploadedFile $file, User $actor): OperationWorkflow
    {
        $r = OperationWorkflow::findOrFail($id);
        app(OperationsWorkflowService::class)->authorize($r, $actor);
        abort_unless($r->kind === 'travel' && $r->user_id === $actor->id && $r->status === 'booked', 409);
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
        $path = $file->store('operations/travel-receipts', 'local');
        try {
            return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($path, $file) {
                abort_unless($r->status === 'booked' && count($r->payload['receipts'] ?? []) < 20, 409);
                $payload = $r->payload;
                $payload['receipts'][] = ['id' => (string) Str::uuid(), 'path' => $path, 'name' => basename($file->getClientOriginalName()), 'sha256' => hash_file('sha256', $file->getRealPath())];
                $r->payload = $payload;

                return 'receipt';
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function download(int $id, string $receiptId, User $actor)
    {
        $record = OperationWorkflow::findOrFail($id);
        app(OperationsWorkflowService::class)->authorize($record, $actor);
        abort_unless($record->kind === 'travel', 404);
        $file = collect($record->payload['receipts'] ?? [])->firstWhere('id', $receiptId);
        abort_unless($file && str_starts_with($file['path'], 'operations/travel-receipts/'), 404);
        abort_unless(hash_equals($file['sha256'], hash('sha256', Storage::disk('local')->get($file['path']))), 409);

        return Storage::disk('local')->download($file['path'], $file['name'], ['Cache-Control' => 'private, no-store']);
    }
}
