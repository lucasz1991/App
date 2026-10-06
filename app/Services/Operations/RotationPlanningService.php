<?php

namespace App\Services\Operations;

use App\Models\Order;
use App\Models\PlanningTeam;
use App\Models\PlanVariant;
use App\Models\QualificationType;
use App\Models\RotationCycle;
use App\Models\Shift;
use App\Models\ShiftBundleSnapshot;
use App\Models\User;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RotationPlanningService
{
    public function save(?int $id, ?int $revision, array $data, User $actor): RotationCycle
    {
        app(PlanningEnhancementService::class)->access($actor);
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'anchor' => 'required|date_format:Y-m-d', 'cycle_days' => 'required|integer|min:1|max:84', 'timezone' => 'required|timezone', 'slots' => 'required|array|min:1|max:50', 'slots.*.day' => 'required|integer|min:0|max:83', 'slots.*.shift_id' => 'required|integer|exists:shifts,id', 'slots.*.team_id' => 'nullable|integer|exists:planning_teams,id', 'slots.*.offset' => 'required|integer|min:-365|max:365', 'exceptions' => 'present|array|max:366', 'exceptions.*.date' => 'required|date_format:Y-m-d', 'exceptions.*.user_id' => 'nullable|integer|exists:users,id'])->validate();
        foreach ($data['slots'] as $slot) {
            if ($slot['day'] >= $data['cycle_days']) {
                throw ValidationException::withMessages(['workflow' => 'Zyklustag muss im Zyklus liegen.']);
            }
        }

        return OperationsTransaction::run(function () use ($id, $revision, $data, $actor) {
            app(PlanningEnhancementService::class)->access($actor);
            PlanningLocks::acquire(array_values(array_unique(array_column($data['slots'], 'shift_id'))));
            $cycle = $id ? RotationCycle::lockForUpdate()->findOrFail($id) : new RotationCycle;
            if ($id && $cycle->revision !== $revision) {
                throw ValidationException::withMessages(['workflow' => 'Rotation wurde geändert.']);
            }
            $slots = [];
            foreach ($data['slots'] as $slot) {
                $source = Shift::with('qualifications')->findOrFail($slot['shift_id']);
                $snapshotIds = ShiftBundleSnapshot::where('shift_id', $source->id)->get()->flatMap(fn ($snapshot) => collect($snapshot->requirements)->where('mandatory', true)->pluck('id'));
                $source->setRelation('qualifications', $source->qualifications->merge(QualificationType::whereKey($snapshotIds)->get())->unique('id'));
                $team = ! empty($slot['team_id']) ? PlanningTeam::findOrFail($slot['team_id']) : null;
                if ($team && ! $team->is_active) {
                    throw ValidationException::withMessages(['workflow' => 'Rotationsteam ist deaktiviert.']);
                }
                // Only editable cycle coordinates come from the client. Every template/snapshot field is reloaded.
                $slots[] = ['day' => (int) $slot['day'], 'shift_id' => $source->id, 'team_id' => $team?->id, 'offset' => (int) $slot['offset'], 'source_revision' => $source->revision, 'team_revision' => $team?->revision, 'template' => ['order_id' => $source->order_id, 'title' => $source->title, 'role_name' => $source->role_name, 'timezone' => $source->timezone, 'start_time' => $source->starts_at->setTimezone($source->timezone)->format('H:i'), 'end_time' => $source->ends_at->setTimezone($source->timezone)->format('H:i'), 'end_days' => (int) $source->starts_at->setTimezone($source->timezone)->startOfDay()->diffInDays($source->ends_at->setTimezone($source->timezone)->startOfDay()), 'location_name' => $source->location_name, 'required_staff' => $source->required_staff, 'planned_break_minutes' => $source->planned_break_minutes, 'qualification_ids' => $source->qualifications->modelKeys(), 'transfer_buffer_minutes' => $source->disposition_details['transfer_buffer_minutes'] ?? 0]];
            }
            $cycle->fill($data)->forceFill(['slots' => $slots, 'revision' => $id ? $revision + 1 : 1, 'created_by' => $id ? $cycle->created_by : $actor->id])->save();
            app(OperationsAuditService::class)->record($cycle, $actor, 'rotation.saved');

            return $cycle;
        }, 3);
    }

    /** Annual preview is read-only; application is explicitly sliced into existing <=94-day variants. */
    public function preview(int $id, int $revision, string $from, string $until, User $actor): array
    {
        app(PlanningEnhancementService::class)->access($actor);
        [$start,$end] = app(PlanningCapacityService::class)->range($from, $until, 366);
        $cycle = RotationCycle::findOrFail($id);
        if ($cycle->revision !== $revision) {
            throw ValidationException::withMessages(['workflow' => 'Rotation wurde geändert.']);
        }
        $anchor = CarbonImmutable::parse($cycle->anchor, $cycle->timezone);
        $entries = [];
        $rows = [];
        for ($date = $start; $date->lt($end); $date = $date->addDay()) {
            foreach ($cycle->slots as $slot) {
                $delta = (int) $anchor->diffInDays(CarbonImmutable::parse($date->toDateString(), $cycle->timezone), false) - (int) $slot['offset'];
                if (($delta % $cycle->cycle_days + $cycle->cycle_days) % $cycle->cycle_days !== (int) $slot['day']) {
                    continue;
                }
                $day = $date->toDateString();
                $exceptions = collect($cycle->exceptions)->where('date', $day);
                if ($exceptions->contains(fn ($e) => empty($e['user_id']))) {
                    continue;
                }
                $team = ! empty($slot['team_id']) ? PlanningTeam::find($slot['team_id']) : null;
                $issues = [];
                if (! empty($slot['team_id']) && (! $team || ! $team->is_active || $team->revision !== $slot['team_revision'])) {
                    $issues[] = 'Rotationsteam wurde geändert.';
                }
                $source = Shift::find($slot['shift_id']);
                if (! $source || $source->revision !== $slot['source_revision']) {
                    $issues[] = 'Vorlagendienst wurde geändert.';
                }
                $t = $slot['template'];
                $eDay = CarbonImmutable::parse($day, $t['timezone'])->addDays($t['end_days'])->toDateString();
                $entry = collect($t)->except(['start_time', 'end_time', 'end_days'])->all() + ['shift_id' => null, 'source_shift_id' => $source?->id, 'starts_at' => $day.'T'.$t['start_time'], 'ends_at' => $eDay.'T'.$t['end_time'], 'user_ids' => $team ? array_values(array_diff($team->user_ids, $exceptions->pluck('user_id')->filter()->all())) : []];
                try {
                    [$s,$e] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
                    if (! $s->isFuture()) {
                        $issues[] = 'Dienstbeginn liegt in der Vergangenheit.';
                    }
                    $order = Order::find($entry['order_id']);
                    if (! $order || $order->status->value === 'cancelled' || $s->lt($order->starts_at) || $e->gt($order->ends_at)) {
                        $issues[] = 'Dienst liegt außerhalb eines aktiven Auftrags.';
                    }
                } catch (ValidationException $exception) {
                    $issues = array_merge($issues, collect($exception->errors())->flatten()->all());
                }
                if (count($entry['user_ids']) > $entry['required_staff']) {
                    $issues[] = 'Rotationsteam überschreitet die Einsatzplätze.';
                }
                $entries[] = $entry;
                $rows[] = ['id' => count($entries), 'name' => $entry['title'], 'period' => $entry['starts_at'].' – '.$entry['ends_at'], 'people' => count($entry['user_ids']), 'issues' => $issues];
            }
        }
        abort_if(count($entries) > 1000, 422);
        // Assess every generated duty together, not just isolated template days.
        $projected = collect($entries)->map(function ($entry) {
            try {
                [$s,$e] = OperationsDateTime::interval($entry['starts_at'], $entry['ends_at'], $entry['timezone']);
            } catch (ValidationException) {
                return null;
            }
            $shift = new Shift;
            $shift->fill($entry);
            $shift->starts_at = $s;
            $shift->ends_at = $e;
            $shift->setRelation('qualifications', QualificationType::whereKey($entry['qualification_ids'])->get());

            return $shift;
        });
        foreach ($entries as $index => $entry) {
            if (! $projected[$index]) {
                continue;
            }
            foreach ($entry['user_ids'] as $userId) {
                $additional = $projected->filter(fn ($shift, $key) => $key !== $index && $shift && in_array($userId, $entries[$key]['user_ids'], true))->values()->all();
                $issues = app(StaffEligibilityService::class)->assessMany($projected[$index], collect([User::findOrFail($userId)]), false, ['additional_shifts' => $additional])[$userId];
                $rows[$index]['issues'] = array_merge($rows[$index]['issues'], array_column($issues, 'message'));
            }
        }

        return ['entries' => $entries, 'rows' => $rows, 'valid' => $entries !== [] && collect($rows)->every(fn ($row) => $row['issues'] === []), 'fingerprint' => hash('sha256', json_encode([$cycle->revision, $entries, $rows], JSON_THROW_ON_ERROR)), 'can_create' => count($entries) <= 100 && $start->diffInDays($end) <= 94];
    }

    public function createVariant(int $id, int $revision, string $from, string $until, string $fingerprint, User $actor): PlanVariant
    {
        return OperationsTransaction::run(function () use ($id, $revision, $from, $until, $fingerprint, $actor) {
            app(PlanningEnhancementService::class)->access($actor);
            $initial = RotationCycle::findOrFail($id);
            PlanningLocks::acquire(array_values(array_unique(array_column($initial->slots, 'shift_id'))), collect($initial->slots)->pluck('team_id')->filter()->flatMap(fn ($id) => PlanningTeam::findOrFail($id)->user_ids)->all());
            $cycle = RotationCycle::lockForUpdate()->findOrFail($id);
            $preview = $this->preview($id, $revision, $from, $until, $actor);
            if (! $preview['valid'] || ! $preview['can_create'] || ! hash_equals($preview['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['workflow' => 'Rotation enthält Konflikte oder muss in Teilzeiträume aufgeteilt werden.']);
            }
            $last = substr(collect($preview['entries'])->max('ends_at'), 0, 10);

            return app(PlanVariantService::class)->save(null, null, ['name' => $cycle->name, 'from' => $from, 'until' => max($until, $last), 'timezone' => $cycle->timezone, 'entries' => $preview['entries']], $actor)->fresh();
        }, 3);
    }
}
