<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PlanVariant;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanVariantService
{
    private function access(User $actor): void
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
        WorkforcePlanningSchema::requireReady();
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }

    public function capture(array $shiftIds, string $name, int $copyDays, User $actor): PlanVariant
    {
        $this->access($actor);
        Validator::make(['ids' => $shiftIds, 'days' => $copyDays], ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer|distinct|exists:shifts,id', 'days' => 'integer|min:0|max:365'])->validate();

        return OperationsTransaction::run(function () use ($shiftIds, $name, $copyDays, $actor) {
            PlanningLocks::acquire($shiftIds, Shift::whereKey($shiftIds)->with('assignments')->get()->flatMap(fn ($shift) => $shift->assignments->pluck('user_id'))->all());
            $entries = Shift::whereKey($shiftIds)->with(['qualifications', 'assignments'])->orderBy('starts_at')->get()->map(function ($shift) use ($copyDays) {
                $start = $shift->starts_at->setTimezone($shift->timezone)->addDays($copyDays);
                $end = $shift->ends_at->setTimezone($shift->timezone)->addDays($copyDays);

                return ['shift_id' => $copyDays ? null : $shift->id, 'source_shift_id' => $copyDays ? $shift->id : null, 'order_id' => $shift->order_id, 'title' => $shift->title, 'role_name' => $shift->role_name, 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $end->format('Y-m-d\TH:i'), 'timezone' => $shift->timezone, 'location_name' => $shift->location_name, 'customer_location_id' => $shift->customer_location_id, 'required_staff' => $shift->required_staff, 'planned_break_minutes' => $shift->planned_break_minutes, 'qualification_ids' => $shift->qualifications->modelKeys(), 'user_ids' => $shift->assignments->filter(fn ($a) => $a->status->blocksAvailability())->pluck('user_id')->all(), 'transfer_buffer_minutes' => $shift->disposition_details['transfer_buffer_minutes'] ?? 0];
            })->all();
            $from = collect($entries)->min('starts_at');
            $until = collect($entries)->max('ends_at');

            return $this->save(null, null, ['name' => $name, 'from' => substr($from, 0, 10), 'until' => substr($until, 0, 10), 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'entries' => $entries], $actor);
        }, 3);
    }

    public function save(?int $id, ?int $revision, array $data, User $actor): PlanVariant
    {
        $this->access($actor);
        $data = $this->validate($data);
        $shiftIds = array_values(array_unique(array_merge($this->references($data['entries']), $id ? $this->references(PlanVariant::findOrFail($id)->entries) : [])));

        return OperationsTransaction::run(function () use ($id, $revision, $data, $shiftIds, $actor) {
            PlanningLocks::acquire($shiftIds, [], array_column($data['entries'], 'order_id'));
            $variant = $id ? PlanVariant::lockForUpdate()->findOrFail($id) : new PlanVariant;
            $this->check(! $id || ($variant->revision === $revision && $variant->status === 'draft'), 'Nur unveränderte Entwürfe können bearbeitet werden.');
            $this->check(! $id || $variant->baseline === $this->baseline($this->references($variant->entries)), 'Ausgangsplan wurde geändert. Variante neu erstellen.');
            $this->validateReferences($data['entries']);
            $baseline = $this->baseline($this->references($data['entries']));
            $variant->fill($data)->forceFill(['baseline' => $baseline, 'created_by' => $id ? $variant->created_by : $actor->id, 'revision' => $id ? $revision + 1 : 1])->save();
            app(OperationsAuditService::class)->record($variant, $actor, 'variant.saved');

            return $variant;
        }, 3);
    }

    private function validate(array $data): array
    {
        $data = Validator::make($data, [
            'name' => 'required|string|max:120', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'timezone' => 'required|timezone', 'comment' => 'nullable|string|max:1000',
            'entries' => 'required|array|min:1|max:100', 'entries.*.shift_id' => 'nullable|integer|distinct|exists:shifts,id', 'entries.*.source_shift_id' => 'nullable|integer|exists:shifts,id',
            'entries.*.order_id' => 'required|integer|exists:orders,id', 'entries.*.title' => 'required|string|max:255', 'entries.*.role_name' => 'required|string|max:160', 'entries.*.starts_at' => 'required|string', 'entries.*.ends_at' => 'required|string', 'entries.*.timezone' => 'required|timezone', 'entries.*.location_name' => 'nullable|string|max:160',
            'entries.*' => 'array:shift_id,source_shift_id,order_id,title,role_name,starts_at,ends_at,timezone,location_name,customer_location_id,required_staff,planned_break_minutes,qualification_ids,user_ids,transfer_buffer_minutes',
            'entries.*.customer_location_id' => 'nullable|integer|exists:customer_locations,id',
            'entries.*.required_staff' => 'required|integer|min:1|max:999', 'entries.*.planned_break_minutes' => 'required|integer|min:0|max:1439', 'entries.*.qualification_ids' => 'present|array|max:50', 'entries.*.qualification_ids.*' => 'integer|exists:qualification_types,id', 'entries.*.user_ids' => 'present|array|max:999', 'entries.*.user_ids.*' => 'integer|exists:users,id', 'entries.*.transfer_buffer_minutes' => 'nullable|integer|min:0|max:10080',
        ])->validate();
        $this->check(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['until'])) < 94, 'Planvariante darf höchstens 94 Tage umfassen.');
        foreach ($data['entries'] as $entry) {
            [$start, $end] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
            $this->check($start->setTimezone($data['timezone'])->toDateString() >= $data['from'] && $end->setTimezone($data['timezone'])->toDateString() <= $data['until'], 'Dienst liegt außerhalb der Planperiode.');
            $this->check(count($entry['user_ids']) <= $entry['required_staff'], 'Variante ist überbesetzt.');
            $this->check(count(array_unique($entry['user_ids'])) === count($entry['user_ids']) && count(array_unique($entry['qualification_ids'])) === count($entry['qualification_ids']), 'Mitarbeiter und Nachweisarten je Dienst nur einmal wählen.');
            $this->check($start->isFuture(), 'Variante enthält einen begonnenen Dienst.');
        }

        return $data;
    }

    private function references(array $entries): array
    {
        return array_values(array_unique(array_filter(array_merge(array_column($entries, 'shift_id'), array_column($entries, 'source_shift_id')))));
    }

    private function baseline(array $ids): array
    {
        return Shift::whereKey($ids)->with(['qualifications', 'assignments'])->orderBy('id')->get()->mapWithKeys(fn ($shift) => [(string) $shift->id => hash('sha256', json_encode([$shift->getRawOriginal(), $shift->qualifications->pluck('id')->sort()->values()->all(), $shift->assignments->sortBy('id')->map(fn ($a) => $a->getRawOriginal())->values()->all()], JSON_THROW_ON_ERROR))])->all();
    }

    private function validateReferences(array $entries): void
    {
        foreach ($entries as $entry) {
            $order = Order::findOrFail($entry['order_id']);
            [$start, $end] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
            $this->check($order->status->value !== 'cancelled' && $start->gte($order->starts_at) && $end->lte($order->ends_at), 'Variante muss im aktiven Auftragszeitraum liegen.');
            $this->check(QualificationType::whereKey($entry['qualification_ids'])->where('is_active', true)->count() === count($entry['qualification_ids']), 'Variante enthält deaktivierte Nachweisarten.');
            if (! empty($entry['shift_id'])) {
                $shift = Shift::findOrFail($entry['shift_id']);
                $this->check($shift->published_revision === 0 && $shift->status->value === 'draft', 'Veröffentlichte Dienste werden durch Varianten nicht überschrieben. Für Ergänzungen eine Kopie verwenden.');
                $this->check(! WorkTimeEntry::whereHas('assignment', fn ($q) => $q->where('shift_id', $shift->id))->exists(), 'Zeitbebuchte Dienste bleiben geschützt.');
            }
        }
    }

    /** Never writes Shift rows. Assess all proposed duties together, not isolated. */
    public function preview(PlanVariant $variant, User $actor, array $travelPreflights = [], bool $lock = false): array
    {
        $this->access($actor);
        $variant = PlanVariant::findOrFail($variant->id);
        $data = $variant->only(['name', 'timezone', 'comment', 'entries']) + ['from' => $variant->from->toDateString(), 'until' => $variant->until->toDateString()];
        $data = $this->validate($data);
        $this->check($variant->baseline === $this->baseline($this->references($variant->entries)), 'Ausgangsplan wurde geändert. Variante neu laden oder neu erstellen.');
        $this->validateReferences($data['entries']);
        $projections = collect($data['entries'])->map(function ($entry) {
            [$start, $end] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
            $shift = ! empty($entry['shift_id']) ? clone Shift::findOrFail($entry['shift_id']) : new Shift;
            $shift->fill(collect($entry)->except(['shift_id', 'source_shift_id', 'user_ids', 'qualification_ids', 'transfer_buffer_minutes', 'customer_location_id', 'location_snapshot'])->all() + $this->locationAttributes($entry));
            $shift->starts_at = $start;
            $shift->ends_at = $end;
            $shift->status = 'draft';
            $snapshotIds = class_exists(PlanningEnhancementService::class)
                ? app(PlanningEnhancementService::class)->mandatoryRequirementIds((int) ($entry['shift_id'] ?? $entry['source_shift_id'] ?? 0))
                : [];
            $requiredIds = array_values(array_unique(array_merge($entry['qualification_ids'], $snapshotIds)));
            $requirements = QualificationType::whereKey($requiredIds)->get();
            $this->check($requirements->count() === count($requiredIds), 'Eine zwingende Nachweisart des Anforderungssnapshots fehlt.');
            $shift->setRelation('qualifications', $requirements);
            $shift->disposition_details = array_merge($shift->disposition_details ?? [], ['transfer_buffer_minutes' => $entry['transfer_buffer_minutes'] ?? 0]);
            app(SiteSnapshotService::class)->prepare($shift);

            return ['shift' => $shift, 'user_ids' => $entry['user_ids']];
        });
        $exclude = array_values(array_filter(array_column($data['entries'], 'shift_id')));
        $rows = [];
        foreach ($projections as $index => $projection) {
            $shift = $projection['shift'];
            $issues = [];
            foreach ($projection['user_ids'] as $id) {
                $additional = $projections->filter(fn ($other, $key) => $key !== $index && in_array($id, $other['user_ids'], true))->pluck('shift')->all();
                $issues[$id] = app(StaffEligibilityService::class)->assessMany($shift, collect([User::findOrFail($id)]), $lock, ['exclude_shift_ids' => $exclude, 'additional_shifts' => $additional, 'travel_preflight' => $travelPreflights[$index][$id] ?? null])[$id];
            }
            // The same configured shift rules apply even if no person is selected yet.
            $global = OperationsRuleProfile::where('is_active', true)->first();
            $minutes = $shift->starts_at->diffInMinutes($shift->ends_at);
            $scheduleIssues = $projection['user_ids'] === [] && (! $global || $minutes > $global->maximum_shift_minutes || $shift->planned_break_minutes >= $minutes || ($minutes > $global->break_after_minutes && $shift->planned_break_minutes < $global->minimum_break_minutes)) ? ['Dauer oder Pause verletzt das freigegebene Regelprofil.'] : [];
            try {
                app(OrderDemandService::class)->assertPlannedCapacity($shift, ['exclude_shift_ids' => $exclude, 'additional_shifts' => $projections->filter(fn ($other, $key) => $key !== $index)->pluck('shift')->all()]);
            } catch (ValidationException $exception) {
                $scheduleIssues = array_merge($scheduleIssues, array_merge(...array_values($exception->errors())));
            }
            $rows[] = ['index' => $index, 'title' => $shift->title, 'starts_at' => $data['entries'][$index]['starts_at'], 'ends_at' => $data['entries'][$index]['ends_at'], 'user_ids' => $projection['user_ids'], 'issues' => $issues, 'schedule_issues' => $scheduleIssues, 'open' => $shift->required_staff - count($projection['user_ids'])];
        }
        $valid = collect($rows)->every(fn ($row) => $row['schedule_issues'] === [] && collect($row['issues'])->every(fn ($issues) => $issues === []));

        return ['rows' => $rows, 'valid' => $valid, 'fingerprint' => hash('sha256', json_encode([$variant->revision, $variant->baseline, $variant->entries, $rows], JSON_THROW_ON_ERROR))];
    }

    public function approve(int $id, int $revision, string $fingerprint, User $actor): void
    {
        $this->access($actor);
        $initial = PlanVariant::findOrFail($id);
        $travelPreflights = $this->routingPreflights($initial);
        OperationsTransaction::run(function () use ($id, $revision, $fingerprint, $actor, $initial, $travelPreflights) {
            PlanningLocks::acquire($this->references($initial->entries), collect($initial->entries)->pluck('user_ids')->flatten()->unique()->all(), array_column($initial->entries, 'order_id'));
            $variant = PlanVariant::lockForUpdate()->findOrFail($id);
            $this->check($variant->revision === $revision && $variant->status === 'draft', 'Variante wurde geändert.');
            $preview = $this->preview($variant, $actor, $travelPreflights, true);
            $this->check($preview['valid'] && hash_equals($preview['fingerprint'], $fingerprint), 'Vorschau wurde geändert oder enthält Konflikte.');
            $variant->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()->utc(), 'revision' => $revision + 1]);
            app(OperationsAuditService::class)->record($variant, $actor, 'variant.approved');
        }, 3);
    }

    public function apply(int $id, int $revision, User $actor): array
    {
        $this->access($actor);
        $initialVariant = PlanVariant::findOrFail($id);
        $travelPreflights = $this->routingPreflights($initialVariant);

        return OperationsTransaction::run(function () use ($id, $revision, $actor, $travelPreflights) {
            $initial = PlanVariant::findOrFail($id);
            $users = collect($initial->entries)->pluck('user_ids')->flatten()->merge(Shift::whereKey($this->references($initial->entries))->with('assignments')->get()->flatMap(fn ($s) => $s->assignments->pluck('user_id')))->unique()->all();
            PlanningLocks::acquire($this->references($initial->entries), $users, array_column($initial->entries, 'order_id'));
            $variant = PlanVariant::lockForUpdate()->findOrFail($id);
            $this->check($variant->revision === $revision && $variant->status === 'approved', 'Nur freigegebene unveränderte Varianten übernehmen.');
            $this->check($this->preview($variant, $actor, $travelPreflights, true)['valid'], 'Variante enthält neue Konflikte.');
            $assigner = app(ShiftAssignmentService::class);
            foreach ($variant->entries as $entry) {
                if (! empty($entry['shift_id'])) {
                    foreach (Shift::findOrFail($entry['shift_id'])->assignments()->blocking()->get() as $assignment) {
                        $assigner->cancel($assignment, $actor, 'Planvariante übernommen');
                    }
                }
            }
            $saved = [];
            $projected = collect($variant->entries)->map(fn ($entry) => $this->routingProjection($entry));
            $context = ['exclude_shift_ids' => array_values(array_filter(array_column($variant->entries, 'shift_id'))), 'additional_shifts' => $projected->all()];
            foreach ($variant->entries as $index => $entry) {
                $shift = ! empty($entry['shift_id']) ? Shift::findOrFail($entry['shift_id']) : new Shift;
                [$start, $end] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
                $attributes = collect($entry)->except(['shift_id', 'source_shift_id', 'qualification_ids', 'user_ids', 'transfer_buffer_minutes', 'customer_location_id', 'location_snapshot'])->all() + $this->locationAttributes($entry);
                $attributes['starts_at'] = $start;
                $attributes['ends_at'] = $end;
                $attributes['status'] = 'draft';
                $attributes['expected_revision'] = $shift->exists ? $shift->revision : null;
                $attributes['disposition_details'] = array_merge($shift->disposition_details ?? [], ['transfer_buffer_minutes' => $entry['transfer_buffer_minutes'] ?? 0]);
                $currentContext = $context;
                $currentContext['additional_shifts'] = $projected->filter(fn ($other) => ! $shift->id || $other->id !== $shift->id)->all();
                $shift = app(ShiftSchedulingService::class)->save($shift, $attributes, $actor, $currentContext);
                $shift->qualifications()->sync($entry['qualification_ids']);
                if (!empty($entry['source_shift_id']) && empty($entry['shift_id']) && class_exists(PlanningEnhancementService::class)) {
                    app(PlanningEnhancementService::class)->copySnapshots((int) $entry['source_shift_id'], $shift, $actor);
                }
                $saved[] = ['shift' => $shift, 'user_ids' => $entry['user_ids'], 'index' => $index];
            }
            foreach ($saved as $item) {
                foreach ($item['user_ids'] as $userId) {
                    $user = User::findOrFail($userId);
                    $additional = collect($saved)->filter(fn ($other) => $other['shift']->id !== $item['shift']->id && in_array($userId, $other['user_ids'], true))->pluck('shift')->all();
                    $travel = app(TravelAssessmentService::class)->rebasePreflight($item['shift'], $user, $travelPreflights[$item['index']][$userId], ['additional_shifts' => $additional]);
                    $assigner->assign($item['shift'], $user, $actor, ShiftAssignmentStatus::Requested, null, $item['shift']->revision, null, $travel);
                }
            }
            $variant->update(['status' => 'applied', 'applied_at' => now()->utc(), 'revision' => $revision + 1]);
            app(OperationsAuditService::class)->record($variant, $actor, 'variant.applied', ['shift_ids' => collect($saved)->pluck('shift.id')->all()]);

            return collect($saved)->pluck('shift.id')->all();
        }, 3);
    }

    private function locationAttributes(array $entry): array
    {
        if (! SiteSnapshotService::ready()) {
            return [];
        }
        $source = ! empty($entry['shift_id'] ?? $entry['source_shift_id'] ?? null)
            ? Shift::find($entry['shift_id'] ?? $entry['source_shift_id']) : null;
        $locationId = $entry['customer_location_id'] ?? $source?->customer_location_id;
        if ($source && (int) $source->order_id === (int) $entry['order_id'] && (int) $source->customer_location_id === (int) $locationId) {
            if ($source->location_name !== ($entry['location_name'] ?? null)) {
                return ['customer_location_id' => null, 'location_snapshot' => null];
            }

            return ['customer_location_id' => $source->customer_location_id, 'location_snapshot' => $source->location_snapshot];
        }

        return ['customer_location_id' => $locationId, 'location_snapshot' => null];
    }

    private function routingProjection(array $entry): Shift
    {
        $shift = ! empty($entry['shift_id']) ? clone Shift::findOrFail($entry['shift_id']) : new Shift;
        [$start, $end] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
        $shift->fill(collect($entry)->except(['shift_id', 'source_shift_id', 'user_ids', 'qualification_ids', 'transfer_buffer_minutes', 'customer_location_id', 'location_snapshot'])->all() + $this->locationAttributes($entry));
        $shift->starts_at = $start;
        $shift->ends_at = $end;
        $shift->status = 'draft';
        $shift->disposition_details = array_merge($shift->disposition_details ?? [], ['transfer_buffer_minutes' => $entry['transfer_buffer_minutes'] ?? 0]);
        app(SiteSnapshotService::class)->prepare($shift);

        return $shift;
    }

    private function routingPreflights(PlanVariant $variant): array
    {
        $projections = collect($variant->entries)->map(fn ($entry) => ['shift' => $this->routingProjection($entry), 'user_ids' => $entry['user_ids']]);
        $exclude = array_values(array_filter(array_column($variant->entries, 'shift_id')));
        $preflights = [];
        foreach ($projections as $index => $projection) {
            foreach ($projection['user_ids'] as $id) {
                $additional = $projections->filter(fn ($other, $key) => $key !== $index && in_array($id, $other['user_ids'], true))->pluck('shift')->all();
                $preflights[$index][$id] = app(TravelAssessmentService::class)->preflight($projection['shift'], collect([User::findOrFail($id)]), ['exclude_shift_ids' => $exclude, 'additional_shifts' => $additional]);
            }
        }

        return $preflights;
    }
}
