<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\Operations\AiIntakeVisualContext;
use App\Support\Operations\TimelineMiniCalendar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiIntakeVisualContextTest extends TestCase
{
    private function demand(array $overrides = []): array
    {
        return array_replace(['starts_at' => '2026-10-13T16:00', 'ends_at' => '2026-10-13T18:00',
            'timezone' => 'Europe/Berlin', 'location_name' => 'Berlin'], $overrides);
    }

    public function test_uses_local_wall_time_and_an_existing_display_location_without_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $context = AiIntakeVisualContext::forDemand($this->demand());

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame('2026-10-13T16:00:00+02:00', $context['start']->toIso8601String());
        $this->assertSame('16:00–18:00', $context['timeLabel']);
        $this->assertStringContainsString('Europe/Berlin', $context['timeTitle']);
        $this->assertSame(15, $context['point']['zoom']);
        $this->assertStringContainsString('ungefähr', $context['mapLabel']);
        $days = TimelineMiniCalendar::forInterval($context['start'], $context['end'])['days'];
        $this->assertSame(['2026-10-13'], array_values(array_column(array_filter($days, fn ($day) => $day['selected']), 'date')));
        $this->assertSame(2, $context['start']->dayOfWeekIso);
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_missing_or_dst_ambiguous_dates_never_produce_a_calendar(array $overrides): void
    {
        $context = AiIntakeVisualContext::forDemand($this->demand($overrides));
        $this->assertNull($context['start']);
        $this->assertNull($context['end']);
        $this->assertNull($context['timeLabel']);
    }

    public static function invalidIntervals(): array
    {
        return [
            'missing start' => [['starts_at' => null]], 'malformed start' => [['starts_at' => ['bad']]],
            'invalid day' => [['starts_at' => '2026-02-30T16:00']],
            'reverse interval' => [['ends_at' => '2026-10-13T14:00']],
            'zero interval' => [['ends_at' => '2026-10-13T16:00']],
            'missing zone' => [['timezone' => null]], 'invalid zone' => [['timezone' => 'Not/AZone']],
            'spring gap' => [['starts_at' => '2026-03-29T02:30', 'ends_at' => '2026-03-29T04:00']],
            'autumn ambiguity' => [['starts_at' => '2026-10-25T02:30', 'ends_at' => '2026-10-25T04:00']],
        ];
    }

    public function test_overnight_and_month_crossing_preserve_the_full_interval(): void
    {
        $context = AiIntakeVisualContext::forDemand($this->demand(['starts_at' => '2026-10-31T22:00', 'ends_at' => '2026-11-02T00:00']));
        $this->assertSame('31.10.2026, 22:00 – 02.11.2026, 00:00', $context['timeLabel']);
        $calendar = TimelineMiniCalendar::forInterval($context['start'], $context['end']);
        $this->assertSame('31.10.2026 bis 01.11.2026', $calendar['range_label']);
        $this->assertSame(['2026-10-31', '2026-11-01'], array_values(array_column(array_filter($calendar['days'], fn ($day) => $day['selected']), 'date')));
    }

    public function test_non_berlin_timezone_remains_the_supplied_display_zone(): void
    {
        $context = AiIntakeVisualContext::forDemand($this->demand(['timezone' => 'America/New_York']));
        $this->assertSame('2026-10-13T16:00:00-04:00', $context['start']->toIso8601String());
        $this->assertSame('16:00–18:00', $context['timeLabel']);
        $this->assertStringContainsString('America/New_York', $context['timeTitle']);
    }

    public function test_unknown_or_multiple_places_and_payload_coordinates_never_invent_a_single_pin(): void
    {
        foreach (['Synthetischer Einsatzort', 'Hamburg Hohe Schaar', 'Berlin / München', '', ['Berlin']] as $location) {
            $context = AiIntakeVisualContext::forDemand($this->demand(['location_name' => $location, 'latitude' => 52.5, 'longitude' => 13.4]));
            $this->assertNull($context['point']);
        }
    }

    public function test_only_an_own_confirmed_matching_order_snapshot_is_used(): void
    {
        $order = new Order;
        $snapshot = ['name' => 'Synthetischer Einsatzort', 'confirmed' => true, 'latitude' => 53.5, 'longitude' => 10.0];
        $order->setRawAttributes(['location_name' => $snapshot['name'], 'location_snapshot' => json_encode($snapshot)]);
        $context = AiIntakeVisualContext::forDemand($this->demand(['location_name' => $snapshot['name']]), $order);
        $this->assertSame(['latitude' => 53.5, 'longitude' => 10.0, 'zoom' => 16, 'label' => $snapshot['name']], $context['point']);
        $this->assertSame('Bestätigter Einsatzstandort', $context['preview']['label']);
        $this->assertNull(AiIntakeVisualContext::forDemand($this->demand(['location_name' => 'Anderer Einsatzort']), $order)['point']);

        foreach ([['confirmed' => false], ['latitude' => 91], ['longitude' => 181], ['latitude' => 'invalid'], ['name' => 'Anderer Einsatzort']] as $change) {
            $order->setRawAttributes(['location_name' => $snapshot['name'], 'location_snapshot' => json_encode(array_replace($snapshot, $change))]);
            $this->assertNull(AiIntakeVisualContext::forDemand($this->demand(['location_name' => $snapshot['name']]), $order)['point']);
        }
    }

    public function test_unknown_visual_has_no_external_map_and_escapes_the_location(): void
    {
        $html = Blade::render('<x-operations.intake.visuals :demand="$demand" />', ['demand' => $this->demand(['location_name' => '<script>alert(1)</script>'])]);
        $this->assertStringContainsString('Kartenposition offen', $html);
        $this->assertStringNotContainsString('x-data="rtIntakeLocationMap', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('data-date="2026-10-13"', $html);
    }

    public function test_known_visual_exposes_precision_accessibly_and_keeps_readonly_map_key(): void
    {
        $html = Blade::render('<x-operations.intake.visuals :demand="$demand" identity="proposal-42" />', ['demand' => $this->demand()]);
        $this->assertStringContainsString('x-data="rtIntakeLocationMap', $html);
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringContainsString('stroke-dasharray="120 720" transform="rotate(30)"', $html);
        $this->assertStringContainsString('wire:key="intake-location-', $html);
        $this->assertStringContainsString('Berlin · Stadtmitte · ungefähr', $html);
        $this->assertStringContainsString('16:00–18:00', $html);
    }
}
