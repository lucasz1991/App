<?php

namespace Tests\Feature;

use App\Support\Operations\TimelineEventDetail;
use App\Support\Operations\TimelineMiniCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TimelineSharedEventDetailTest extends TestCase
{
    public function test_compact_facts_keep_exact_native_period_parent_and_workload_without_hidden_private_metadata(): void
    {
        [$event, $row] = $this->fixture();
        $facts = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed');
        $this->assertSame('22:00', $facts['start']['time']);
        $this->assertSame('06:00', $facts['end']['time']);
        $this->assertSame('05.10.2026', $facts['start']['date']);
        $this->assertSame('06.10.2026', $facts['end']['date']);
        $this->assertSame('München · RT-123', $facts['parent']);
        $this->assertSame(42, $facts['shiftId']);
        $this->assertNull($facts['absenceId']);
        $this->assertSame('Bestätigt', $facts['status']);
        $this->assertSame([['label' => 'Regelarbeitszeit', 'value' => '38,5 h/Woche'], ['label' => 'Eingeplant · KW 41', 'value' => '24,5 h']], $facts['workload']);
        $this->assertSame('Ali Ammar', $facts['person']['name']);
        $this->assertLessThan(2300, strlen(json_encode($facts)));
        $this->assertArrayNotHasKey('days', $facts['calendar']);
        foreach (['private@example.test', 'employee_location', 'latitude', 'longitude', 'assignments', 'qualifications'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($facts));
        }
    }

    public function test_single_calendar_seed_matches_existing_server_calendar_including_half_open_month_boundary(): void
    {
        [$event, $row] = $this->fixture();
        $event['start'] = CarbonImmutable::parse('2026-08-31 22:00', 'Europe/Berlin');
        $event['end'] = CarbonImmutable::parse('2026-09-01 00:00', 'Europe/Berlin');
        $facts = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed');
        $calendar = TimelineMiniCalendar::forInterval($event['start'], $event['end']);
        $this->assertSame($calendar['month_label'], $facts['calendar']['monthLabel']);
        $this->assertSame($calendar['accessible_label'], $facts['calendar']['accessibleLabel']);
        $this->assertSame($calendar['range_label'], $facts['calendar']['rangeLabel']);
        $this->assertSame($calendar['days'][0]['date'], $facts['calendar']['gridStart']);
        $this->assertSame(count($calendar['days']), $facts['calendar']['count']);
        $this->assertSame('2026-08-31', $facts['calendar']['last']);
        $this->assertSame(42, $facts['calendar']['count']);
    }

    public function test_absence_facts_keep_all_day_last_occupied_date_and_only_authorized_native_absence_action(): void
    {
        [$event, $row] = $this->fixture();
        $event = ['kind' => 'absence', 'title' => 'Urlaub', 'status' => 'Genehmigt', 'shift_id' => null, 'absence_id' => 7,
            'start' => CarbonImmutable::parse('2026-10-05 00:00', 'Europe/Berlin'), 'end' => CarbonImmutable::parse('2026-10-07 00:00', 'Europe/Berlin'),
            'iso_week' => '2026-41', 'visible_start' => $event['visible_start']];
        $facts = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', true, 'approved');
        $this->assertTrue($facts['allDay']);
        $this->assertSame('05.10.2026 – 06.10.2026', $facts['allDayLabel']);
        $this->assertSame('2026-10-06', $facts['calendar']['last']);
        $this->assertSame(7, $facts['absenceId']);
        $this->assertSame([], $facts['workload']);
        $this->assertSame([], $facts['metadata']);
        $this->assertNull(TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'approved')['absenceId']);
    }

    public function test_dst_facts_preserve_offsets_and_actual_elapsed_time(): void
    {
        [$event, $row] = $this->fixture();
        $event['start'] = CarbonImmutable::parse('2026-10-24 22:00', 'Europe/Berlin');
        $event['end'] = CarbonImmutable::parse('2026-10-25 06:00', 'Europe/Berlin');
        $facts = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed');
        $this->assertTrue($facts['dstChanged']);
        $this->assertSame('+02:00', $facts['start']['offset']);
        $this->assertSame('+01:00', $facts['end']['offset']);
        $this->assertSame('9,0', $facts['elapsedHours']);
    }

    public function test_map_seed_preserves_confirmed_and_unknown_states_without_transmitting_coordinates(): void
    {
        [$event, $row] = $this->fixture();
        $event['location_preview'] = ['state' => 'located', 'label' => 'Bestätigter Einsatzort', 'place' => 'München', 'confirmed' => true, 'latitude' => 48.14, 'longitude' => 11.57];
        $map = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed')['map'];
        $this->assertSame(['left', 'top'], array_keys($map['point']));
        $this->assertStringContainsString('bestätigter Einsatzstandort.', $map['accessibleLabel']);
        $event['location_preview'] = ['state' => 'ambiguous', 'label' => 'Ort nicht eindeutig'];
        $map = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed')['map'];
        $this->assertNull($map['point']);
        $this->assertSame('ambiguous', $map['state']);
        $this->assertSame('Deutschland: Ort nicht eindeutig', $map['accessibleLabel']);
    }

    public function test_fact_json_is_escaped_as_attribute_and_all_dynamic_display_stays_text_only(): void
    {
        [$event, $row] = $this->fixture();
        $event['title'] = '<img src=x onerror="alert(1)"> & "Schicht"';
        $facts = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed');
        $html = Blade::render('<button data-timeline-detail="{{ json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}"></button>', compact('facts'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $this->assertSame(0, $dom->getElementsByTagName('img')->length);
        $this->assertSame($event['title'], json_decode($dom->getElementsByTagName('button')[0]->getAttribute('data-timeline-detail'), true)['title']);
        $view = file_get_contents(resource_path('views/livewire/operations/partials/timeline-shared-event-detail.blade.php'));
        $this->assertStringNotContainsString('x-html', $view);
        $this->assertStringContainsString('x-text="detail.title"', $view);
        $this->assertStringContainsString('<template x-if="open && detail">', $view);
        $this->assertStringContainsString(':external-trigger="true"', $view);
        $this->assertStringContainsString('layer-group="staff-timeline-events"', $view);
    }

    public function test_multiple_named_sites_keep_both_map_points_in_the_popover_and_mini_map(): void
    {
        [$event, $row] = $this->fixture();
        $event['location_name'] = 'Eidelstedt/Maschen';
        $preview = [
            'state' => 'multiple', 'label' => 'Mehrere genannte Orte · ungefähr', 'place' => 'Hamburg-Eidelstedt / Maschen',
            'points' => [
                ['state' => 'located', 'label' => 'Bahnhofslage · ungefähr', 'place' => 'Hamburg-Eidelstedt', 'latitude' => 53.596, 'longitude' => 9.906],
                ['state' => 'located', 'label' => 'Bahnhofslage · ungefähr', 'place' => 'Maschen', 'latitude' => 53.405, 'longitude' => 10.049],
            ],
        ];
        $event['location_preview'] = $preview;
        $map = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed')['map'];
        $this->assertNull($map['point'], 'A compound location must not invent a single primary point.');
        $this->assertCount(2, $map['points']);
        $this->assertNotSame($map['points'][0]['top'], $map['points'][1]['top']);
        $this->assertSame(['Hamburg-Eidelstedt', 'Maschen'], array_column($map['points'], 'title'));
        $this->assertStringNotContainsString('latitude', json_encode($map));
        $this->assertStringNotContainsString('longitude', json_encode($map));
        $this->assertStringContainsString('Hamburg-Eidelstedt / Maschen', $map['accessibleLabel']);
        $html = Blade::render('<x-operations.timeline-mini-map :preview="$preview" location="Eidelstedt/Maschen" />', compact('preview'));
        $this->assertSame(2, substr_count($html, 'data-location-marker'));
        $this->assertStringContainsString('data-map-state="multiple"', $html);
        $this->assertStringContainsString('Mehrere genannte Orte · ungefähr', $html);
        $this->assertStringContainsString('title="Hamburg-Eidelstedt"', $html);
        $this->assertStringContainsString('title="Maschen"', $html);
        $source = file_get_contents(resource_path('views/livewire/operations/partials/timeline-shared-event-detail.blade.php'));
        $this->assertStringContainsString('x-for="(point, pointIndex) in (detail.map.points', $source);
        $this->assertStringContainsString(':key="pointIndex"', $source);
    }

    public function test_a_ranked_location_proposal_is_visible_but_never_presented_as_confirmed(): void
    {
        [$event, $row] = $this->fixture();
        $preview = ['state' => 'located', 'label' => 'Ortsvorschlag · ungefähr', 'place' => 'Sulzdorf', 'latitude' => 49.105, 'longitude' => 9.854, 'estimated' => true];
        $event['location_preview'] = $preview;
        $map = TimelineEventDetail::forEvent($event, $row, 'Europe/Berlin', false, 'confirmed')['map'];
        $this->assertCount(1, $map['points']);
        $this->assertNotNull($map['point']);
        $this->assertSame('Ortsvorschlag · ungefähr', $map['precision']);
        $this->assertStringContainsString('keine bestätigte Einsatzadresse', $map['accessibleLabel']);
        $html = Blade::render('<x-operations.timeline-mini-map :preview="$preview" location="Sulzdorf" />', compact('preview'));
        $this->assertSame(1, substr_count($html, 'data-location-marker'));
        $this->assertStringContainsString('Ortsvorschlag · ungefähr', $html);
        $this->assertStringNotContainsString('bestätigter Einsatzstandort.', $html);
    }

    public function test_timeline_renders_one_shared_surface_and_only_compact_facts_per_badge(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertSame(1, substr_count($view, "@include('livewire.operations.partials.timeline-shared-event-detail')"));
        $this->assertStringNotContainsString("@include('livewire.operations.partials.timeline-event-detail')", $view);
        $this->assertStringContainsString('TimelineEventDetail::forEvent($event, $row, $zone, $absencesOnly, $state)', $view);
        $this->assertStringContainsString('data-timeline-detail="{{ json_encode($detailFacts', $view);
        $this->assertStringContainsString("rt-timeline-event-preview', { anchor: \$el }", $view);
        $this->assertStringContainsString('wire:key="staff-event-{{ $eventKey }}"', $view);
        $controller = file_get_contents(resource_path('js/timeline-event-details.js'));
        $this->assertStringNotContainsString('$wire', $controller);
        $this->assertStringNotContainsString('fetch(', $controller);
        $this->assertStringContainsString('rt-anchor-dropdown-focus', $controller);
        $this->assertStringContainsString('attributeFilter: [\'data-timeline-detail\']', $controller);
    }

    private function fixture(): array
    {
        $start = CarbonImmutable::parse('2026-10-05 22:00', 'Europe/Berlin');
        $event = ['kind' => 'shift', 'title' => 'München · Nachtdienst', 'start' => $start,
            'end' => CarbonImmutable::parse('2026-10-06 06:00', 'Europe/Berlin'), 'visible_start' => $start,
            'status' => 'Bestätigt', 'shift_id' => 42, 'absence_id' => null, 'iso_week' => '2026-41',
            'order_title' => 'München', 'order_number' => 'RT-123', 'detail' => 'Kunde', 'role_name' => 'Lokführer',
            'location_name' => 'München', 'shift_status_label' => 'Geplant', 'planned_break_minutes' => 30,
            'location_preview' => ['state' => 'unknown', 'label' => 'Standort nicht verortet']];
        $row = ['user' => (object) ['name' => 'Ali Ammar', 'profile_photo_url' => '/avatar.png', 'email' => 'private@example.test'],
            'weekly_working_hours' => 38.5, 'planned_hours_by_week' => ['2026-41' => 24.5]];

        return [$event, $row];
    }
}
