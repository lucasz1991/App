<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit review/apply adapter. Preparing never reserves, publishes or overrides a recorded response. */
class DistributionAutoAssignmentService
{
    public const MAX_SHIFTS = 100;

    public const MAX_SLOTS = 200;

    public function available(): bool
    {
        return OperationsAccess::ready() && WorkforcePlanningSchema::ready() && app(PlanningEnhancementService::class)->ready();
    }

    public function access(User $actor, bool $requireBulkReady = true): User
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.manage');
        OperationsAccess::requireReady();
        if ($requireBulkReady) {
            app(PlanningEnhancementService::class)->access($actor);
            WorkforcePlanningSchema::requireReady();
        }

        return $actor;
    }

    public function scope(string $from, string $until, string $customerFilter): array
    {
        Validator::make(compact('from', 'until'), [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $this->check(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) <= 93, 'Bitte höchstens 94 Tage auswählen.');
        $this->check($customerFilter === 'all' || preg_match('/^[1-9][0-9]*$/', $customerFilter) === 1, 'Der Kundenfilter ist ungültig.');
        if ($customerFilter !== 'all') {
            $this->check(Customer::whereKey($customerFilter)->exists(), 'Der gewählte Kunde ist nicht mehr verfügbar.');
        }

        return ['from' => $from, 'until' => $until, 'customer_filter' => $customerFilter];
    }

    public function prepare(string $from, string $until, string $customerFilter, User $actor): array
    {
        $actor = $this->access($actor);
        $scope = $this->scope($from, $until, $customerFilter);
        $suggestions = app(TimelinePlanningSuggestionService::class);
        $shifts = $this->openShifts($scope, $actor)->limit(self::MAX_SHIFTS + 1)->get();
        $this->check($shifts->count() <= self::MAX_SHIFTS, 'Die automatische Verteilung ist auf 100 offene Schichten begrenzt. Bitte einen kleineren Zeitraum oder einen Kunden auswählen.');
        $openSlots = (int) $shifts->sum(fn (Shift $shift) => max(0, $shift->required_staff - $shift->reserved_count));
        $this->check($openSlots <= self::MAX_SLOTS, 'Die automatische Verteilung ist auf 200 neue Einsatzplätze begrenzt. Bitte einen kleineren Zeitraum oder einen Kunden auswählen.');
        $this->check($shifts->every(fn (Shift $shift) => $shift->required_staff - $shift->reserved_count <= 100), 'Pro Schicht können höchstens 100 neue Einsatzplätze gemeinsam geprüft werden. Bitte diese Schicht einzeln planen.');
        $basis = $this->assignmentBasis($shifts->pluck('id')->all());
        $existingUsers = ShiftAssignment::whereIn('shift_id', $shifts->pluck('id'))->get(['shift_id', 'user_id'])->groupBy('shift_id');
        $additionalByUser = [];
        $entries = [];
        $details = [];
        $remaining = [];

        foreach ($shifts as $shift) {
            $open = max(0, (int) $shift->required_staff - (int) $shift->reserved_count);
            $recordedUsers = $existingUsers->get($shift->id, collect())->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $ranked = $suggestions->rankedCandidates($shift, $actor, $additionalByUser)
                ->reject(fn (array $candidate) => $candidate['wish'] === 'free_requested' || in_array((int) $candidate['user']->id, $recordedUsers, true));
            $candidates = collect();
            $remainingReason = 'Keine weitere konfliktfreie automatische Besetzung. Freiwünsche und vorhandene Zuweisungen bleiben unverändert.';
            foreach ($ranked as $candidate) {
                if ($candidates->count() >= $open) {
                    break;
                }
                $userId = (int) $candidate['user']->id;
                $virtual = collect($additionalByUser)->flatMap(fn (array $duties, int $id) => collect($duties)->map(fn (Shift $duty) => ['shift' => $duty, 'user_id' => $id]))->all();
                try {
                    app(OrderDemandService::class)->assertCapacity($shift, $userId, false, $virtual);
                } catch (ValidationException $exception) {
                    $remainingReason = collect($exception->errors())->flatten()->implode(' ');

                    continue;
                }
                $candidates->push($candidate);
                $additionalByUser[$userId][] = $shift;
                $details[$shift->id.':'.$userId] = ['period' => $this->period($shift), 'wish' => $candidate['wish'], 'reasons' => $candidate['reasons']];
            }
            if ($candidates->isNotEmpty()) {
                $entries[] = ['shift_id' => (int) $shift->id, 'revision' => (int) $shift->revision, 'user_ids' => $candidates->pluck('user.id')->map(fn ($id) => (int) $id)->all()];
            }
            if ($candidates->count() < $open) {
                $remaining[] = [
                    'shift_id' => (int) $shift->id, 'title' => $shift->title, 'period' => $this->period($shift),
                    'slots' => $open - $candidates->count(),
                    'reason' => $remainingReason,
                ];
            }
        }

        // Provider facts stay request-local and are prepared before any planning transaction.
        $travel = $entries === [] ? [] : app(TravelAssessmentService::class)->preflightForEntries($entries);
        $review = $entries === [] ? ['rows' => [], 'valid' => false, 'fingerprint' => '']
            : app(PlanningEnhancementService::class)->bulkPreview($entries, $actor, $travel);
        $review['rows'] = array_map(fn (array $row) => $row + $details[$row['shift_id'].':'.$row['user_id']], $review['rows']);
        $omitted = $this->omitted($scope);
        $plannedSlots = array_sum(array_map(fn (array $entry) => count($entry['user_ids']), $entries));

        return $review + [
            'scope' => $scope, 'prepared_at' => now()->utc()->toIso8601String(), 'entries' => $entries,
            'assignment_basis' => $basis, 'remaining' => $remaining,
            'summary' => [
                'shifts' => $shifts->count(), 'open_slots' => $openSlots, 'planned_slots' => $plannedSlots,
                'remaining_slots' => $openSlots - $plannedSlots, 'omitted_shifts' => array_sum($omitted),
                'omitted_started' => $omitted['started'], 'omitted_closed' => $omitted['closed'],
            ],
        ];
    }

    public function apply(array $prepared, string $from, string $until, string $customerFilter, User $actor): array
    {
        $actor = $this->access($actor);
        $scope = $this->scope($from, $until, $customerFilter);
        $this->check(($prepared['scope'] ?? null) === $scope, 'Zeitraum oder Kunde wurde geändert. Bitte die Vorschau erneuern.');
        $this->check(($prepared['valid'] ?? false) === true && ! empty($prepared['entries']) && is_string($prepared['fingerprint'] ?? null), 'Bitte zuerst eine gültige Verteilung prüfen.');
        $entries = Validator::make($prepared, [
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.shift_id' => ['required', 'integer', 'distinct', 'exists:shifts,id'],
            'entries.*.revision' => ['required', 'integer', 'min:1'],
            'entries.*.user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.user_ids.*' => ['required', 'integer', 'exists:users,id'],
        ])->validate()['entries'];
        $this->check(array_sum(array_map(fn (array $entry) => count($entry['user_ids']), $entries)) <= self::MAX_SLOTS, 'Die Vorschau überschreitet 200 neue Einsatzplätze. Bitte erneut prüfen.');
        $guard = function (bool $lock = false) use ($entries, $prepared, $scope, $actor): void {
            $this->assertCurrentBasis($entries, $prepared['assignment_basis'] ?? [], $scope, $actor, $lock);
        };
        $guard();

        // Reuse the native atomic path without placing its routing preflight in
        // an outer transaction. The extra automatic-only guard runs under its locks.
        $bulk = new class($guard) extends PlanningEnhancementService
        {
            public function __construct(private Closure $guard) {}

            public function bulkPreview(array $entries, User $actor, array $travelPreflights = [], bool $lock = false): array
            {
                if ($lock) {
                    ($this->guard)(true);
                }

                return parent::bulkPreview($entries, $actor, $travelPreflights, $lock);
            }
        };
        $saved = $bulk->bulkAssign($entries, $prepared['fingerprint'], $actor);

        return [
            'assigned' => count($saved), 'remaining_slots' => (int) ($prepared['summary']['remaining_slots'] ?? 0),
            'scope' => $scope,
            'message' => count($saved).' Einsatzplätze als Anfrage eingeteilt. Bestehende Zuweisungen und Veröffentlichung bleiben unverändert.',
        ];
    }

    private function openShifts(array $scope, User $actor): Builder
    {
        return app(TimelinePlanningSuggestionService::class)->openShifts($scope['from'], $scope['until'], $actor)
            ->when($scope['customer_filter'] !== 'all', fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('customer_id', (int) $scope['customer_filter'])));
    }

    private function assignmentBasis(array $shiftIds, bool $lock = false): array
    {
        $query = ShiftAssignment::whereIn('shift_id', $shiftIds)->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get(['id', 'shift_id', 'user_id', 'status', 'plan_revision', 'updated_at'])->groupBy('shift_id');

        return collect($shiftIds)->mapWithKeys(fn ($id) => [(int) $id => hash('sha256', json_encode($rows->get($id, collect())->map(fn ($row) => [
            $row->id, $row->user_id, $row->status->value, $row->plan_revision, $row->updated_at?->toIso8601String(),
        ])->all(), JSON_THROW_ON_ERROR))])->all();
    }

    private function assertCurrentBasis(array $entries, array $basis, array $scope, User $actor, bool $lock): void
    {
        $shiftIds = array_map('intval', array_column($entries, 'shift_id'));
        $shifts = $this->openShifts($scope, $actor)->whereKey($shiftIds)->get()->keyBy('id');
        $this->check($shifts->count() === count($entries), 'Eine Schicht liegt nicht mehr im gewählten Zeitraum/Kundenbereich oder ist nicht mehr offen. Bitte erneut prüfen.');
        $current = $this->assignmentBasis($shiftIds, $lock);
        foreach ($shiftIds as $shiftId) {
            $this->check(isset($basis[$shiftId]) && hash_equals($current[$shiftId], (string) $basis[$shiftId]), 'Vorhandene Zuweisungen oder Antworten wurden geändert. Bitte die Vorschau erneuern.');
        }
        $userIds = collect($entries)->pluck('user_ids')->flatten()->map(fn ($id) => (int) $id)->unique()->values();
        if ($lock) {
            EmployeeAvailability::whereIn('user_id', $userIds)->orderBy('id')->lockForUpdate()->get();
        }
        $users = User::whereKey($userIds)->get()->keyBy('id');
        foreach ($entries as $entry) {
            $wishes = app(WorkforcePlanningService::class)->wishSummaries($shifts[$entry['shift_id']], $users->only($entry['user_ids'])->values());
            foreach ($entry['user_ids'] as $userId) {
                $this->check(($wishes->get($userId)['state'] ?? 'unknown') !== 'free_requested', 'Ein neuer Freiwunsch betrifft die automatische Verteilung. Bitte erneut prüfen.');
            }
        }
    }

    private function omitted(array $scope): array
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $query = Shift::query()->during(CarbonImmutable::parse($scope['from'], $zone), CarbonImmutable::parse($scope['until'], $zone)->addDay())
            ->when($scope['customer_filter'] !== 'all', fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('customer_id', (int) $scope['customer_filter'])));
        $closedShifts = [ShiftStatus::InProgress->value, ShiftStatus::Completed->value, ShiftStatus::Cancelled->value];
        $closedOrders = [OrderStatus::Completed->value, OrderStatus::Invoiced->value, OrderStatus::Cancelled->value];

        return [
            'closed' => (clone $query)->where(fn (Builder $query) => $query->whereIn('status', $closedShifts)->orWhereHas('order', fn (Builder $order) => $order->whereIn('status', $closedOrders)))->count(),
            'started' => (clone $query)->whereNotIn('status', $closedShifts)->whereHas('order', fn (Builder $order) => $order->whereNotIn('status', $closedOrders))->where('starts_at', '<', now()->utc())->count(),
        ];
    }

    private function period(Shift $shift): string
    {
        $zone = (string) config('operations.display_timezone', 'Europe/Berlin');
        $start = $shift->starts_at->copy()->setTimezone($zone);
        $end = $shift->ends_at->copy()->setTimezone($zone);
        $offset = $start->offset !== $end->offset ? ' P' : '';

        return $start->format('d.m. H:i'.$offset).' – '.$end->format(($start->isSameDay($end) ? '' : 'd.m. ').'H:i'.$offset);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['autoAssignment' => $message]);
        }
    }
}
