<?php

namespace App\Services\Operations;

use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\PlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShiftSeriesService
{
    public function saveTemplate(?int $id, ?int $revision, array $data, User $actor): ShiftTemplate
    {
        $this->access($actor);
        $data = Validator::make($data, [
            'name' => 'required|string|max:180', 'title' => 'required|string|max:180', 'role_name' => 'required|string|max:160',
            'timezone' => 'required|timezone', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i',
            'end_next_day' => 'required|boolean', 'required_staff' => 'required|integer|min:1|max:999',
            'planned_break_minutes' => 'required|integer|min:0|max:1439', 'location_name' => 'nullable|string|max:180',
            'qualification_ids' => 'array|max:50', 'qualification_ids.*' => 'integer|distinct|exists:qualification_types,id',
        ])->validate();

        return DB::transaction(function () use ($id, $revision, $data, $actor) {
            $template = $id ? ShiftTemplate::lockForUpdate()->findOrFail($id) : new ShiftTemplate;
            $this->check(! $id || $template->revision === $revision, 'Vorlage wurde geändert. Bitte neu laden.');
            $name = $data['name'];
            unset($data['name']);
            $template->fill(['name' => $name, 'definition' => $data, 'revision' => $id ? $revision + 1 : 1, 'created_by' => $id ? $template->created_by : $actor->id])->save();
            app(OperationsAuditService::class)->record($template, $actor, 'template.saved');

            return $template;
        }, 3);
    }

    public function preview(array $data, User $actor): array
    {
        $this->access($actor);
        $data = $this->validateRequest($data);
        $template = ShiftTemplate::findOrFail($data['template_id']);
        $order = Order::findOrFail($data['order_id']);
        $this->check($order->status->value !== 'cancelled', 'Auftrag ist storniert.');
        $definition = $template->definition;
        $profile = OperationsRuleProfile::where('is_active', true)->first();
        $this->check($profile !== null, 'Regelprofil fehlt.');
        $ids = $definition['qualification_ids'] ?? [];
        $this->check(QualificationType::whereIn('id', $ids)->where('is_active', true)->count() === count($ids), 'Eine Nachweisart der Vorlage ist nicht mehr aktiv.');
        $first = CarbonImmutable::parse($data['from'], $definition['timezone']);
        $last = CarbonImmutable::parse($data['until'], $definition['timezone']);
        $this->check($first->diffInDays($last) <= 366, 'Serienzeitraum auf höchstens ein Jahr begrenzen.');
        $rows = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $data['weekdays']) || in_array($day->toDateString(), $data['exceptions'], true)) {
                continue;
            }
            $error = null;
            $start = $end = null;
            try {
                [$start, $end] = OperationsDateTime::interval($day->toDateString().'T'.$definition['start_time'], ($definition['end_next_day'] ? $day->addDay() : $day)->toDateString().'T'.$definition['end_time'], $definition['timezone']);
                $minutes = $start->diffInMinutes($end);
                $this->check($start->gte($order->starts_at) && $end->lte($order->ends_at), 'Außerhalb des Auftragszeitraums.');
                $this->check($start->isFuture(), 'Dienstbeginn liegt in der Vergangenheit.');
                $this->check($minutes <= $profile->maximum_shift_minutes && $definition['planned_break_minutes'] < $minutes, 'Dauer oder Pause verletzt das Regelprofil.');
                $this->check($minutes <= $profile->break_after_minutes || $definition['planned_break_minutes'] >= $profile->minimum_break_minutes, 'Mindestpause unterschritten.');
            } catch (ValidationException $exception) {
                $error = collect($exception->errors())->flatten()->first();
            }
            $rows[] = ['id' => count($rows), 'date' => $day->toDateString(), 'starts_at' => $start?->toIso8601String(), 'ends_at' => $end?->toIso8601String(), 'error' => $error];
            $this->check(count($rows) <= 90, 'Höchstens 90 Dienste je Serie. Bitte Zeitraum verkürzen.');
        }
        $this->check(count($rows) > 0, 'Keine Termine nach Wochentagen und Ausnahmen.');

        return ['rows' => $rows, 'definition' => $definition, 'request' => $data,
            'fingerprint' => hash('sha256', json_encode([$data, $template->revision, $definition, $order->only(['updated_at', 'starts_at', 'ends_at', 'status']), $profile->id, $rows], JSON_THROW_ON_ERROR))];
    }

    public function generate(array $data, string $fingerprint, string $key, User $actor): ShiftSeries
    {
        $this->access($actor);
        $data = $this->validateRequest($data);
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($data, $fingerprint, $key, $actor) {
            // The order lock serializes retries and keeps its interval stable.
            Order::lockForUpdate()->findOrFail($data['order_id']);
            ShiftTemplate::lockForUpdate()->findOrFail($data['template_id']);
            if ($existing = ShiftSeries::where('request_key', $key)->first()) {
                $this->check($existing->created_by === $actor->id && $existing->definition['request'] === $data, 'Serienschlüssel wurde bereits verwendet.');

                return $existing;
            }
            $preview = $this->preview($data, $actor);
            $this->check(hash_equals($preview['fingerprint'], $fingerprint), 'Vorschau ist veraltet. Bitte neu prüfen.');
            $this->check(! collect($preview['rows'])->contains(fn ($row) => $row['error'] !== null), 'Bitte fehlerhafte Termine korrigieren oder als Ausnahme ausschließen.');
            $series = ShiftSeries::create(['request_key' => $key, 'order_id' => $data['order_id'], 'shift_template_id' => $data['template_id'], 'definition' => ['request' => $data, 'template' => $preview['definition']], 'fingerprint' => $fingerprint, 'created_by' => $actor->id]);
            foreach ($preview['rows'] as $row) {
                $definition = $preview['definition'];
                $shift = app(ShiftSchedulingService::class)->save(new Shift, [
                    'order_id' => $data['order_id'], 'title' => $definition['title'], 'role_name' => $definition['role_name'],
                    'timezone' => $definition['timezone'], 'starts_at' => CarbonImmutable::parse($row['starts_at']), 'ends_at' => CarbonImmutable::parse($row['ends_at']),
                    'location_name' => $definition['location_name'] ?? null, 'required_staff' => $definition['required_staff'],
                    'planned_break_minutes' => $definition['planned_break_minutes'], 'status' => 'draft',
                ], $actor);
                $shift->qualifications()->sync($definition['qualification_ids'] ?? []);
                $series->occurrences()->create(['service_date' => $row['date'], 'shift_id' => $shift->id]);
            }
            app(OperationsAuditService::class)->record($series, $actor, 'series.generated', ['count' => count($preview['rows'])]);

            return $series;
        }, 3);
    }

    private function validateRequest(array $data): array
    {
        $data = Validator::make($data, ['template_id' => 'required|integer|exists:shift_templates,id', 'order_id' => 'required|integer|exists:orders,id', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'integer|between:1,7|distinct', 'exceptions' => 'present|array|max:366', 'exceptions.*' => 'date_format:Y-m-d|distinct|after_or_equal:from|before_or_equal:until'])->validate();
        $data['template_id'] = (int) $data['template_id'];
        $data['order_id'] = (int) $data['order_id'];
        $data['weekdays'] = array_map('intval', $data['weekdays']);
        sort($data['weekdays']);
        sort($data['exceptions']);

        return $data;
    }

    private function access(User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        OperationsAccess::requireReady();
        PlanningSchema::requireReady();
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
