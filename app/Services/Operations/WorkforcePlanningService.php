<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\AvailabilityPeriod;
use App\Models\EmployeeAvailability;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftOffer;
use App\Models\ShiftOfferResponse;
use App\Models\ShiftTransferRequest;
use App\Models\StaffingCase;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkforcePlanningService
{
    private function access(User $actor, bool $personal = false): void
    {
        $fresh = User::findOrFail($actor->id);
        if ($personal) {
            OperationsAccess::own($fresh, $actor->id);
        } else {
            OperationsAccess::authorize($fresh, 'operations.manage');
        }
        WorkforcePlanningSchema::requireReady();
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }

    public function savePool(?int $id, ?int $revision, array $data, User $actor): WorkforcePool
    {
        $this->access($actor);
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'kind' => 'required|in:regular,springer,reserve', 'location_name' => 'nullable|string|max:160', 'responsible_id' => 'nullable|integer|exists:users,id', 'is_active' => 'required|boolean', 'user_ids' => 'array|max:1000', 'user_ids.*' => 'integer|distinct|exists:users,id'])->validate();

        return OperationsTransaction::run(function () use ($id, $revision, $data, $actor) {
            $this->access($actor);
            $pool = $id ? WorkforcePool::lockForUpdate()->findOrFail($id) : new WorkforcePool;
            $this->check(! $id || $pool->revision === $revision, 'Pool wurde geändert. Bitte neu laden.');
            $ids = $data['user_ids'] ?? [];
            unset($data['user_ids']);
            $this->check(User::whereKey($ids)->where('role', 'staff')->where('status', true)->count() === count($ids), 'Nur aktive Mitarbeiter in Pools aufnehmen.');
            $pool->fill($data)->forceFill(['revision' => $id ? $revision + 1 : 1])->save();
            $pool->users()->sync($ids);
            app(OperationsAuditService::class)->record($pool, $actor, 'pool.saved', ['user_ids' => $ids]);

            return $pool;
        }, 3);
    }

    public function savePeriod(?int $id, ?int $revision, array $data, User $actor): AvailabilityPeriod
    {
        $this->access($actor);
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'due_at' => 'required|string', 'timezone' => 'required|timezone'])->validate();
        $this->check(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['until'])) <= 365, 'Zeitraum darf höchstens ein Jahr umfassen.');
        $data['due_at'] = OperationsDateTime::local($data['due_at'], $data['timezone'], 'due_at');

        return OperationsTransaction::run(function () use ($id, $revision, $data, $actor) {
            $period = $id ? AvailabilityPeriod::lockForUpdate()->findOrFail($id) : new AvailabilityPeriod;
            $this->check(! $id || $period->revision === $revision, 'Abgabefrist wurde geändert.');
            if ($id && EmployeeAvailability::where('availability_period_id', $id)->exists()) {
                $this->check($period->from->toDateString() === $data['from'] && $period->until->toDateString() === $data['until'] && $period->timezone === $data['timezone'], 'Zeitraum mit eingereichten Wünschen nicht nachträglich ändern.');
            }
            $period->fill($data)->forceFill(['revision' => $id ? $revision + 1 : 1, 'created_by' => $id ? $period->created_by : $actor->id])->save();
            app(OperationsAuditService::class)->record($period, $actor, 'availability.period.saved');

            return $period;
        }, 3);
    }

    public function saveWish(?int $id, ?int $revision, array $data, User $actor): EmployeeAvailability
    {
        $this->access($actor, true);
        $data = Validator::make($data, ['kind' => 'required|in:available,preferred,unavailable', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'integer|min:1|max:7|distinct', 'whole_day' => 'required|boolean', 'start_time' => 'nullable|required_if:whole_day,false|date_format:H:i', 'end_time' => 'nullable|required_if:whole_day,false|date_format:H:i|after:start_time', 'timezone' => 'required|timezone', 'preferred_pool_id' => 'nullable|integer|exists:workforce_pools,id', 'availability_period_id' => 'nullable|integer|exists:availability_periods,id', 'note' => 'nullable|string|max:1000', 'late_reason' => 'nullable|string|max:1000'])->validate();
        $this->check(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['until'])) <= 365, 'Wunschzeitraum darf höchstens ein Jahr umfassen.');
        if ($data['whole_day']) {
            $data['start_time'] = $data['end_time'] = null;
        }
        $this->wishWindows($data); // Reject ambiguous/nonexistent wall times before persisting.

        return OperationsTransaction::run(function () use ($id, $revision, $data, $actor) {
            $this->access($actor, true);
            // Lock period before person, matching deadline updates and wish writes.
            $period = ! empty($data['availability_period_id']) ? AvailabilityPeriod::lockForUpdate()->findOrFail($data['availability_period_id']) : null;
            User::lockForUpdate()->findOrFail($actor->id);
            $wish = $id ? EmployeeAvailability::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id) : new EmployeeAvailability;
            $this->check(! $id || $wish->revision === $revision, 'Wunsch wurde geändert.');
            $this->check(! $id || (int) $wish->availability_period_id === (int) ($data['availability_period_id'] ?? null), 'Zugeordnete Abgabefrist darf nicht entfernt werden.');
            if ($period) {
                $this->check($data['from'] >= $period->from->toDateString() && $data['until'] <= $period->until->toDateString(), 'Wunsch liegt außerhalb des Abgabezeitraums.');
                $this->check($period->due_at->isFuture() || mb_strlen(trim($data['late_reason'] ?? '')) >= 5, 'Nach Abgabefrist ist eine Begründung erforderlich.');
            }
            $wish->fill($data)->forceFill(['user_id' => $actor->id, 'revision' => $id ? $revision + 1 : 1])->save();
            app(OperationsAuditService::class)->record($wish, $actor, 'availability.submitted');

            return $wish;
        }, 3);
    }

    public function removeWish(int $id, int $revision, string $reason, User $actor): void
    {
        $this->access($actor, true);
        OperationsTransaction::run(function () use ($id, $revision, $reason, $actor) {
            $record = EmployeeAvailability::where('user_id', $actor->id)->findOrFail($id);
            $period = $record->availability_period_id ? AvailabilityPeriod::lockForUpdate()->findOrFail($record->availability_period_id) : null;
            User::lockForUpdate()->findOrFail($actor->id);
            $record = EmployeeAvailability::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            $this->check($record->revision === $revision, 'Wunsch wurde geändert.');
            $this->check(! $period || $period->due_at->isFuture() || mb_strlen(trim($reason)) >= 5, 'Nach Abgabefrist ist eine Begründung erforderlich.');
            app(OperationsAuditService::class)->record($record, $actor, 'availability.removed', ['reason' => mb_substr($reason, 0, 1000)]);
            $record->delete();
        }, 3);
    }

    private function wishWindows(array $data): Collection
    {
        $windows = collect();
        for ($day = CarbonImmutable::parse($data['from'], $data['timezone'])->startOfDay(); $day->toDateString() <= $data['until']; $day = $day->addDay()) {
            if (! in_array($day->isoWeekday(), array_map('intval', $data['weekdays']), true)) {
                continue;
            }
            [$start, $end] = $data['whole_day']
                ? [$day->utc(), $day->addDay()->utc()]
                : OperationsDateTime::interval($day->toDateString().'T'.$data['start_time'], $day->toDateString().'T'.$data['end_time'], $data['timezone']);
            $windows->push(compact('start', 'end'));
        }
        $this->check($windows->isNotEmpty(), 'Wochentage ergeben kein Zeitfenster im gewählten Zeitraum.');

        return $windows;
    }

    /** Wishes are informational. They never create approved absences or binding assignments. */
    public function wishSummary(Shift $shift, User $employee): array
    {
        if (! WorkforcePlanningSchema::ready()) {
            return ['state' => 'unknown', 'kinds' => []];
        }
        $startDate = $shift->starts_at->subDay()->toDateString();
        $endDate = $shift->ends_at->addDay()->toDateString();
        $kinds = EmployeeAvailability::where('user_id', $employee->id)->where('from', '<=', $endDate)->where('until', '>=', $startDate)->get()->filter(function ($wish) use ($shift) {
            $data = $wish->toArray();
            $data['from'] = $wish->from->toDateString();
            $data['until'] = $wish->until->toDateString();

            return $this->wishWindows($data)->contains(fn ($window) => $window['start']->lt($shift->ends_at) && $window['end']->gt($shift->starts_at));
        })->pluck('kind')->unique()->values()->all();

        return ['state' => in_array('unavailable', $kinds, true) ? 'free_requested' : (in_array('preferred', $kinds, true) ? 'preferred' : (in_array('available', $kinds, true) ? 'available' : 'unknown')), 'kinds' => $kinds];
    }

    public function periodStatus(AvailabilityPeriod $period, User $employee): string
    {
        return EmployeeAvailability::where('user_id', $employee->id)->where('availability_period_id', $period->id)->exists()
            ? 'submitted' : ($period->due_at->isPast() ? 'overdue' : 'open');
    }

    private function publishedFuture(Shift $shift, int $revision): void
    {
        $this->check(! $shift->trashed() && $shift->revision === $revision && $shift->published_revision === $revision && $revision > 0
            && $shift->starts_at->isFuture() && ! in_array($shift->status->value, ['draft', 'cancelled', 'completed', 'in_progress'], true), 'Nur unveränderte veröffentlichte zukünftige Dienste sind verfügbar.');
    }

    public function offer(int $shiftId, int $planRevision, array $userIds, string $expiresAt, string $timezone, User $actor): ShiftOffer
    {
        $this->access($actor);
        Validator::make(['ids' => $userIds, 'timezone' => $timezone], ['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer|distinct|exists:users,id', 'timezone' => 'required|timezone'])->validate();
        $expiry = OperationsDateTime::local($expiresAt, $timezone, 'expires_at');

        return OperationsTransaction::run(function () use ($shiftId, $planRevision, $userIds, $expiry, $actor) {
            $shift = PlanningLocks::acquire([$shiftId], $userIds)->get($shiftId);
            abort_unless($shift, 404);
            $this->publishedFuture($shift, $planRevision);
            $this->check($expiry->isFuture() && $expiry->lte($shift->starts_at), 'Angebotsfrist muss vor Dienstbeginn liegen.');
            $this->check($shift->assignments()->blocking()->count() < $shift->required_staff, 'Kein Einsatzplatz offen.');
            foreach (User::whereKey($userIds)->get() as $employee) {
                $this->check(! $shift->assignments()->blocking()->where('user_id', $employee->id)->exists(), 'Mitarbeiter ist bereits eingeplant.');
                app(StaffEligibilityService::class)->assertEligible($shift, $employee);
            }
            $this->check(! ShiftOffer::where('shift_id', $shiftId)->where('status', 'open')->where('expires_at', '>', now()->utc())->exists(), 'Es besteht bereits ein offenes Angebot.');
            $offer = ShiftOffer::create(['shift_id' => $shiftId, 'plan_revision' => $planRevision, 'invited_user_ids' => array_values($userIds), 'expires_at' => $expiry, 'created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($offer, $actor, 'offer.created');

            return $offer;
        }, 3);
    }

    public function respondOffer(int $id, int $revision, string $status, User $actor): ShiftOfferResponse
    {
        $this->access($actor, true);
        $this->check(in_array($status, ['interested', 'declined', 'withdrawn'], true), 'Ungültige Rückmeldung.');

        return OperationsTransaction::run(function () use ($id, $revision, $status, $actor) {
            $offer = ShiftOffer::findOrFail($id);
            $shift = PlanningLocks::acquire([$offer->shift_id], [$actor->id])->get($offer->shift_id);
            $offer = ShiftOffer::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($actor->id, $offer->invited_user_ids, true), 403);
            $this->check($offer->revision === $revision && $offer->status === 'open' && $offer->expires_at->isFuture(), 'Angebot wurde geändert oder ist abgelaufen.');
            $this->publishedFuture($shift, $offer->plan_revision);
            $response = ShiftOfferResponse::where('shift_offer_id', $id)->where('user_id', $actor->id)->lockForUpdate()->first() ?? new ShiftOfferResponse;
            $this->check(! in_array($response->status, ['approved', 'rejected'], true), 'Rückmeldung wurde bereits bearbeitet.');
            if ($status === 'interested') {
                app(StaffEligibilityService::class)->assertEligible($shift, User::findOrFail($actor->id));
                app(ShiftAssignmentService::class)->assertCapacity($shift, $actor);
            }
            $response->fill(['shift_offer_id' => $id, 'user_id' => $actor->id, 'status' => $status, 'responded_at' => now()->utc(), 'revision' => $response->exists ? $response->revision + 1 : 1])->save();
            app(OperationsAuditService::class)->record($response, $actor, 'offer.'.$status);

            return $response;
        }, 3);
    }

    public function reviewOffer(int $responseId, int $revision, bool $approve, User $actor): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($responseId, $revision, $approve, $actor) {
            $response = ShiftOfferResponse::with('offer')->findOrFail($responseId);
            abort_if($actor->id === $response->user_id, 403);
            $shift = PlanningLocks::acquire([$response->offer->shift_id], [$response->user_id])->get($response->offer->shift_id);
            $offer = ShiftOffer::lockForUpdate()->findOrFail($response->shift_offer_id);
            $response = ShiftOfferResponse::lockForUpdate()->findOrFail($responseId);
            $this->check($response->revision === $revision && $response->status === 'interested' && $offer->status === 'open' && $offer->expires_at->isFuture(), 'Angebot wurde bereits bearbeitet oder ist abgelaufen.');
            $this->publishedFuture($shift, $offer->plan_revision);
            if ($approve) {
                app(ShiftAssignmentService::class)->assign($shift, User::findOrFail($response->user_id), $actor, ShiftAssignmentStatus::Requested, null, $offer->plan_revision);
            }
            $response->update(['status' => $approve ? 'approved' : 'rejected', 'revision' => $revision + 1, 'reviewed_by' => $actor->id]);
            if ($approve && $shift->assignments()->blocking()->count() >= $shift->required_staff) {
                $offer->update(['status' => 'filled', 'revision' => $offer->revision + 1]);
            }
            app(OperationsAuditService::class)->record($response, $actor, $approve ? 'offer.approved' : 'offer.rejected');
        }, 3);
    }

    private function transferSources(ShiftTransferRequest $request): Collection
    {
        $ids = array_filter([$request->source_assignment_id, $request->target_assignment_id]);
        $sources = ShiftAssignment::whereKey($ids)->with('shift')->get()->keyBy('id');
        $this->check($sources->count() === count($ids), 'Dienstzuweisung fehlt.');

        return $sources;
    }

    private function assertTransferSource(ShiftAssignment $source, int $revision): void
    {
        $this->check($source->shift !== null, 'Dienst wurde entfernt.');
        $this->publishedFuture($source->shift, $revision);
        $this->check($source->status === ShiftAssignmentStatus::Confirmed && $source->plan_revision === $revision, 'Nur eigene bestätigte Dienste können übergeben werden.');
        $this->check(! WorkTimeEntry::where('shift_assignment_id', $source->id)->exists(), 'Für diesen Dienst wurden bereits Zeiten erfasst.');
    }

    public function requestTransfer(int $sourceId, int $sourceRevision, int $targetUserId, ?int $targetId, ?int $targetRevision, string $note, User $actor): ShiftTransferRequest
    {
        $this->access($actor, true);
        $this->check($targetUserId !== $actor->id, 'Übergabe an sich selbst ist nicht möglich.');
        Validator::make(['note' => $note], ['note' => 'nullable|string|max:1000'])->validate();

        return OperationsTransaction::run(function () use ($sourceId, $sourceRevision, $targetUserId, $targetId, $targetRevision, $note, $actor) {
            $source = ShiftAssignment::where('user_id', $actor->id)->with('shift')->findOrFail($sourceId);
            $target = $targetId ? ShiftAssignment::where('user_id', $targetUserId)->with('shift')->findOrFail($targetId) : null;
            $this->check(! $target || $source->shift_id !== $target->shift_id, 'Ein Tausch benötigt zwei verschiedene Dienste.');
            PlanningLocks::acquire(array_filter([$source->shift_id, $target?->shift_id]), [$actor->id, $targetUserId]);
            $source = $source->fresh(['shift']);
            $target = $target?->fresh(['shift']);
            $this->assertTransferSource($source, $sourceRevision);
            if ($target) {
                $this->assertTransferSource($target, (int) $targetRevision);
            }
            $recipient = User::findOrFail($targetUserId);
            $this->check(! $source->shift->assignments()->blocking()->where('user_id', $targetUserId)->exists(), 'Zielperson ist bereits im abzugebenden Dienst eingeplant.');
            $this->check(! $target || ! $target->shift->assignments()->blocking()->where('user_id', $actor->id)->exists(), 'Antragsteller ist bereits im Tauschdienst eingeplant.');
            $exclude = array_filter([$source->shift_id, $target?->shift_id]);
            app(StaffEligibilityService::class)->assertEligible($source->shift, $recipient, ['exclude_shift_ids' => $exclude]);
            if ($target) {
                app(StaffEligibilityService::class)->assertEligible($target->shift, $actor, ['exclude_shift_ids' => $exclude]);
            }
            $this->check(! ShiftTransferRequest::where('source_assignment_id', $sourceId)->whereIn('status', ['awaiting_target', 'pending'])->exists(), 'Für diesen Dienst besteht bereits eine Übergabeanfrage.');
            $request = ShiftTransferRequest::create(['source_assignment_id' => $sourceId, 'target_user_id' => $targetUserId, 'target_assignment_id' => $targetId, 'source_plan_revision' => $sourceRevision, 'target_plan_revision' => $targetRevision, 'note' => $note]);
            app(OperationsAuditService::class)->record($request, $actor, 'transfer.requested');

            return $request;
        }, 3);
    }

    public function respondTransfer(int $id, int $revision, bool $accept, User $actor): void
    {
        $this->access($actor, true);
        OperationsTransaction::run(function () use ($id, $revision, $accept, $actor) {
            $request = ShiftTransferRequest::findOrFail($id);
            abort_unless($request->target_user_id === $actor->id, 403);
            $sources = $this->transferSources($request);
            PlanningLocks::acquire($sources->pluck('shift_id')->all(), $sources->pluck('user_id')->push($actor->id)->all());
            $request = ShiftTransferRequest::lockForUpdate()->findOrFail($id);
            $this->check($request->revision === $revision && $request->status === 'awaiting_target', 'Anfrage wurde geändert.');
            foreach ($this->transferSources($request) as $source) {
                $this->assertTransferSource($source, $source->id === $request->source_assignment_id ? $request->source_plan_revision : $request->target_plan_revision);
            }
            $request->update(['status' => $accept ? 'pending' : 'declined', 'revision' => $revision + 1]);
            app(OperationsAuditService::class)->record($request, $actor, $accept ? 'transfer.consented' : 'transfer.declined');
        }, 3);
    }

    public function withdrawTransfer(int $id, int $revision, User $actor): void
    {
        $this->access($actor, true);
        OperationsTransaction::run(function () use ($id, $revision, $actor) {
            $record = ShiftTransferRequest::with('source')->findOrFail($id);
            abort_unless($record->source->user_id === $actor->id, 403);
            $record = ShiftTransferRequest::lockForUpdate()->findOrFail($id);
            $this->check($record->revision === $revision && in_array($record->status, ['awaiting_target', 'pending'], true), 'Anfrage wurde bereits bearbeitet.');
            $record->update(['status' => 'withdrawn', 'revision' => $revision + 1]);
            app(OperationsAuditService::class)->record($record, $actor, 'transfer.withdrawn');
        }, 3);
    }

    public function reviewTransfer(int $id, int $revision, bool $approve, string $note, User $actor): void
    {
        $this->access($actor);
        Validator::make(['note' => $note], ['note' => ($approve ? 'nullable' : 'required|min:5').'|string|max:1000'])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $approve, $note, $actor) {
            $request = ShiftTransferRequest::findOrFail($id);
            $sources = $this->transferSources($request);
            $source = $sources->get($request->source_assignment_id);
            $target = $sources->get($request->target_assignment_id);
            abort_if(in_array($actor->id, [$source->user_id, $request->target_user_id], true), 403);
            PlanningLocks::acquire($sources->pluck('shift_id')->all(), [$source->user_id, $request->target_user_id]);
            $request = ShiftTransferRequest::lockForUpdate()->findOrFail($id);
            $this->check($request->revision === $revision && $request->status === 'pending', 'Anfrage wurde bereits bearbeitet.');
            if ($approve) {
                $source = $source->fresh(['shift']);
                $target = $target?->fresh(['shift']);
                $this->assertTransferSource($source, $request->source_plan_revision);
                $this->check(! $source->shift->assignments()->blocking()->where('user_id', $request->target_user_id)->exists(), 'Zielperson ist bereits im abzugebenden Dienst eingeplant.');
                if ($target) {
                    $this->assertTransferSource($target, $request->target_plan_revision);
                    $this->check(! $target->shift->assignments()->blocking()->where('user_id', $source->user_id)->exists(), 'Antragsteller ist bereits im Tauschdienst eingeplant.');
                }
                $exclude = $sources->pluck('shift_id')->all();
                app(StaffEligibilityService::class)->assertEligible($source->shift, User::findOrFail($request->target_user_id), ['exclude_shift_ids' => $exclude]);
                if ($target) {
                    app(StaffEligibilityService::class)->assertEligible($target->shift, User::findOrFail($source->user_id), ['exclude_shift_ids' => $exclude]);
                }
                // Both releases happen inside the same transaction; fresh normal assignment guards then see the final schedule.
                $assigner = app(ShiftAssignmentService::class);
                $assigner->cancel($source, $actor, 'Freigegebene Übergabe');
                if ($target) {
                    $assigner->cancel($target, $actor, 'Freigegebener Tausch');
                }
                $assigner->assign($source->shift, User::findOrFail($request->target_user_id), $actor, ShiftAssignmentStatus::Requested, null, $request->source_plan_revision);
                if ($target) {
                    $assigner->assign($target->shift, User::findOrFail($source->user_id), $actor, ShiftAssignmentStatus::Requested, null, $request->target_plan_revision);
                }
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'review_note' => $note, 'revision' => $revision + 1]);
            app(OperationsAuditService::class)->record($request, $actor, $approve ? 'transfer.approved' : 'transfer.rejected');
        }, 3);
    }

    public function openCase(array $data, User $actor): StaffingCase
    {
        $this->access($actor);
        $data = Validator::make($data, ['shift_id' => 'required|integer|exists:shifts,id', 'shift_assignment_id' => 'nullable|integer|exists:shift_assignments,id', 'kind' => 'required|in:failure,relief,reserve,callout', 'responsible_id' => 'required|integer|exists:users,id', 'due_at' => 'required|string', 'timezone' => 'required|timezone', 'note' => 'required|string|max:1000'])->validate();
        $data['due_at'] = OperationsDateTime::local($data['due_at'], $data['timezone'], 'due_at');
        unset($data['timezone']);

        return OperationsTransaction::run(function () use ($data, $actor) {
            $shift = PlanningLocks::acquire([$data['shift_id']])->get($data['shift_id']);
            $this->check($shift && ! in_array($shift->status->value, ['cancelled', 'completed'], true), 'Kein aktiver Dienst.');
            $this->check(empty($data['shift_assignment_id']) || ShiftAssignment::where('shift_id', $shift->id)->whereKey($data['shift_assignment_id'])->blocking()->exists(), 'Betroffene Zuweisung gehört nicht zum Dienst.');
            $responsible = User::findOrFail($data['responsible_id']);
            OperationsAccess::authorize($responsible, 'operations.manage');
            $case = StaffingCase::create($data);
            app(OperationsAuditService::class)->record($case, $actor, 'staffing.case.opened');

            return $case;
        }, 3);
    }

    public function updateCase(int $id, int $revision, string $action, array $data, User $actor): void
    {
        $this->access($actor);
        $this->check(in_array($action, ['contact', 'escalate', 'resolve', 'handover'], true), 'Ungültige Aktion.');
        Validator::make($data, ['note' => 'required|string|min:5|max:1000', 'user_id' => 'nullable|integer|exists:users,id', 'channel' => 'nullable|in:phone,email,portal,chat', 'handed_over_at' => 'nullable|string', 'timezone' => 'nullable|timezone'])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $action, $data, $actor) {
            $case = StaffingCase::findOrFail($id);
            $shift = PlanningLocks::acquire([$case->shift_id], array_filter([$data['user_id'] ?? null]))->get($case->shift_id);
            $case = StaffingCase::lockForUpdate()->findOrFail($id);
            $this->check($case->revision === $revision && in_array($case->status, ['open', 'escalated'], true), 'Fall wurde bereits bearbeitet.');
            if ($action === 'contact') {
                $this->check(count($case->contacts ?? []) < 200, 'Kontaktprotokoll ist voll. Fall bearbeiten oder eskalieren.');
                $case->contacts = [...($case->contacts ?? []), ['user_id' => $data['user_id'] ?? null, 'channel' => $data['channel'] ?? 'portal', 'note' => $data['note'], 'at' => now()->utc()->toIso8601String(), 'actor_id' => $actor->id]];
            } elseif ($action === 'escalate') {
                $case->status = 'escalated';
                $case->resolution = $data['note'];
            } elseif ($action === 'handover') {
                $this->check(filled($data['handed_over_at'] ?? null) && filled($data['timezone'] ?? null), 'Tatsächlichen Übergabezeitpunkt angeben.');
                $at = OperationsDateTime::local($data['handed_over_at'], $data['timezone'], 'handed_over_at');
                $this->check($at->lte(now()->utc()) && $at->gte($shift->starts_at) && $at->lte($shift->ends_at), 'Übergabezeitpunkt muss im bereits begonnenen Dienst liegen.');
                $case->handed_over_at = $at;
                $case->resolution = $data['note'];
            } else {
                if (! empty($data['user_id'])) {
                    $replacement = $shift->assignments()->where('user_id', $data['user_id'])->where('status', 'confirmed')->first();
                    $this->check($replacement && $replacement->plan_revision === $shift->published_revision && $shift->revision === $shift->published_revision, 'Ersatzdienst muss aktuell bestätigt sein.');
                    $this->check($replacement->id !== $case->shift_assignment_id, 'Die ausgefallene Zuweisung ist kein Ersatz.');
                    app(StaffEligibilityService::class)->assertEligible($shift, User::findOrFail($data['user_id']));
                    $case->replacement_assignment_id = $replacement->id;
                } else {
                    $this->check(! $case->shift_assignment_id && $shift->assignments()->where('status', 'confirmed')->count() >= $shift->required_staff, 'Betroffener Einsatzplatz benötigt eine bestätigte Ersatzzuweisung.');
                }
                $this->check(! $case->shift_assignment_id || ! ShiftAssignment::whereKey($case->shift_assignment_id)->blocking()->exists() || $case->handed_over_at !== null, 'Betroffene Zuweisung zuerst umplanen oder tatsächliche Ablösung dokumentieren.');
                $case->status = 'resolved';
                $case->resolution = $data['note'];
            }
            $case->revision++;
            $case->save();
            app(OperationsAuditService::class)->record($case, $actor, 'staffing.case.'.$action);
        }, 3);
    }

    /** Read-only impact; pending absences can be evaluated without changing their status. */
    public function absenceImpact(int $userId, CarbonImmutable $start, CarbonImmutable $end, User $actor): Collection
    {
        $this->access($actor);

        return ShiftAssignment::blocking()->where('user_id', $userId)->whereHas('shift', fn ($q) => $q->notCancelled()->during($start, $end))->with('shift.order')->get()->map(function ($assignment) use ($userId) {
            $users = User::where('role', 'staff')->where('status', true)->where('id', '!=', $userId)->get();
            $issues = app(StaffEligibilityService::class)->assessMany($assignment->shift, $users);

            return ['assignment' => $assignment, 'eligible' => $users->filter(fn ($user) => ($issues[$user->id] ?? []) === [])->values(), 'issues' => $issues];
        });
    }
}
