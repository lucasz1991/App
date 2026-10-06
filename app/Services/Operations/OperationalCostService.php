<?php

namespace App\Services\Operations;

use App\Models\OperationsCostRate;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Validator;

class OperationalCostService
{
    public function configure(User $subject, array $data, User $actor): OperationsCostRate
    {
        OperationsEnhancementsSchema::requireReady();
        app(PersonnelScopeService::class)->authorize($actor, $subject, 'operations.costs.manage');
        $data = Validator::make($data, ['starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'hourly_cents' => 'required|integer|min:0|max:10000000', 'currency' => 'required|in:EUR'])->validate();

        return OperationsTransaction::run(function () use ($subject, $data, $actor) {
            User::lockForUpdate()->findOrFail($subject->id);
            abort_if(OperationsCostRate::where('user_id', $subject->id)->where('starts_on', '<=', $data['ends_on'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']))->exists(), 422, 'Kostenzeiträume überschneiden sich.');
            $rate = OperationsCostRate::create($data + ['user_id' => $subject->id, 'created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($rate, $actor, 'cost_rate.created', ['user_id' => $subject->id]);

            return $rate;
        }, 3);
    }

    public function order(Order $order, User $actor): array
    {
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.costs.manage');
        OperationsEnhancementsSchema::requireReady();
        $allEntries = WorkTimeEntry::where(fn ($q) => $q->where('order_id', $order->id)->orWhereHas('assignment.shift', fn ($s) => $s->where('order_id', $order->id)))->get();
        $entries = $allEntries->where('status', 'approved');
        $cost = 0;
        $missing = [];
        foreach ($entries as $entry) {
            $date = $entry->starts_at->setTimezone($entry->timezone)->toDateString();
            $lastDate = $entry->ends_at?->subSecond()->setTimezone($entry->timezone)->toDateString();
            $rate = OperationsCostRate::where('user_id', $entry->user_id)->where('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $lastDate ?? $date))->first();
            if (! $rate || ! $entry->ends_at) {
                $missing[] = $entry->id;

                continue;
            }
            $cost += (int) round($entry->netSeconds() * $rate->hourly_cents / 3600);
        }
        $accepted = OperationWorkflow::where('kind', 'proof')->where('order_id', $order->id)->where('status', 'accepted')->get();
        $billings = OperationWorkflow::where('kind', 'billing')->where('order_id', $order->id)->get();
        $revenue = 0;
        $revenueKnown = $accepted->isNotEmpty();
        foreach ($accepted as $proof) {
            $bill = $billings->first(fn ($b) => ($b->payload['proof_id'] ?? null) === $proof->id && ($b->payload['proof_revision'] ?? null) === $proof->revision);
            if (! $bill) {
                $revenueKnown = false;
            } else {
                $revenue += $bill->payload['billing_cents'];
            }
        }
        $cost += OperationWorkflow::where('kind', 'travel')->where('order_id', $order->id)->where('status', 'expense_approved')->get()->sum(fn ($t) => $t->payload['actual_cents'] ?? 0);
        $pendingIds = $allEntries->where('status', '!=', 'approved')->pluck('id')->all();
        $missingAssignments = ShiftAssignment::where('status', 'confirmed')->whereHas('shift', fn ($q) => $q->where('order_id', $order->id)->where('published_revision', '>', 0)->where('ends_at', '<=', now()->utc())->where('status', '!=', 'cancelled'))->whereDoesntHave('timeEntry', fn ($q) => $q->where('status', 'approved'))->pluck('id')->all();
        $costKnown = $missing === [] && $pendingIds === [] && $missingAssignments === [] && $entries->isNotEmpty();

        return ['order_id' => $order->id, 'currency' => 'EUR', 'actual_cost_cents' => $costKnown ? $cost : null, 'known_cost_cents' => $cost, 'missing_entry_ids' => $missing, 'pending_entry_ids' => $pendingIds, 'missing_assignment_ids' => $missingAssignments, 'accepted_revenue_cents' => $revenueKnown ? $revenue : null, 'contribution_cents' => $costKnown && $revenueKnown ? $revenue - $cost : null];
    }

    public function setBillingAmount(int $proofId, int $revision, int $cents, User $actor): OperationWorkflow
    {
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.costs.manage');
        Validator::make(compact('cents'), ['cents' => 'required|integer|min:0|max:100000000'])->validate();

        return OperationsTransaction::run(function () use ($proofId, $revision, $cents, $actor) {
            $proof = OperationWorkflow::lockForUpdate()->findOrFail($proofId);
            abort_unless($proof->kind === 'proof' && $proof->status === 'accepted', 409);
            abort_unless($proof->revision === $revision, 409);
            abort_if(OperationWorkflow::where('kind', 'billing')->where('order_id', $proof->order_id)->get()->contains(fn ($r) => ($r->payload['proof_id'] ?? null) === $proof->id && ($r->payload['proof_revision'] ?? null) === $revision), 409, 'Abgenommener Erlös bereits dokumentiert.');

            return app(OperationsWorkflowService::class)->create('billing', 'Abgenommener Erlös', ['proof_id' => $proof->id, 'proof_revision' => $revision, 'billing_cents' => $cents, 'currency' => 'EUR'], $actor, null, $proof->order_id);
        }, 3);
    }
}
