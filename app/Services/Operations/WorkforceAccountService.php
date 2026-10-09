<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeRuleAssignment;
use App\Models\EmployeeVacationPolicy;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\VacationReservation;
use App\Models\WorkforceAccountEntry;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkforceAccountService
{
    private ?bool $schemaReady = null;

    public function __construct(private OperationsAuditService $audit) {}

    public function ready(): bool
    {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }
        foreach (['employee_work_models', 'employee_rule_assignments', 'employee_vacation_policies', 'workforce_account_entries', 'vacation_reservations'] as $table) {
            if (! Schema::hasTable($table)) {
                return $this->schemaReady = false;
            }
        }

        return $this->schemaReady = true;
    }

    public function effectiveModel(User|int $user, CarbonInterface|string $date): ?EmployeeWorkModel
    {
        if ($this->ready() && $date instanceof CarbonInterface) {
            $at = CarbonImmutable::instance($date);
            $utc = $at->utc();

            return EmployeeWorkModel::where('user_id', $user instanceof User ? $user->id : $user)->where('status', 'active')->where('starts_on', '<=', $utc->addDay()->toDateString())->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $utc->subDay()->toDateString()))->orderByDesc('starts_on')->get()->first(function ($model) use ($at) {
                $day = $at->setTimezone($model->timezone)->toDateString();

                return $model->starts_on <= $day && ($model->ends_on === null || $model->ends_on >= $day);
            });
        }

        return $this->ready() ? $this->effective(EmployeeWorkModel::class, $user, $date)->where('status', 'active')->first() : null;
    }

    public function effectiveRules(User|int $user, CarbonInterface|string $date): ?OperationsRuleProfile
    {
        if ($this->ready()) {
            $assignment = $this->effective(EmployeeRuleAssignment::class, $user, $date)->with('profile')->first();
            if ($assignment) {
                return $assignment->profile;
            }
        }

        return Schema::hasTable('operations_rule_profiles') ? OperationsRuleProfile::where('is_active', true)->first() : null;
    }

    /** Same civil-date selection as effectiveModel(), in one read for a candidate set. */
    public function effectiveModels(Collection $users, CarbonInterface $date): Collection
    {
        if (! $this->ready() || $users->isEmpty()) {
            return collect();
        }
        $at = CarbonImmutable::instance($date);
        $utc = $at->utc();

        return EmployeeWorkModel::whereIn('user_id', $users->pluck('id'))->where('status', 'active')
            ->where('starts_on', '<=', $utc->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $utc->subDay()->toDateString()))
            ->orderByDesc('starts_on')->get()->groupBy('user_id')->map(fn ($models) => $models->first(function ($model) use ($at) {
                $day = $at->setTimezone($model->timezone)->toDateString();

                return $model->starts_on <= $day && ($model->ends_on === null || $model->ends_on >= $day);
            }));
    }

    public function effectiveRulesFor(Collection $users, CarbonInterface $date, ?OperationsRuleProfile $fallback): Collection
    {
        $models = $this->effectiveModels($users, $date);
        $at = CarbonImmutable::instance($date);
        $days = $users->mapWithKeys(fn ($user) => [$user->id => $at->setTimezone($models->get($user->id)?->timezone ?? config('operations.display_timezone'))->toDateString()]);
        if ($days->isEmpty()) {
            return collect();
        }
        $assignments = EmployeeRuleAssignment::whereIn('user_id', $days->keys())->where('starts_on', '<=', $days->max())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $days->min()))
            ->with('profile')->orderByDesc('starts_on')->get()->groupBy('user_id');

        return $days->map(function ($day, $id) use ($assignments, $fallback) {
            $assignment = $assignments->get($id, collect())->first(fn ($row) => $row->starts_on <= $day && ($row->ends_on === null || $row->ends_on >= $day));

            return $assignment ? $assignment->profile : $fallback;
        });
    }

    public function effectivePolicy(User|int $user, CarbonInterface|string $date): ?EmployeeVacationPolicy
    {
        return $this->ready() ? $this->effective(EmployeeVacationPolicy::class, $user, $date)->where('status', 'active')->first() : null;
    }

    public function createModel(User $user, array $data, User $actor): EmployeeWorkModel
    {
        $this->manage($actor, $user);
        $data = Validator::make($data, [
            'name' => 'required|string|max:120', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'timezone' => 'required|timezone', 'weekly_target_minutes' => 'required|integer|min:0|max:10080', 'maximum_weekly_minutes' => 'nullable|integer|min:0|max:10080',
            'daily_minutes' => 'required|array|size:7', 'daily_minutes.*' => 'required|integer|min:0|max:1440', 'work_windows' => 'nullable|array', 'valuation_rules' => 'nullable|array', 'valuation_rules.*' => 'integer|between:0,10000',
        ])->validate();
        $days = array_map('intval', array_keys($data['daily_minutes']));
        sort($days);
        $this->check($days === range(1, 7), 'Arbeitstage müssen Montag bis Sonntag vollständig enthalten.');
        $data['daily_minutes'] = array_map('intval', $data['daily_minutes']);
        $data['valuation_rules'] = array_map('intval', $data['valuation_rules'] ?? []);
        $this->check(array_sum($data['daily_minutes']) === (int) $data['weekly_target_minutes'], 'Tageswerte müssen den Wochenstunden entsprechen.');
        $this->check(! isset($data['maximum_weekly_minutes']) || (int) $data['maximum_weekly_minutes'] >= (int) $data['weekly_target_minutes'], 'Wochenlimit darf nicht unter dem Soll liegen.');
        foreach (array_keys($data['valuation_rules'] ?? []) as $kind) {
            $this->check(in_array($kind, ['work', 'preparation', 'driving', 'shunting', 'travel', 'waiting', 'on_call', 'break', 'internal', 'training'], true), 'Unbekannte Zeitart in der Bewertung.');
        }
        foreach ($data['work_windows'] ?? [] as $day => $windows) {
            $this->check(in_array((int) $day, range(1, 7), true) && is_array($windows), 'Arbeitszeitfenster sind ungültig.');
            $previousEnd = -1;
            foreach ($windows as $window) {
                $this->check(is_array($window) && isset($window['start'], $window['end']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $window['start']) && preg_match('/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/', $window['end']), 'Arbeitszeitfenster müssen HH:MM verwenden.');
                $start = $this->wallMinutes($window['start']);
                $end = $this->wallMinutes($window['end']);
                $this->check($end > $start && $start >= $previousEnd, 'Arbeitszeitfenster dürfen sich nicht überschneiden.');
                $previousEnd = $end;
            }
        }

        return OperationsTransaction::run(function () use ($user, $data, $actor) {
            $this->lockUser($user);
            $model = EmployeeWorkModel::create($data + ['user_id' => $user->id, 'status' => 'draft', 'created_by' => $actor->id]);
            $this->audit->record($model, $actor, 'work_model.drafted', $data);

            return $model;
        }, 3);
    }

    public function createPolicy(User $user, array $data, User $actor): EmployeeVacationPolicy
    {
        $this->manage($actor, $user);
        $data = Validator::make($data, [
            'name' => 'required|string|max:120', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'unit' => 'required|in:days,minutes', 'holiday_region' => 'nullable|string|max:100', 'calendar_version' => 'required|string|max:120',
            'non_working_dates' => 'present|array', 'non_working_dates.*' => 'required|date_format:Y-m-d',
        ])->validate();

        return OperationsTransaction::run(function () use ($user, $data, $actor) {
            $this->lockUser($user);
            $policy = EmployeeVacationPolicy::create($data + ['user_id' => $user->id, 'status' => 'draft', 'created_by' => $actor->id]);
            $this->audit->record($policy, $actor, 'vacation_policy.drafted', $data);

            return $policy;
        }, 3);
    }

    public function activate(Model $version, int $revision, User $actor): void
    {
        $this->manage($actor, (int) $version->user_id);
        $this->check($version instanceof EmployeeWorkModel || $version instanceof EmployeeVacationPolicy, 'Ungültige Modellart.');
        abort_if((int) $version->created_by === (int) $actor->id, 403, 'Eine andere Person muss das Modell freigeben.');
        OperationsTransaction::run(function () use ($version, $revision, $actor): void {
            $this->lockUser((int) $version->user_id);
            $record = $version::lockForUpdate()->findOrFail($version->id);
            $this->check($record->revision === $revision && $record->status === 'draft', 'Modell wurde bereits geändert.');
            $this->check(! $record::where('user_id', $record->user_id)->where('status', 'active')->where('starts_on', '<=', $record->ends_on ?? '9999-12-31')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $record->starts_on))->exists(), 'Aktive Modellzeiträume dürfen sich nicht überschneiden.');
            if ($record instanceof EmployeeVacationPolicy) {
                $existingUnit = EmployeeVacationPolicy::where('user_id', $record->user_id)->where('status', 'active')->value('unit');
                $this->check($existingUnit === null || $existingUnit === $record->unit, 'Einheitenwechsel benötigt eine bestätigte Kontoumrechnung.');
            }
            $record->forceFill(['status' => 'active', 'revision' => $revision + 1, 'approved_by' => $actor->id, 'approved_at' => now()->utc()])->save();
            $impact = $record instanceof EmployeeWorkModel ? app(WorkforcePlanReviewService::class)->recheckForEmployee((int) $record->user_id, $record, $actor) : null;
            $this->audit->record($record, $actor, 'workforce_version.activated', ['starts_on' => $record->starts_on, 'ends_on' => $record->ends_on, 'planning_review' => $impact]);
        }, 3);
    }

    public function endVersion(Model $version, int $revision, string $endsOn, string $note, User $actor): void
    {
        $this->manage($actor, (int) $version->user_id);
        $this->check($version instanceof EmployeeWorkModel || $version instanceof EmployeeVacationPolicy, 'Ungültige Modellart.');
        Validator::make(['ends_on' => $endsOn, 'note' => $note], ['ends_on' => 'required|date_format:Y-m-d', 'note' => 'required|string|min:5|max:1000'])->validate();
        OperationsTransaction::run(function () use ($version, $revision, $endsOn, $note, $actor): void {
            $this->lockUser((int) $version->user_id);
            $record = $version::lockForUpdate()->findOrFail($version->id);
            $this->check($record->revision === $revision && $record->status === 'active', 'Modell wurde bereits geändert.');
            $timezone = $record instanceof EmployeeWorkModel ? $record->timezone : ($this->effectiveModel((int) $record->user_id, CarbonImmutable::now('UTC'))?->timezone ?? config('operations.display_timezone'));
            $this->check($endsOn >= $record->starts_on && $endsOn >= now($timezone)->toDateString(), 'Historische Gültigkeit kann nicht verkürzt werden.');
            $before = $record->ends_on;
            $this->check($before === null || $endsOn <= $before, 'Gültigkeit darf nur beendet werden.');
            if ($record instanceof EmployeeVacationPolicy) {
                $this->check(! VacationReservation::where('employee_vacation_policy_id', $record->id)->whereIn('status', ['reserved', 'approved'])->whereHas('absence', fn ($q) => $q->where('ends_at', '>', CarbonImmutable::parse($endsOn, $timezone)->addDay()->utc()))->exists(), 'Zukünftige Urlaubsbuchungen benötigen diese Richtlinie.');
            }
            $record->forceFill(['ends_on' => $endsOn, 'revision' => $revision + 1])->save();
            $impact = $record instanceof EmployeeWorkModel ? app(WorkforcePlanReviewService::class)->recheckForEmployee((int) $record->user_id, $record, $actor) : null;
            $this->audit->record($record, $actor, 'workforce_version.ended', ['previous_ends_on' => $before, 'ends_on' => $endsOn, 'note' => $note, 'planning_review' => $impact]);
        }, 3);
    }

    public function assignRules(User $user, array $data, User $actor): EmployeeRuleAssignment
    {
        $this->manage($actor, $user);
        app(PersonnelScopeService::class)->authorize($actor, $user, 'operations.rules.manage');
        $data = Validator::make($data, ['operations_rule_profile_id' => 'required|integer|exists:operations_rule_profiles,id', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on'])->validate();

        return OperationsTransaction::run(function () use ($user, $data, $actor) {
            $this->lockUser($user);
            $this->check(! EmployeeRuleAssignment::where('user_id', $user->id)->where('starts_on', '<=', $data['ends_on'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']))->exists(), 'Regelzuordnungen dürfen sich nicht überschneiden.');
            $record = EmployeeRuleAssignment::create($data + ['user_id' => $user->id, 'created_by' => $actor->id]);
            $impact = app(WorkforcePlanReviewService::class)->recheckForEmployee($user, $record, $actor);
            $this->audit->record($record, $actor, 'rules.assigned', $data + ['planning_review' => $impact]);

            return $record;
        }, 3);
    }

    public function creditVacation(User $user, array $data, User $actor): WorkforceAccountEntry
    {
        $this->manage($actor, $user);
        $data = Validator::make($data, ['effective_on' => 'required|date_format:Y-m-d', 'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:effective_on', 'entitlement_year' => 'required|integer|min:1900|max:9999', 'amount' => 'required|numeric|gt:0|max:1000000', 'kind' => 'required|in:grant,carry,manual', 'note' => 'required|string|min:5|max:1000', 'idempotency_key' => 'nullable|uuid'])->validate();

        return OperationsTransaction::run(function () use ($user, $data, $actor) {
            $this->lockUser($user);
            $policy = $this->effectivePolicy($user, $data['effective_on']);
            $this->check($policy !== null, 'Eine freigegebene Urlaubsrichtlinie fehlt.');
            $quantity = $this->units($data['amount'], $policy->unit);
            $payload = ['user_id' => (int) $user->id, 'account' => 'vacation', 'unit' => $policy->unit, 'quantity' => $quantity, 'kind' => $data['kind'], 'effective_on' => $data['effective_on'], 'expires_on' => $data['expires_on'] ?? null, 'entitlement_year' => (int) $data['entitlement_year'], 'note' => $data['note']];
            $existing = $this->idempotentEntry($data['idempotency_key'] ?? null, $payload);
            if ($existing) {
                return $existing;
            }
            $record = WorkforceAccountEntry::create($payload + ['idempotency_key' => $data['idempotency_key'] ?? null, 'employee_vacation_policy_id' => $policy->id, 'created_by' => $actor->id, 'snapshot' => ['policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'calendar_version' => $policy->calendar_version, 'request_hash' => $this->payloadHash($payload)]]);
            $this->audit->record($record, $actor, 'vacation.credited', ['quantity' => $quantity, 'unit' => $policy->unit, 'year' => $data['entitlement_year'], 'note' => $data['note']]);

            return $record;
        }, 3);
    }

    public function adjustTime(User $user, array $data, User $actor): WorkforceAccountEntry
    {
        $this->manage($actor, $user);
        $data = Validator::make($data, ['effective_on' => 'required|date_format:Y-m-d', 'quantity' => 'required|integer|between:-1000000,1000000|not_in:0', 'note' => 'required|string|min:5|max:1000', 'idempotency_key' => 'nullable|uuid'])->validate();

        return OperationsTransaction::run(function () use ($user, $data, $actor) {
            $this->lockUser($user);
            $payload = ['user_id' => (int) $user->id, 'account' => 'time', 'unit' => 'minutes', 'kind' => 'adjustment', 'effective_on' => $data['effective_on'], 'quantity' => (int) $data['quantity'], 'note' => $data['note']];
            $existing = $this->idempotentEntry($data['idempotency_key'] ?? null, $payload);
            if ($existing) {
                return $existing;
            }
            if (class_exists(PayrollClosingService::class)) {
                $date = CarbonImmutable::parse($data['effective_on'], config('operations.display_timezone', 'Europe/Berlin'));
                app(PayrollClosingService::class)->assertMutable($user, $date, $date->addDay());
            }
            $entry = WorkforceAccountEntry::create($payload + ['idempotency_key' => $data['idempotency_key'] ?? null, 'created_by' => $actor->id, 'snapshot' => ['request_hash' => $this->payloadHash($payload)]]);
            $this->audit->record($entry, $actor, 'time_account.adjusted', $data);

            return $entry;
        }, 3);
    }

    public function reduceCreditAmount(WorkforceAccountEntry $credit, mixed $amount, string $note, User $actor, ?string $idempotencyKey = null): WorkforceAccountEntry
    {
        $this->manage($actor, (int) $credit->user_id);
        Validator::make(['amount' => $amount], ['amount' => 'required|numeric|gt:0|max:1000000'])->validate();

        return $this->reduceCredit($credit, $this->units($amount, $credit->unit), $note, $actor, $idempotencyKey);
    }

    public function reduceCredit(WorkforceAccountEntry $credit, int $quantity, string $note, User $actor, ?string $idempotencyKey = null): WorkforceAccountEntry
    {
        $this->manage($actor, (int) $credit->user_id);
        Validator::make(['quantity' => $quantity, 'note' => $note, 'idempotency_key' => $idempotencyKey], ['quantity' => 'required|integer|min:1', 'note' => 'required|string|min:5|max:1000', 'idempotency_key' => 'nullable|uuid'])->validate();

        return OperationsTransaction::run(function () use ($credit, $quantity, $note, $actor, $idempotencyKey) {
            $this->lockUser((int) $credit->user_id);
            $source = WorkforceAccountEntry::lockForUpdate()->findOrFail($credit->id);
            $this->check($source->account === 'vacation' && $source->quantity > 0 && $source->source_entry_id === null, 'Anspruchsbuchung ist ungültig.');
            $payload = ['user_id' => (int) $source->user_id, 'account' => 'vacation', 'unit' => $source->unit, 'kind' => 'correction', 'source_entry_id' => (int) $source->id, 'quantity' => -$quantity, 'note' => $note];
            $existing = $this->idempotentEntry($idempotencyKey, $payload);
            if ($existing) {
                return $existing;
            }
            $taken = VacationReservation::where('workforce_account_entry_id', $source->id)->whereIn('status', ['reserved', 'approved'])->sum('quantity');
            $remaining = $source->quantity + (int) WorkforceAccountEntry::where('source_entry_id', $source->id)->sum('quantity') - $taken;
            $this->check($quantity <= $remaining, 'Bereits gebuchten Anspruch zuerst über den Antrag korrigieren.');
            $entry = WorkforceAccountEntry::create($payload + ['effective_on' => now(config('operations.display_timezone'))->toDateString(), 'idempotency_key' => $idempotencyKey, 'employee_vacation_policy_id' => $source->employee_vacation_policy_id, 'entitlement_year' => $source->entitlement_year, 'created_by' => $actor->id, 'snapshot' => ['source_quantity' => $source->quantity, 'request_hash' => $this->payloadHash($payload)]]);
            $this->audit->record($entry, $actor, 'vacation.corrected', ['source_id' => $source->id, 'quantity' => -$quantity, 'note' => $note]);

            return $entry;
        }, 3);
    }

    public function assertTrainingClock(User $user, int $trainingSessionId, CarbonImmutable $at): void
    {
        OperationsAccess::own($user, $user->id);
        abort_unless(Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants'), 503);
        $training = PersonnelTraining::findOrFail($trainingSessionId);
        $this->check($training->status === 'scheduled' && PersonnelTrainingParticipant::where('personnel_training_id', $training->id)->where('user_id', $user->id)->where('status', 'confirmed')->exists(), 'Keine bestätigte Schulungsteilnahme.');
        $this->check($at->greaterThanOrEqualTo($training->starts_at) && $at->lessThan($training->ends_at), 'Schulungsuhr kann nur während des Termins gestartet werden.');
        $this->check(! WorkTimeEntry::where('user_id', $user->id)->whereIn('status', ['running', 'paused'])->exists(), 'Eine Zeiterfassung läuft bereits.');
    }

    /** Call within the request transaction while holding its user's lock. */
    public function reserveAbsence(AbsenceRequest $request, bool $adopting = false): void
    {
        if (! $this->ready() || $request->kind !== 'vacation') {
            return;
        }
        $quote = $this->absenceQuote($request);
        if (! $quote['configured']) {
            return; // Existing unconfigured employee workflows remain usable; UI exposes missing configuration.
        }
        $this->check($quote['issues'] === [], implode(' ', $quote['issues']));
        $this->check($adopting || $this->unallocatedAbsences((int) $request->user_id) === [], 'Genehmigte Altanträge müssen zunächst dem Urlaubskonto zugeordnet werden.');
        $this->check(! VacationReservation::where('absence_request_id', $request->id)->exists(), 'Urlaub wurde bereits gebucht.');
        $credits = WorkforceAccountEntry::where('user_id', $request->user_id)->where('account', 'vacation')->where('quantity', '>', 0)->whereNull('source_entry_id')->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->orderBy('id')->lockForUpdate()->get();
        $allocations = [];
        foreach ($quote['days'] as $day) {
            $remaining = $day['quantity'];
            foreach ($credits as $credit) {
                if ($credit->unit !== $quote['unit'] || $credit->effective_on > $day['date'] || ($credit->expires_on !== null && $credit->expires_on < $day['date'])) {
                    continue;
                }
                $used = VacationReservation::where('workforce_account_entry_id', $credit->id)->whereIn('status', ['reserved', 'approved'])->sum('quantity');
                $correction = WorkforceAccountEntry::where('source_entry_id', $credit->id)->sum('quantity');
                $available = $credit->quantity + $correction - $used - ($allocations[$credit->id]['quantity'] ?? 0);
                $take = min($remaining, max(0, $available));
                if ($take > 0) {
                    $allocations[$credit->id] ??= ['quantity' => 0, 'days' => []];
                    $allocations[$credit->id]['quantity'] += $take;
                    $allocations[$credit->id]['days'][] = ['date' => $day['date'], 'quantity' => $take, 'model_id' => $day['model_id']];
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            $this->check($remaining === 0, 'Der verfügbare Urlaubsanspruch reicht am '.$day['date'].' nicht aus.');
        }
        foreach ($allocations as $creditId => $allocation) {
            VacationReservation::create(['user_id' => $request->user_id, 'absence_request_id' => $request->id, 'workforce_account_entry_id' => $creditId, 'employee_vacation_policy_id' => $quote['policy_id'], 'quantity' => $allocation['quantity'], 'status' => 'reserved', 'snapshot' => $allocation + ['unit' => $quote['unit'], 'policy_revision' => $quote['policy_revision'], 'calendar_version' => $quote['calendar_version']]]);
        }
    }

    /** Call within the approval transaction while holding the user's lock. */
    public function approveAbsence(AbsenceRequest $request): void
    {
        if (! $this->ready() || $request->kind !== 'vacation') {
            return;
        }
        $quote = $this->absenceQuote($request);
        if (! $quote['configured']) {
            return;
        }
        $this->check($quote['issues'] === [], implode(' ', $quote['issues']));
        if (! VacationReservation::where('absence_request_id', $request->id)->exists()) {
            $this->reserveAbsence($request); // Controlled adoption of a pending legacy request.
        }
        $reservations = VacationReservation::where('absence_request_id', $request->id)->lockForUpdate()->get();
        $this->check((int) $reservations->where('status', 'reserved')->sum('quantity') === $quote['quantity'], 'Die Urlaubsbewertung hat sich geändert. Antrag zurücknehmen und neu einreichen.');
        foreach ($reservations as $reservation) {
            $this->check((int) $reservation->employee_vacation_policy_id === $quote['policy_id'] && (int) $reservation->snapshot['policy_revision'] === $quote['policy_revision'], 'Die Urlaubsrichtlinie hat sich geändert. Antrag erneut einreichen.');
            $dayModels = collect($quote['days'])->keyBy('date');
            foreach ($reservation->snapshot['days'] as $day) {
                $this->check(isset($dayModels[$day['date']]) && (int) $dayModels[$day['date']]['model_id'] === (int) $day['model_id'], 'Das Arbeitsmodell hat sich geändert. Antrag erneut einreichen.');
            }
            $credit = WorkforceAccountEntry::lockForUpdate()->findOrFail($reservation->workforce_account_entry_id);
            $reserved = VacationReservation::where('workforce_account_entry_id', $credit->id)->whereIn('status', ['reserved', 'approved'])->sum('quantity');
            $this->check($reserved <= $credit->quantity + WorkforceAccountEntry::where('source_entry_id', $credit->id)->sum('quantity'), 'Der Urlaubsanspruch wurde zwischenzeitlich geändert.');
            $reservation->update(['status' => 'approved']);
        }
    }

    public function releaseAbsence(AbsenceRequest $request): void
    {
        if ($this->ready()) {
            VacationReservation::where('absence_request_id', $request->id)->whereIn('status', ['reserved', 'approved'])->update(['status' => 'released']);
        }
    }

    public function adoptApprovedAbsence(AbsenceRequest $request, int $revision, string $note, User $actor): void
    {
        $this->manage($actor, (int) $request->user_id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $request->user_id, 'operations.absences.review');
        abort_if((int) $actor->id === (int) $request->user_id, 403);
        Validator::make(['note' => $note], ['note' => 'required|string|min:5|max:1000'])->validate();
        OperationsTransaction::run(function () use ($request, $revision, $note, $actor): void {
            $this->lockUser((int) $request->user_id);
            $record = AbsenceRequest::lockForUpdate()->findOrFail($request->id);
            $this->check($record->kind === 'vacation' && $record->status === 'approved' && $record->revision === $revision, 'Altantrag wurde geändert.');
            $this->check(! VacationReservation::where('absence_request_id', $record->id)->exists(), 'Antrag ist bereits zugeordnet.');
            $this->check($this->absenceQuote($record)['configured'], 'Gültige Urlaubsrichtlinie fehlt.');
            $this->reserveAbsence($record, true);
            $this->approveAbsence($record);
            $record->increment('revision');
            $this->audit->record($record, $actor, 'vacation.legacy_adopted', ['note' => $note]);
        }, 3);
    }

    public function unallocatedAbsences(User|int $user): array
    {
        if (! $this->ready()) {
            return [];
        }
        $id = $user instanceof User ? $user->id : $user;
        $startsOn = EmployeeVacationPolicy::where('user_id', $id)->where('status', 'active')->min('starts_on');
        if (! $startsOn) {
            return [];
        }
        $timezone = $this->effectiveModel($id, $startsOn)?->timezone ?? config('operations.display_timezone');
        $cutoff = CarbonImmutable::parse($startsOn, $timezone)->startOfDay()->utc();

        return AbsenceRequest::where('user_id', $id)->where('kind', 'vacation')->where('status', 'approved')->where('ends_at', '>', $cutoff)->whereNotIn('id', VacationReservation::select('absence_request_id'))->pluck('id')->map(fn ($value) => (int) $value)->all();
    }

    public function absenceQuote(AbsenceRequest $request): array
    {
        $base = ['configured' => false, 'issues' => [], 'days' => [], 'quantity' => null, 'unit' => null, 'policy_id' => null, 'policy_revision' => null, 'calendar_version' => null];
        if (! $this->ready() || $request->kind !== 'vacation') {
            return $base;
        }
        $start = CarbonImmutable::instance($request->starts_at)->setTimezone($request->timezone);
        $end = CarbonImmutable::instance($request->ends_at)->setTimezone($request->timezone);
        $policy = $this->effectivePolicy((int) $request->user_id, $start->toDateString());
        $hasPolicy = EmployeeVacationPolicy::where('user_id', $request->user_id)->where('status', 'active')->exists();
        if (! $policy) {
            return array_replace($base, ['configured' => $hasPolicy, 'issues' => ['Gültige Urlaubsrichtlinie fehlt.']]);
        }
        $result = array_replace($base, ['configured' => true, 'quantity' => 0, 'unit' => $policy->unit, 'policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'calendar_version' => $policy->calendar_version]);
        $fraction = (float) ($request->getAttribute('vacation_fraction') ?? 1);
        if ($fraction !== 1.0 && $fraction !== 0.5) {
            $result['issues'][] = 'Urlaubsanteil ist ungültig.';
        }
        $thisEnd = $end->subSecond()->startOfDay();
        $fullDays = $start->format('H:i:s') === '00:00:00' && $end->format('H:i:s') === '00:00:00';
        if ($fraction === 0.5 && ($fullDays || $start->toDateString() !== $thisEnd->toDateString())) {
            $result['issues'][] = 'Halbtagsurlaub benötigt ein konkretes Zeitfenster an einem Arbeitstag.';
        }
        if (! $fullDays && $policy->unit === 'days' && $fraction !== 0.5) {
            $result['issues'][] = 'Tagesurlaub benötigt ganze Tage oder ein geprüftes Halbtagsfenster.';
        }
        if ($start->diffInDays($end) > 366) {
            $result['issues'][] = 'Urlaubszeitraum ist zu lang.';

            return $result;
        }
        for ($date = $start->startOfDay(); $date->lessThan($end); $date = $date->addDay()) {
            $day = $date->toDateString();
            $model = $this->effectiveModel((int) $request->user_id, $day);
            $dailyPolicy = $this->effectivePolicy((int) $request->user_id, $day);
            if (! $model || ! $dailyPolicy || $dailyPolicy->id !== $policy->id) {
                $result['issues'][] = 'Arbeitsmodell/Richtlinie fehlt oder wechselt am '.$day.'.';

                continue;
            }
            $minutes = (int) ($model->daily_minutes[$date->dayOfWeekIso] ?? 0);
            if ($model->timezone !== $request->timezone) {
                $result['issues'][] = 'Urlaubszeitzone stimmt am '.$day.' nicht mit dem Arbeitsmodell überein.';

                continue;
            }
            if ($minutes === 0 || in_array($day, $policy->non_working_dates, true)) {
                continue;
            }
            $chargeMinutes = $minutes;
            if (! $fullDays) {
                $windows = $model->work_windows[$date->dayOfWeekIso] ?? [];
                if ($windows === []) {
                    $result['issues'][] = 'Teilurlaub benötigt konkrete Arbeitszeitfenster am '.$day.'.';

                    continue;
                }
                $windowSeconds = 0;
                $overlapSeconds = 0;
                try {
                    foreach ($windows as $window) {
                        $windowStart = OperationsDateTime::local($day.'T'.$window['start'], $model->timezone);
                        $windowEnd = $window['end'] === '24:00' ? OperationsDateTime::local($date->addDay()->toDateString().'T00:00', $model->timezone) : OperationsDateTime::local($day.'T'.$window['end'], $model->timezone);
                        $windowSeconds += (int) $windowStart->diffInSeconds($windowEnd);
                        $overlapSeconds += $this->overlapSeconds($windowStart, $windowEnd, $start, $end);
                    }
                } catch (ValidationException) {
                    $result['issues'][] = 'Arbeitszeitfenster sind wegen der Zeitumstellung nicht eindeutig am '.$day.'.';

                    continue;
                }
                if ($windowSeconds !== $minutes * 60 || $overlapSeconds % 60 !== 0 || ($fraction === 0.5 && $overlapSeconds !== $minutes * 30)) {
                    $result['issues'][] = 'Netto-Arbeitszeitfenster und Urlaubsanteil stimmen am '.$day.' nicht überein.';

                    continue;
                }
                $chargeMinutes = intdiv($overlapSeconds, 60);
            }
            if ($chargeMinutes === 0) {
                continue;
            }
            $quantity = $policy->unit === 'days' ? (int) (100 * $fraction) : $chargeMinutes;
            $result['days'][] = ['date' => $day, 'quantity' => $quantity, 'model_id' => $model->id];
            $result['quantity'] += $quantity;
        }
        if ($result['quantity'] === 0) {
            $result['issues'][] = 'Der Zeitraum enthält keine bewertbaren Arbeitstage.';
        }

        return $result;
    }

    public function summary(User $user, CarbonInterface|string $from, CarbonInterface|string $until, User $actor): array
    {
        if ($actor->id === $user->id) {
            OperationsAccess::own($actor, $user->id);
        } else {
            app(PersonnelScopeService::class)->authorize($actor, $user, 'employees.master-data.view');
        }
        $start = $this->day($from);
        $end = $this->day($until);
        $this->check($end >= $start && $start->diffInDays($end) <= 366, 'Zeitraum darf höchstens 367 Tage umfassen.');
        $missing = [];
        $target = 0;
        $modelIds = [];
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $model = $this->effectiveModel($user, $date);
            if (! $model) {
                $missing[] = $date->toDateString();

                continue;
            }
            $modelIds[$model->id] = true;
            $target += (int) ($model->daily_minutes[$date->dayOfWeekIso] ?? 0);
            $policy = $this->effectivePolicy($user, $date);
            if ($policy && in_array($date->toDateString(), $policy->non_working_dates, true)) {
                $target -= (int) ($model->daily_minutes[$date->dayOfWeekIso] ?? 0);
            }
        }
        $timezone = $this->effectiveModel($user, $start)?->timezone ?? config('operations.display_timezone', 'Europe/Berlin');
        $periodStart = CarbonImmutable::parse($start->toDateString(), $timezone)->startOfDay()->utc();
        $periodEnd = CarbonImmutable::parse($end->toDateString(), $timezone)->addDay()->startOfDay()->utc();
        $actualSeconds = 0;
        $creditedSeconds = 0;
        $missingTimeAllocations = [];
        $missingValuations = [];
        if (Schema::hasTable('work_time_entries')) {
            foreach (WorkTimeEntry::where('user_id', $user->id)->where('status', 'approved')->whereNotNull('ends_at')->where('starts_at', '<', $periodEnd)->where('ends_at', '>', $periodStart)->get() as $entry) {
                $creditedPart = $this->creditedPeriodSeconds($entry, $periodStart, $periodEnd);
                if ($creditedPart === null) {
                    $missingValuations[] = $entry->id;
                } else {
                    $creditedSeconds += $creditedPart;
                }
                if ($entry->starts_at->greaterThanOrEqualTo($periodStart) && $entry->ends_at->lessThanOrEqualTo($periodEnd)) {
                    $actualSeconds += $entry->netSeconds();

                    continue;
                }
                $activities = Schema::hasTable('work_time_activities') ? $entry->activities()->orderBy('starts_at')->get() : collect();
                if ($activities->isEmpty()) {
                    if ((int) $entry->pause_seconds > 0) {
                        $missingTimeAllocations[] = $entry->id;
                    } else {
                        $actualSeconds += $this->overlapSeconds($entry->starts_at, $entry->ends_at, $periodStart, $periodEnd);
                    }

                    continue;
                }
                $cursor = $entry->starts_at;
                $pause = 0;
                $parts = 0;
                $valid = true;
                foreach ($activities as $activity) {
                    if (! $activity->ends_at || ! $activity->starts_at->equalTo($cursor) || ! $activity->ends_at->greaterThan($activity->starts_at) || $activity->ends_at->greaterThan($entry->ends_at)) {
                        $valid = false;
                        break;
                    }
                    $cursor = $activity->ends_at;
                    if ($activity->kind === 'break') {
                        $pause += (int) $activity->starts_at->diffInSeconds($activity->ends_at);
                    } else {
                        $parts += $this->overlapSeconds($activity->starts_at, $activity->ends_at, $periodStart, $periodEnd);
                    }
                }
                if (! $valid || ! $cursor->equalTo($entry->ends_at) || $pause !== (int) $entry->pause_seconds) {
                    $missingTimeAllocations[] = $entry->id;
                } else {
                    $actualSeconds += $parts;
                }
            }
        }
        $actual = $missingTimeAllocations === [] ? intdiv($actualSeconds, 60) : null;
        $credited = $missingValuations === [] && $missingTimeAllocations === [] ? intdiv($creditedSeconds, 60) : null;
        $adjustments = $this->ready() ? (int) WorkforceAccountEntry::where('user_id', $user->id)->where('account', 'time')->whereBetween('effective_on', [$start->toDateString(), $end->toDateString()])->sum('quantity') : 0;
        $vacationCredit = 0;
        $vacationDays = [];
        if ($this->ready()) {
            // Approved vacation credits target time once; training is never credited through this path.
            foreach (VacationReservation::where('user_id', $user->id)->where('status', 'approved')->get() as $reservation) {
                foreach ($reservation->snapshot['days'] ?? [] as $day) {
                    if ($day['date'] < $start->toDateString() || $day['date'] > $end->toDateString()) {
                        continue;
                    }
                    $key = $day['date'].':'.$day['model_id'].':'.$reservation->snapshot['unit'];
                    $vacationDays[$key] ??= $day + ['unit' => $reservation->snapshot['unit']];
                    if (isset($vacationDays[$key]['accumulated'])) {
                        $vacationDays[$key]['accumulated'] += $day['quantity'];
                    } else {
                        $vacationDays[$key]['accumulated'] = $day['quantity'];
                    }
                }
            }
        }
        foreach ($vacationDays as $day) {
            $model = EmployeeWorkModel::find($day['model_id']);
            if ($model) {
                $minutes = (int) ($model->daily_minutes[CarbonImmutable::parse($day['date'])->dayOfWeekIso] ?? 0);
                $vacationCredit += $day['unit'] === 'days' ? (int) round($minutes * $day['accumulated'] / 100) : $day['accumulated'];
            }
        }

        $unallocated = $this->unallocatedAbsences($user);

        return ['configured' => $missing === [], 'missing_dates' => $missing, 'model_ids' => array_keys($modelIds), 'target_minutes' => $missing === [] ? $target : null, 'actual_minutes' => $actual, 'actual_seconds' => $missingTimeAllocations === [] ? $actualSeconds : null, 'missing_time_allocations' => $missingTimeAllocations, 'credited_minutes' => $credited, 'credited_seconds' => $credited === null ? null : $creditedSeconds, 'missing_valuations' => $missingValuations, 'missing_absence_allocations' => $unallocated, 'absence_credit_minutes' => $vacationCredit, 'adjustment_minutes' => $adjustments, 'raw_balance_minutes' => $missing === [] && $actual !== null && $unallocated === [] ? $actual + $vacationCredit + $adjustments - $target : null, 'balance_minutes' => $missing === [] && $credited !== null && $unallocated === [] ? $credited + $vacationCredit + $adjustments - $target : null, 'vacation' => $this->vacationBalance($user, $end->toDateString())];
    }

    public function vacationBalance(User|int $user, CarbonInterface|string $asOf): array
    {
        $date = $this->day($asOf)->toDateString();
        $policy = $this->effectivePolicy($user, $date);
        $result = ['configured' => $policy !== null, 'unit' => $policy?->unit, 'credited' => 0, 'reserved' => 0, 'approved' => 0, 'used' => 0, 'expired' => 0, 'available' => null, 'missing_absence_allocations' => []];
        if (! $this->ready() || ! $policy) {
            return $result;
        }
        $id = $user instanceof User ? $user->id : $user;
        $available = 0;
        foreach (WorkforceAccountEntry::where('user_id', $id)->where('account', 'vacation')->where('unit', $policy->unit)->whereNull('source_entry_id')->where('quantity', '>', 0)->where('effective_on', '<=', $date)->get() as $credit) {
            $quantity = $credit->quantity + (int) WorkforceAccountEntry::where('source_entry_id', $credit->id)->where('effective_on', '<=', $date)->sum('quantity');
            $result['credited'] += $quantity;
            $taken = 0;
            foreach (VacationReservation::where('workforce_account_entry_id', $credit->id)->whereIn('status', ['reserved', 'approved'])->with('absence')->get() as $reservation) {
                $taken += $reservation->quantity;
                if ($reservation->status === 'reserved') {
                    $result['reserved'] += $reservation->quantity;
                } else {
                    $usedUntil = min($date, now(config('operations.display_timezone'))->toDateString());
                    $used = collect($reservation->snapshot['days'] ?? [])->where('date', '<=', $usedUntil)->sum('quantity');
                    $result['used'] += $used;
                    $result['approved'] += $reservation->quantity - $used;
                }
            }
            $remaining = max(0, $quantity - $taken);
            if ($credit->expires_on !== null && $credit->expires_on < $date) {
                $result['expired'] += $remaining;
            } else {
                $available += $remaining;
            }
        }
        $result['missing_absence_allocations'] = $this->unallocatedAbsences($user);
        $result['available'] = $result['missing_absence_allocations'] === [] ? $available : null;

        return $result;
    }

    /** Legacy users without configured contracts are not silently assigned a fabricated model. */
    public function planningIssues(Shift $shift, User $user, array $context = []): array
    {
        if (! $this->ready() || ! EmployeeWorkModel::where('user_id', $user->id)->where('status', 'active')->exists()) {
            return [];
        }
        $issues = [];
        $timezone = $this->effectiveModel($user, $shift->starts_at)?->timezone ?? $shift->timezone;
        $start = CarbonImmutable::instance($shift->starts_at)->setTimezone($timezone);
        $end = CarbonImmutable::instance($shift->ends_at)->setTimezone($timezone);
        $weeks = [];
        for ($date = $start->startOfDay(); $date < $end; $date = $date->addDay()) {
            $model = $this->effectiveModel($user, $date);
            if (! $model) {
                $issues[] = ['code' => 'contract_missing', 'message' => 'Arbeitsmodell fehlt am '.$date->toDateString().'.'];

                continue;
            }
            if ((int) ($model->daily_minutes[$date->dayOfWeekIso] ?? 0) === 0) {
                $issues[] = ['code' => 'contract_workday', 'message' => 'Kein Arbeitstag im Arbeitsmodell am '.$date->toDateString().'.'];
            }
            $windows = $model->work_windows[$date->dayOfWeekIso] ?? null;
            if ($windows !== null && $windows !== []) {
                $segmentStart = $start->max($date)->format('H:i');
                $segmentEnd = $end->min($date->addDay())->equalTo($date->addDay()) ? '24:00' : $end->min($date->addDay())->format('H:i');
                $covered = collect($windows)->contains(fn ($window) => $window['start'] <= $segmentStart && $window['end'] >= $segmentEnd);
                if (! $covered) {
                    $issues[] = ['code' => 'contract_window', 'message' => 'Dienst liegt außerhalb des Arbeitszeitfensters am '.$date->toDateString().'.'];
                }
            }
            if ($model->maximum_weekly_minutes !== null) {
                $key = $date->startOfWeek()->toDateString();
                $weeks[$key] = isset($weeks[$key]) ? min($weeks[$key], $model->maximum_weekly_minutes) : $model->maximum_weekly_minutes;
            }
        }
        foreach ($weeks as $week => $limit) {
            $from = CarbonImmutable::parse($week, $timezone);
            $until = $from->addWeek();
            $exclude = array_values(array_unique(array_filter([$shift->id, ...($context['exclude_shift_ids'] ?? [])], fn ($id) => $id !== null)));
            $existing = ShiftAssignment::blocking()->where('user_id', $user->id)->whereNotIn('shift_id', $exclude)->whereHas('shift', fn ($q) => $q->notCancelled()->during($from, $until))->with('shift')->get()->pluck('shift');
            $all = $existing->concat([$shift])->concat($context['additional_shifts'] ?? [])->unique(fn ($item) => $item->id ? 'shift:'.$item->id : 'object:'.spl_object_id($item));
            $minutes = 0;
            $unknownPause = false;
            foreach ($all as $duty) {
                $overlap = $this->overlapSeconds($duty->starts_at, $duty->ends_at, $from, $until);
                $duration = (int) $duty->starts_at->diffInSeconds($duty->ends_at);
                if ($overlap > 0 && $overlap < $duration && $duty->planned_break_minutes > 0) {
                    $unknownPause = true;
                }
                // A pause without a location cannot be distributed over a week boundary.
                $minutes += ($overlap === $duration ? max(0, $overlap - $duty->planned_break_minutes * 60) : $overlap) / 60;
            }
            if (Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants')) {
                $trainings = PersonnelTrainingParticipant::where('user_id', $user->id)->whereIn('status', ['confirmed', 'attended'])->whereNotIn('personnel_training_id', $context['exclude_training_ids'] ?? [])
                    ->whereHas('training', fn ($query) => $query->where('status', 'scheduled')->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc()))->with('training')->get();
                foreach ($trainings as $participant) {
                    $minutes += $this->overlapSeconds($participant->training->starts_at, $participant->training->ends_at, $from, $until) / 60;
                }
            }
            if ($unknownPause) {
                $issues[] = ['code' => 'contract_weekly_allocation_unknown', 'message' => 'Lage der Planpause am Wochenwechsel muss geprüft werden.'];
            }
            if ($minutes > $limit) {
                $issues[] = ['code' => 'contract_weekly_limit', 'message' => 'Freigegebenes Wochenlimit ab '.$week.' wird überschritten.'];
            }
        }

        return $issues;
    }

    private function effective(string $class, User|int $user, CarbonInterface|string $date)
    {
        if ($date instanceof CarbonInterface && $class !== EmployeeWorkModel::class) {
            $date = CarbonImmutable::instance($date)->setTimezone($this->effectiveModel($user, $date)?->timezone ?? config('operations.display_timezone'))->toDateString();
        }
        $day = $this->day($date)->toDateString();

        return $class::where('user_id', $user instanceof User ? $user->id : $user)->where('starts_on', '<=', $day)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))->orderByDesc('starts_on');
    }

    private function manage(User $actor, User|int $user): void
    {
        app(PersonnelScopeService::class)->authorize($actor, $user, 'employees.master-data.edit');
        abort_unless($this->ready(), 503, 'Personalmodelle sind noch nicht verfügbar.');
    }

    private function lockUser(User|int $user): void
    {
        User::lockForUpdate()->findOrFail($user instanceof User ? $user->id : $user);
    }

    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        $value = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        Validator::make(['date' => $value], ['date' => 'required|date_format:Y-m-d'])->validate();

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }

    private function units(mixed $amount, string $unit): int
    {
        $value = (float) $amount * ($unit === 'days' ? 100 : 1);
        $this->check(abs($value - round($value)) < 0.00001, 'Tage höchstens mit zwei Dezimalstellen; Minuten nur ganzzahlig.');

        return (int) round($value);
    }

    private function idempotentEntry(?string $key, array $payload): ?WorkforceAccountEntry
    {
        if ($key === null) {
            return null;
        }
        $entry = WorkforceAccountEntry::where('idempotency_key', $key)->first();
        if ($entry) {
            $this->check((int) $entry->user_id === $payload['user_id'] && ($entry->snapshot['request_hash'] ?? '') === $this->payloadHash($payload), 'Buchungsschlüssel wurde für andere Werte verwendet.');
        }

        return $entry;
    }

    private function payloadHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function wallMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    private function overlapSeconds(CarbonInterface $start, CarbonInterface $end, CarbonInterface $from, CarbonInterface $until): int
    {
        $left = CarbonImmutable::instance($start)->max($from);
        $right = CarbonImmutable::instance($end)->min($until);

        return $right->greaterThan($left) ? (int) $left->diffInSeconds($right) : 0;
    }

    private function creditedPeriodSeconds(WorkTimeEntry $entry, CarbonImmutable $from, CarbonImmutable $until): ?int
    {
        if ($entry->starts_at->greaterThanOrEqualTo($from) && $entry->ends_at->lessThanOrEqualTo($until)) {
            if (method_exists($entry, 'creditedSeconds')) {
                return $entry->creditedSeconds();
            }

            return ($entry->work_context ?? 'shift') === 'shift' && ! array_key_exists('valuation', $entry->plan_snapshot ?? []) ? $entry->netSeconds() : null;
        }
        $rates = $entry->plan_snapshot['valuation'] ?? null;
        $legacy = ! array_key_exists('valuation', $entry->plan_snapshot ?? []) && ($entry->work_context ?? 'shift') === 'shift';
        $activities = Schema::hasTable('work_time_activities') ? $entry->activities()->orderBy('starts_at')->get() : collect();
        if ($activities->isEmpty()) {
            if ((int) $entry->pause_seconds > 0) {
                return null;
            }
            $kind = ($entry->work_context ?? 'shift') === 'shift' ? 'work' : $entry->work_context;
            $rate = $legacy ? 10000 : ($rates[$kind] ?? null);

            return $rate === null ? null : (int) floor($this->overlapSeconds($entry->starts_at, $entry->ends_at, $from, $until) * $rate / 10000);
        }
        $seconds = 0;
        $cursor = $entry->starts_at;
        $pause = 0;
        foreach ($activities as $activity) {
            if ($activity->source === 'needs_review' || ! $activity->ends_at || ! $activity->starts_at->equalTo($cursor) || ! $activity->ends_at->greaterThan($activity->starts_at) || $activity->ends_at->greaterThan($entry->ends_at)) {
                return null;
            }
            $cursor = $activity->ends_at;
            if ($activity->kind === 'break') {
                $pause += (int) $activity->starts_at->diffInSeconds($activity->ends_at);
            }
            $overlap = $this->overlapSeconds($activity->starts_at, $activity->ends_at, $from, $until);
            $rate = $legacy ? ($activity->kind === 'break' ? 0 : 10000) : ($rates[$activity->kind] ?? null);
            if ($overlap > 0 && $rate === null) {
                return null;
            }
            $seconds += $overlap * ($rate ?? 0) / 10000;
        }

        return $cursor->equalTo($entry->ends_at) && $pause === (int) $entry->pause_seconds ? (int) floor($seconds) : null;
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workforce' => $message]);
        }
    }
}
