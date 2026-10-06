<?php

namespace App\Services\CustomerPortal;

use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalSubmission;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\OperationsDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerPortalSeriesService
{
    public function preview(CustomerPortalIdentity $identity, int $customerId, array $input): array
    {
        app(CustomerPortalScope::class)->membership($identity, $customerId, 'requests.create');
        $data = Validator::make($input, ['from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date', 'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'required|integer|min:1|max:7|distinct',
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i', 'overnight' => 'required|boolean', 'timezone' => 'required|timezone', 'exceptions' => 'nullable|array|max:366', 'exceptions.*' => 'date_format:Y-m-d|distinct'])->validate();
        $from = CarbonImmutable::parse($data['from_date'], 'UTC');
        $to = CarbonImmutable::parse($data['to_date'], 'UTC');
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to_date' => 'Der Serienzeitraum darf höchstens 366 Tage umfassen.']);
        }
        foreach ($data['exceptions'] ?? [] as $date) {
            if ($date < $data['from_date'] || $date > $data['to_date']) {
                throw ValidationException::withMessages(['exceptions' => 'Eine Ausnahme liegt außerhalb der Serie.']);
            }
        }
        $rows = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! in_array($day->isoWeekday(), array_map('intval', $data['weekdays']), true) || in_array($day->toDateString(), $data['exceptions'] ?? [], true)) {
                continue;
            }
            $endDay = $data['overnight'] ? $day->addDay() : $day;
            $start = $day->format('Y-m-d').'T'.$data['start_time'];
            $end = $endDay->format('Y-m-d').'T'.$data['end_time'];
            OperationsDateTime::interval($start, $end, $data['timezone']);
            $rows[] = ['starts_at' => $start, 'ends_at' => $end, 'timezone' => $data['timezone']];
            if (count($rows) > 20) {
                throw ValidationException::withMessages(['to_date' => 'Eine Anfrage darf höchstens 20 Serientermine enthalten.']);
            }
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['weekdays' => 'Für diese Serie gibt es keine Termine.']);
        }

        return $rows;
    }

    /** A single intake transaction/UUID owns the full series, not one partial submission per date. */
    public function submit(CustomerPortalIdentity $identity, int $customerId, string $uuid, array $recurrence, array $input, array $attachments = []): CustomerPortalSubmission
    {
        $windows = $this->preview($identity, $customerId, $recurrence);
        $base = array_intersect_key($input, array_flip(['title', 'location_id', 'location_name', 'role_name', 'required_staff', 'condition_id', 'quantity', 'planned_break_minutes', 'train_reference', 'vehicle_reference', 'cost_center']));
        $payload = array_intersect_key($input, array_flip(['intent', 'reference', 'note', 'accept_conditions', 'accepted_terms_hash']));
        $payload['positions'] = array_map(fn ($window) => $base + $window, $windows);

        return app(CustomerPortalSubmissionService::class)->submit($identity, $customerId, $uuid, $payload, attachments: $attachments);
    }
}
