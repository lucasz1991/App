<?php

namespace App\Services\CustomerPortal;

use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerCapacityReservation;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalSubmission;
use App\Models\EmployeeQualification;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\WorkforceAccountService;
use App\Support\CustomerPortal\CustomerPortalDateTime as OperationsDateTime;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CustomerCapacityService
{
    public function propose(User $actor, int $customerId, array $input): CustomerCapacityCommitment
    {
        CustomerPortalIntakeSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
        $data = Validator::make($input, ['user_id' => 'required|integer', 'location_id' => 'required|integer', 'role_name' => 'required|string|max:160', 'timezone' => 'required|timezone', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'planned_break_minutes' => 'required|integer|min:0|max:1440', 'qualification_ids' => 'present|array|max:50', 'qualification_ids.*' => 'integer|distinct'])->validate();
        [$from, $to] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);
        abort_unless($from->isFuture() && $data['planned_break_minutes'] < $from->diffInMinutes($to), 422, 'Zeitraum oder Pause ungültig.');

        return OperationsTransaction::run(function () use ($actor, $customerId, $data) {
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
            CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->findOrFail($data['location_id']);
            User::whereKey($data['user_id'])->where('status', true)->where('role', 'staff')->lockForUpdate()->firstOrFail();
            app(PersonnelScopeService::class)->authorize($actor, (int) $data['user_id'], 'employees.master-data.view');
            abort_unless(QualificationType::whereIn('id', $data['qualification_ids'])->where('is_active', true)->count() === count($data['qualification_ids']), 422);

            $record = CustomerCapacityCommitment::create($data + ['customer_id' => $customerId, 'created_by' => $actor->id, 'status' => 'requested', 'revision' => 1]);
            $this->audit($record, $actor, 'capacity_requested');

            return $record;
        });
    }

    public function consent(User $actor, int $id, int $revision, bool $accept): CustomerCapacityCommitment
    {
        CustomerPortalIntakeSchema::requireReady();
        OperationsAccess::own($actor, $actor->id);

        return OperationsTransaction::run(function () use ($actor, $id, $revision, $accept) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $record = CustomerCapacityCommitment::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            OperationsAccess::own(User::findOrFail($actor->id), $record->user_id);
            abort_unless($record->revision === $revision && $record->status === 'requested' && $record->starts_at->isFuture(), 409);
            $record->forceFill(['status' => $accept ? 'consented' : 'declined', 'consented_at' => $accept ? now()->utc() : null, 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, $accept ? 'capacity_consented' : 'capacity_declined');

            return $record;
        });
    }

    public function approve(User $actor, int $customerId, int $id, int $revision): CustomerCapacityCommitment
    {
        CustomerPortalIntakeSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');

        return OperationsTransaction::run(function () use ($actor, $customerId, $id, $revision) {
            $reference = CustomerCapacityCommitment::where('customer_id', $customerId)->findOrFail($id);
            User::whereKey($reference->user_id)->lockForUpdate()->firstOrFail();
            $record = CustomerCapacityCommitment::where('customer_id', $customerId)->lockForUpdate()->findOrFail($id);
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
            app(PersonnelScopeService::class)->authorize($actor, (int) $record->user_id, 'employees.master-data.view');
            abort_unless(OperationsAccess::ready(), 503);
            abort_unless($record->created_by !== $actor->id && $record->revision === $revision && $record->status === 'consented' && $record->consented_at && $record->starts_at->isFuture(), 409, 'Mitarbeiterzustimmung und zweite Freigabe erforderlich.');
            abort_unless(QualificationType::whereIn('id', $record->qualification_ids ?? [])->where('is_active', true)->count() === count($record->qualification_ids ?? []), 409, 'Nachweisgrundlagen fehlen.');
            app(StaffEligibilityService::class)->assertEligible($this->shift($record), User::findOrFail($record->user_id));
            $record->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()->utc(), 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'capacity_approved');

            return $record;
        });
    }

    /** Caller holds customer and submission locks. Staff locks serialize with every existing assignment. */
    public function reserve(CustomerPortalSubmission $submission): array
    {
        CustomerPortalIntakeSchema::requireReady();
        abort_unless(OperationsAccess::ready(), 503);
        $all = CustomerCapacityCommitment::where('customer_id', $submission->customer_id)->where('status', 'approved')->whereNotNull('approved_at')->whereNotNull('consented_at')->orderBy('user_id')->get();
        User::whereIn('id', $all->pluck('user_id')->unique())->orderBy('id')->lockForUpdate()->get();
        $reservations = [];
        foreach ($submission->items as $item) {
            $inquiry = OperationInquiry::lockForUpdate()->findOrFail($item->inquiry_id);
            $candidates = $all->filter(fn ($c) => $c->location_id === $item->location_id && $c->role_name === $inquiry->role_name && $c->starts_at->lte($inquiry->starts_at) && $c->ends_at->gte($inquiry->ends_at))->sortBy('user_id')->unique('user_id');
            $selected = 0;
            foreach ($candidates as $candidate) {
                $commitment = CustomerCapacityCommitment::lockForUpdate()->findOrFail($candidate->id);
                if ($commitment->status !== 'approved' || ! $commitment->consented_at || ! $commitment->approved_at) {
                    continue;
                }
                $approver = User::find($commitment->approved_by);
                if (! $approver?->status || ! CustomerLocation::whereKey($commitment->location_id)->where('customer_id', $submission->customer_id)->where('is_active', true)->exists()) {
                    continue;
                }
                try {
                    app(CustomerPortalScope::class)->authorizeManager($approver, $submission->customer_id, 'customers.portal.automation');
                    app(PersonnelScopeService::class)->authorize($approver, (int) $commitment->user_id, 'employees.master-data.view');
                } catch (AuthorizationException $exception) {
                    continue;
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 403) {
                        throw $exception;
                    }

                    continue;
                }
                $shift = $this->shift($commitment, $inquiry);
                $user = User::findOrFail($commitment->user_id);
                if (QualificationType::whereIn('id', $commitment->qualification_ids ?? [])->where('is_active', true)->count() !== count($commitment->qualification_ids ?? [])) {
                    continue;
                }
                $accounts = app(WorkforceAccountService::class);
                if (! $accounts->ready() || ! $accounts->effectiveModel($user, $inquiry->starts_at) || ! $accounts->effectiveRules($user, $inquiry->starts_at)) {
                    continue;
                }
                $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), true)[$user->id];
                if ($issues !== []) {
                    continue;
                }
                $reservations[] = CustomerCapacityReservation::create(['commitment_id' => $commitment->id, 'commitment_revision' => $commitment->revision, 'submission_id' => $submission->id, 'inquiry_id' => $inquiry->id, 'customer_id' => $submission->customer_id, 'user_id' => $user->id, 'role_name' => $inquiry->role_name, 'location_name' => $inquiry->location_name, 'planned_break_minutes' => $shift->planned_break_minutes, 'starts_at' => $inquiry->starts_at->utc(), 'ends_at' => $inquiry->ends_at->utc(), 'status' => 'held']);
                $selected++;
                if ($selected >= $inquiry->required_staff) {
                    break;
                }
            }
            abort_unless($selected === $inquiry->required_staff, 409, 'Kapazität nicht belastbar reservierbar.');
        }

        return $reservations;
    }

    public function planningIssues(Shift $shift, User $user): array
    {
        if (! Schema::hasTable('customer_capacity_reservations')) {
            return [];
        }
        if (! Schema::hasColumns('customer_capacity_reservations', ['user_id', 'status', 'starts_at', 'ends_at', 'order_id', 'role_name', 'location_name'])) {
            return [['code' => 'customer_capacity_incomplete', 'message' => 'Kundenreservierungen müssen geprüft werden.']];
        }
        $from = CarbonImmutable::instance($shift->starts_at);
        $to = CarbonImmutable::instance($shift->ends_at);
        $rest = max(0, (int) (app(WorkforceAccountService::class)->ready() ? app(WorkforceAccountService::class)->effectiveRules($user, $from)?->minimum_rest_minutes : OperationsRuleProfile::where('is_active', true)->value('minimum_rest_minutes')));
        $reservations = CustomerCapacityReservation::where('user_id', $user->id)->whereIn('status', ['held', 'committed'])
            ->where('starts_at', '<', $to->addMinutes($rest)->utc())->where('ends_at', '>', $from->subMinutes($rest)->utc())
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereIn('order_id', Order::whereNotIn('status', ['cancelled', 'completed', 'invoiced'])->select('id')))->get();
        $conflict = $reservations->contains(fn ($r) => ! ($r->order_id && (int) $r->order_id === (int) $shift->order_id && $r->role_name === $shift->role_name && $r->location_name === $shift->location_name && $r->starts_at->eq($from) && $r->ends_at->eq($to)));

        if ($conflict) {
            return [['code' => 'customer_capacity_reserved', 'message' => 'Der Zeitraum ist für eine bestätigte Kundenleistung reserviert.']];
        }
        foreach ($reservations as $reservation) {
            $commitment = CustomerCapacityCommitment::find($reservation->commitment_id);
            if (! $commitment || $commitment->revision !== (int) $reservation->commitment_revision || $commitment->status !== 'approved') {
                return [['code' => 'customer_capacity_changed', 'message' => 'Reservierungsgrundlage muss erneut geprüft werden.']];
            }
            $required = $commitment->qualification_ids ?? [];
            $types = QualificationType::whereKey($required)->where('is_active', true)->count();
            $valid = EmployeeQualification::where('user_id', $user->id)->where('status', 'approved')->whereIn('qualification_type_id', $required)->whereDate('valid_from', '<=', $from->setTimezone($shift->timezone)->toDateString())->whereDate('valid_until', '>=', $to->setTimezone($shift->timezone)->toDateString())->distinct()->count('qualification_type_id');
            if ($types !== count($required) || $valid !== count($required)) {
                return [['code' => 'customer_capacity_qualification', 'message' => 'Ein gültiger Nachweis der reservierten Leistung fehlt.']];
            }
        }

        return [];
    }

    /** Reserved work also counts towards existing weekly/rolling limits, without double-counting its assigned service. */
    public function additionalShifts(Shift $target, User $user): array
    {
        if (! Schema::hasTable('customer_capacity_reservations') || ! Schema::hasColumns('customer_capacity_reservations', ['user_id', 'order_id', 'status', 'starts_at', 'ends_at'])) {
            return [];
        }
        $from = CarbonImmutable::instance($target->starts_at);
        $to = CarbonImmutable::instance($target->ends_at);

        return CustomerCapacityReservation::where('user_id', $user->id)->whereIn('status', ['held', 'committed'])->where('starts_at', '<', $to->addDays(90)->utc())->where('ends_at', '>', $from->subDays(90)->utc())
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereIn('order_id', Order::whereNotIn('status', ['cancelled', 'completed', 'invoiced'])->select('id')))
            ->get()->reject(fn ($r) => $r->order_id && (int) $r->order_id === (int) $target->order_id && $r->role_name === $target->role_name && $r->location_name === $target->location_name && $r->starts_at->eq($from) && $r->ends_at->eq($to))
            ->reject(fn ($r) => $r->order_id && ShiftAssignment::blocking()->where('user_id', $user->id)->whereHas('shift', fn ($q) => $q->notCancelled()->where('order_id', $r->order_id)->where('starts_at', '<=', $r->starts_at)->where('ends_at', '>=', $r->ends_at))->exists())
            ->map(function ($r) {
                $s = new Shift(['timezone' => 'UTC', 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at, 'role_name' => $r->role_name, 'location_name' => $r->location_name, 'order_id' => $r->order_id, 'planned_break_minutes' => (int) $r->planned_break_minutes]);
                // No persisted shift is invented; negative synthetic identity only deduplicates preview calculations.
                $s->id = -$r->id;

                return $s;
            })->all();
    }

    private function shift(CustomerCapacityCommitment $c, ?OperationInquiry $inquiry = null): Shift
    {
        $shift = new Shift(['timezone' => $c->timezone, 'role_name' => $c->role_name, 'location_name' => CustomerLocation::findOrFail($c->location_id)->name, 'starts_at' => $inquiry?->starts_at ?? $c->starts_at, 'ends_at' => $inquiry?->ends_at ?? $c->ends_at, 'planned_break_minutes' => $c->planned_break_minutes, 'required_staff' => 1]);
        $shift->setRelation('qualifications', QualificationType::whereIn('id', $c->qualification_ids)->get());

        return $shift;
    }

    /** Read-only attention checks expose no personnel/absence reason to customer managers. */
    public function reviewItems(User $actor): Collection
    {
        if (! CustomerPortalIntakeSchema::ready()) {
            return collect();
        }
        $customers = app(CustomerPortalScope::class)->manageableCustomers($actor)->pluck('id');
        $result = collect();
        $reservations = CustomerCapacityReservation::whereIn('customer_id', $customers)->where('status', 'committed')->where('ends_at', '>', now()->utc())->whereIn('order_id', Order::whereNotIn('status', ['cancelled', 'completed', 'invoiced'])->select('id'))->limit(100)->get();
        foreach ($reservations as $r) {
            $user = User::find($r->user_id);
            $commitment = CustomerCapacityCommitment::find($r->commitment_id);
            $target = new Shift(['order_id' => $r->order_id, 'role_name' => $r->role_name, 'location_name' => $r->location_name, 'timezone' => 'UTC', 'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at, 'planned_break_minutes' => (int) $r->planned_break_minutes]);
            $target->setRelation('qualifications', QualificationType::whereKey($commitment?->qualification_ids ?? [])->get());
            $fulfilled = Shift::notCancelled()->where('order_id', $r->order_id)->where('role_name', $r->role_name)->where('location_name', $r->location_name)->where('starts_at', $r->starts_at->utc())->where('ends_at', $r->ends_at->utc())->whereHas('assignments', fn ($q) => $q->blocking()->where('user_id', $r->user_id))->first();
            if ($fulfilled) {
                $target->id = $fulfilled->id;
                $target->setRelation('qualifications', $target->qualifications->merge($fulfilled->qualifications)->unique('id'));
            }
            $accounts = app(WorkforceAccountService::class);
            $needsReview = ! $user || ! $accounts->ready() || ! $accounts->effectiveModel($user, $r->starts_at) || app(StaffEligibilityService::class)->assessMany($target, collect([$user]))[$user->id] !== [];
            if ($needsReview) {
                $result->push((object) ['id' => 'portal-reservation-'.$r->id, 'kind' => 'Kapazität erneut prüfen', 'title' => 'Reservierte Leistung #'.$r->order_id, 'subject' => '', 'user_id' => null, 'due_at' => $r->starts_at, 'status' => 'needs_review', 'revision' => 1, 'priority' => 0, 'module' => 'customer-portal', 'target_tab' => 'requests', 'record_id' => $r->id, 'personal' => false]);
                $result->last()->customer_id = $r->customer_id;
            }
        }

        return $result;
    }

    private function audit(CustomerCapacityCommitment $record, User $actor, string $action): void
    {
        CustomerPortalAudit::create(['customer_id' => $record->customer_id, 'actor_id' => $actor->id, 'action' => $action, 'revision' => $record->revision, 'details' => ['commitment_id' => $record->id, 'user_id' => $record->user_id, 'status' => $record->status]]);
    }
}
