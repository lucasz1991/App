<?php

namespace Tests\Unit;

use App\Support\Operations\TimelineMiniCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TimelineMiniCalendarTest extends TestCase
{
    #[DataProvider('intervals')]
    public function test_marks_only_occupied_display_dates(string $start, string $end, array $selectedDates, string $range): void
    {
        $calendar = TimelineMiniCalendar::forInterval(CarbonImmutable::parse($start, 'Europe/Berlin'), CarbonImmutable::parse($end, 'Europe/Berlin'));
        $selected = array_values(array_filter($calendar['days'], fn (array $day): bool => $day['selected']));

        $this->assertSame($selectedDates, array_column($selected, 'date'));
        $this->assertSame($range, $calendar['range_label']);
        $this->assertSame([$selectedDates[0]], array_column(array_filter($selected, fn (array $day): bool => $day['start']), 'date'));
        $this->assertSame([end($selectedDates)], array_values(array_column(array_filter($selected, fn (array $day): bool => $day['end']), 'date')));
    }

    public static function intervals(): array
    {
        return [
            'same day' => ['2026-10-05 08:00', '2026-10-05 16:00', ['2026-10-05'], '05.10.2026'],
            'overnight' => ['2026-10-05 22:00', '2026-10-06 06:00', ['2026-10-05', '2026-10-06'], '05.10.2026 bis 06.10.2026'],
            'shift ends at midnight' => ['2026-10-05 18:00', '2026-10-06 00:00', ['2026-10-05'], '05.10.2026'],
            'all day exclusive end' => ['2026-10-05 00:00', '2026-10-07 00:00', ['2026-10-05', '2026-10-06'], '05.10.2026 bis 06.10.2026'],
            'month boundary' => ['2026-04-30 22:00', '2026-05-02 00:00', ['2026-04-30', '2026-05-01'], '30.04.2026 bis 01.05.2026'],
            'year boundary' => ['2026-12-31 22:00', '2027-01-02 00:00', ['2026-12-31', '2027-01-01'], '31.12.2026 bis 01.01.2027'],
            'leap day' => ['2024-02-28 22:00', '2024-03-01 00:00', ['2024-02-28', '2024-02-29'], '28.02.2024 bis 29.02.2024'],
            'spring daylight saving' => ['2026-03-28 22:00', '2026-03-30 00:00', ['2026-03-28', '2026-03-29'], '28.03.2026 bis 29.03.2026'],
            'autumn daylight saving' => ['2026-10-24 22:00', '2026-10-26 00:00', ['2026-10-24', '2026-10-25'], '24.10.2026 bis 25.10.2026'],
            'midnight plus microsecond belongs to next day' => ['2026-10-05 23:59:59', '2026-10-06 00:00:00.000001', ['2026-10-05', '2026-10-06'], '05.10.2026 bis 06.10.2026'],
        ];
    }

    #[DataProvider('months')]
    public function test_grid_is_monday_first_and_covers_the_complete_month(string $month, int $count, string $first, string $last): void
    {
        $start = CarbonImmutable::parse($month.'-15 08:00', 'Europe/Berlin');
        $calendar = TimelineMiniCalendar::forInterval($start, $start->addHour());
        $dates = array_column($calendar['days'], 'date');

        $this->assertCount($count, $dates);
        $this->assertSame($first, $dates[0]);
        $this->assertSame($last, $dates[$count - 1]);
        $this->assertCount($count, array_unique($dates));
        $this->assertSame(1, CarbonImmutable::parse($dates[0])->dayOfWeekIso);
        $this->assertSame(7, CarbonImmutable::parse($dates[$count - 1])->dayOfWeekIso);
        $this->assertCount($start->daysInMonth, array_filter($calendar['days'], fn (array $day): bool => ! $day['outside']));
    }

    public static function months(): array
    {
        return [
            'five weeks minimum' => ['2021-02', 35, '2021-02-01', '2021-03-07'],
            'five weeks' => ['2026-10', 35, '2026-09-28', '2026-11-01'],
            'six weeks' => ['2026-03', 42, '2026-02-23', '2026-04-05'],
        ];
    }

    public function test_uses_start_display_zone_without_mutating_inputs_or_their_locale(): void
    {
        $start = Carbon::parse('2026-10-31 23:30:00', 'Europe/Berlin')->locale('en');
        $end = Carbon::parse('2026-10-31 23:00:00', 'UTC')->locale('en');
        $before = [$start->format('c.u e'), $end->format('c.u e'), $start->locale(), $end->locale()];
        $calendar = TimelineMiniCalendar::forInterval($start, $end);

        $this->assertSame(['2026-10-31'], array_column(array_filter($calendar['days'], fn (array $day): bool => $day['selected']), 'date'));
        $this->assertSame('Oktober 2026', $calendar['month_label']);
        $this->assertSame($before, [$start->format('c.u e'), $end->format('c.u e'), $start->locale(), $end->locale()]);
    }

    public function test_long_range_keeps_one_month_and_describes_the_complete_span(): void
    {
        $calendar = TimelineMiniCalendar::forInterval(CarbonImmutable::parse('2026-12-30', 'Europe/Berlin'), CarbonImmutable::parse('2028-02-01', 'Europe/Berlin'));

        $this->assertCount(35, $calendar['days']);
        $this->assertSame('Kalender Dezember 2026. Markierter Zeitraum: 30.12.2026 bis 31.01.2028.', $calendar['accessible_label']);
        $this->assertSame(['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03'], array_column(array_filter($calendar['days'], fn (array $day): bool => $day['selected']), 'date'));
        $this->assertCount(0, array_filter($calendar['days'], fn (array $day): bool => $day['end']));
    }

    public function test_empty_or_reversed_intervals_do_not_invent_selected_dates(): void
    {
        $start = CarbonImmutable::parse('2026-10-05 08:00', 'Europe/Berlin');
        foreach ([$start, $start->subHour()] as $end) {
            $calendar = TimelineMiniCalendar::forInterval($start, $end);
            $this->assertCount(0, array_filter($calendar['days'], fn (array $day): bool => $day['selected'] || $day['start'] || $day['end']));
            $this->assertSame('Kein gültiger Zeitraum', $calendar['range_label']);
        }
    }

    public function test_component_is_read_only_accessible_and_does_not_label_unselected_dates_as_event_dates(): void
    {
        $start = CarbonImmutable::parse('2026-10-05', 'Europe/Berlin');
        $end = CarbonImmutable::parse('2026-10-07', 'Europe/Berlin');
        $html = Blade::render('<x-operations.timeline-mini-calendar :start="$start" :end="$end" />', compact('start', 'end'));
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $this->assertSame('Kalender Oktober 2026. Markierter Zeitraum: 05.10.2026 bis 06.10.2026.', $xpath->evaluate('string(//figure/@aria-label)'));
        $this->assertSame('img', $xpath->evaluate('string(//figure/@role)'));
        $this->assertSame(35, $xpath->query('//*[@data-date]')->length);
        $this->assertSame(2, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " is-selected ")]')->length);
        $this->assertSame(0, $xpath->query('//button | //a | //input | //*[@tabindex] | //*[@aria-current]')->length);
        $this->assertStringNotContainsString('07.10.2026', $html);
        $this->assertStringNotContainsString('28.09.2026', $html);
        $this->assertStringContainsString('is-outside', $html);
    }
}
