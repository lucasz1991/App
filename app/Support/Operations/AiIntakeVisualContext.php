<?php

namespace App\Support\Operations;

use App\Models\OperationInquiry;
use App\Models\Order;
use Throwable;

/** Read-only presentation of the proposal's own time and worksite, without lookups or geocoding. */
final class AiIntakeVisualContext
{
    public static function forDemand(array $demand, ?Order $order = null): array
    {
        $start = $end = null;
        $zone = is_string($demand['timezone'] ?? null) ? $demand['timezone'] : '';
        if (is_string($demand['starts_at'] ?? null) && is_string($demand['ends_at'] ?? null) && $zone !== '') {
            try {
                [$from, $until] = OperationsDateTime::interval($demand['starts_at'], $demand['ends_at'], $zone);
                $start = $from->setTimezone($zone);
                $end = $until->setTimezone($zone);
            } catch (Throwable) {
                // Unreviewed or ambiguous wall times must never turn into an invented date.
            }
        }

        $timeLabel = null;
        $timeTitle = null;
        if ($start !== null && $end !== null) {
            $timeLabel = $start->isSameDay($end)
                ? $start->format('H:i').'–'.$end->format('H:i')
                : $start->format('d.m.Y, H:i').' – '.$end->format('d.m.Y, H:i');
            $timeTitle = $start->format('d.m.Y, H:i').' – '.$end->format('d.m.Y, H:i').' · '.$zone;
        }

        $location = self::locationName($demand['location_name'] ?? null);
        $preview = ['state' => 'unknown', 'label' => 'Kartenposition offen'];
        if ($location !== null) {
            $snapshot = $order?->getAttribute('location_snapshot');
            // A later customer address or a different saved worksite cannot move this proposal.
            if (is_array($snapshot) && ($snapshot['confirmed'] ?? false) === true
                && self::sameLocation($location, $order?->getAttribute('location_name'))
                && self::sameLocation($location, $snapshot['name'] ?? null)
                && self::coordinates($snapshot['latitude'] ?? null, $snapshot['longitude'] ?? null)) {
                $preview = ['state' => 'located', 'label' => 'Bestätigter Einsatzstandort', 'confirmed' => true,
                    'place' => $location, 'latitude' => (float) $snapshot['latitude'], 'longitude' => (float) $snapshot['longitude']];
            } else {
                $inquiry = new OperationInquiry;
                $inquiry->setRawAttributes(['location_name' => $location]);
                $preview = TimelineLocationPreview::fromInquiry($inquiry);
            }
        }

        $point = null;
        if (($preview['state'] ?? null) === 'located'
            && self::coordinates($preview['latitude'] ?? null, $preview['longitude'] ?? null)) {
            $point = ['latitude' => (float) $preview['latitude'], 'longitude' => (float) $preview['longitude'],
                'zoom' => ($preview['confirmed'] ?? false) ? 16 : (($preview['detail'] ?? false) ? 14 : 11)];
        }

        return ['start' => $start, 'end' => $end, 'timeLabel' => $timeLabel, 'timeTitle' => $timeTitle,
            'location' => $location ?? 'Einsatzort offen', 'preview' => $preview, 'point' => $point,
            'mapLabel' => ($location ?? 'Einsatzort offen').' · '.($preview['label'] ?? 'Kartenposition offen')];
    }

    private static function locationName(mixed $value): ?string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= 200 && trim($value) !== ''
            ? trim($value) : null;
    }

    private static function sameLocation(string $location, mixed $other): bool
    {
        $other = self::locationName($other);

        return $other !== null && mb_strtolower($location, 'UTF-8') === mb_strtolower($other, 'UTF-8');
    }

    private static function coordinates(mixed $latitude, mixed $longitude): bool
    {
        return is_numeric($latitude) && is_numeric($longitude) && is_finite((float) $latitude) && is_finite((float) $longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180;
    }
}
