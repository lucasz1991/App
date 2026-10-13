<?php

namespace Tests\Unit;

use App\Enums\ShiftAssignmentStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\Operations\GermanyMap;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GermanyMapTest extends TestCase
{
    private function shift(string $location, ?array $snapshot = null, int $days = 1, int $required = 1, array $assignments = []): Shift
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDays($days)->setTime(6, 0);
        $shift = new Shift(['location_name' => $location, 'location_snapshot' => $snapshot, 'starts_at' => $start, 'ends_at' => $start->addHours(8), 'required_staff' => $required, 'status' => 'open']);
        $shift->setRelation('assignments', collect($assignments)->map(fn (string $status) => new ShiftAssignment(['status' => ShiftAssignmentStatus::from($status)])));

        return $shift;
    }

    private function duty(Shift $shift): object
    {
        return (object) ['shift' => $shift];
    }

    public function test_points_use_the_shared_location_resolver_and_count_unplaced_duties(): void
    {
        $map = GermanyMap::personal(collect([$this->duty($this->shift('Hamburg-Billwerder')), $this->duty($this->shift('Irgendwo Gbf', null, 2))]), 'Europe/Berlin');

        $this->assertSame([2, 1], [$map['total'], $map['unplaced']]);
        $this->assertSame('Hamburg-Billwerder', $map['points'][0]['label']);
        $this->assertTrue($map['points'][0]['next']);
        // Employees see a rounded locality area, including refined Hamburg districts.
        $preview = TimelineLocationPreview::fromShift($this->shift('Hamburg-Billwerder'));
        $coarse = GermanyMap::fromPreview($preview, true);
        $this->assertSame([$coarse['left'], $coarse['top']], [$map['points'][0]['left'], $map['points'][0]['top']]);
        $this->assertNotSame(GermanyMap::fromPreview($preview)['left'], $coarse['left']);
    }

    public function test_preview_projection_matches_the_resolver_and_employee_points_are_coarse(): void
    {
        $preview = TimelineLocationPreview::fromShift($this->shift('Nürnberg Hafen'));
        $this->assertSame('located', $preview['state']);
        $point = GermanyMap::fromPreview($preview);
        $this->assertSame('Nürnberg', $point['label']);
        $this->assertNull(GermanyMap::fromPreview(['state' => 'ambiguous', 'label' => 'Ort nicht eindeutig']));

        $snapshot = ['confirmed' => true, 'name' => 'Werkstor 3', 'latitude' => 53.08321, 'longitude' => 8.80177];
        $map = GermanyMap::personal(collect([$this->duty($this->shift('Kundenstandort', $snapshot))]), 'Europe/Berlin');
        $this->assertSame('Werkstor 3', $map['points'][0]['label']);
        $this->assertSame(['left', 'top', 'label', 'count', 'next', 'date'], array_keys($map['points'][0]));
        $this->assertStringNotContainsString('53.08', json_encode($map));
    }

    public function test_planning_groups_by_resolved_place_and_takes_the_most_urgent_staffing_tone(): void
    {
        $map = GermanyMap::planning(collect([
            $this->shift('Hamburg-Billwerder', null, 1, 2, ['confirmed', 'confirmed']),
            $this->shift('Hasselbrook', null, 2, 2, ['confirmed']),
            $this->shift('Hamburg-Hasselbrook', null, 3, 1, ['confirmed']),
            $this->shift('München Nord', null, 3, 1, ['requested']),
            $this->shift('Irgendwo Gbf'),
        ]));

        $this->assertSame([5, 1], [$map['total'], $map['unplaced']]);
        $this->assertSame(['Hamburg-Hasselbrook', 'Hamburg-Billwerder', 'München'], array_column($map['points'], 'label'));
        $this->assertSame([2, 1, 'open'], [$map['points'][0]['count'], $map['points'][0]['open'], $map['points'][0]['tone']]);
        $this->assertSame(['Bahnhofslage · ungefähr'], $map['points'][0]['precisionLabels']);
        $this->assertSame([1, 0, 'staffed'], [$map['points'][1]['count'], $map['points'][1]['open'], $map['points'][1]['tone']]);
        $this->assertSame([1, 0, 'awaiting'], [$map['points'][2]['count'], $map['points'][2]['open'], $map['points'][2]['tone']]);
        foreach ($map['points'] as $point) {
            $this->assertSame($point['left'].'|'.$point['top'], $point['key']);
        }
        $html = view('livewire.admin.operations.partials.shift-plan-map', ['planMap' => $map])->render();
        $this->assertStringContainsString('Bahnhofslage · ungefähr', $html);
    }

    public function test_planner_hover_keys_follow_coordinate_groups_even_when_labels_are_identical(): void
    {
        $snapshot = ['confirmed' => true, 'name' => 'Werkstor "Nord"', 'latitude' => 53.08321, 'longitude' => 8.80177];
        $map = GermanyMap::planning(collect([
            $this->shift('Werkstor', $snapshot),
            $this->shift('Werkstor', $snapshot, 2),
            $this->shift('Werkstor', array_replace($snapshot, ['longitude' => 10.80177])),
            $this->shift('Unbekannter Einsatzort'),
        ]));
        $this->assertCount(2, $map['points']);
        $this->assertSame(1, $map['unplaced']);
        $this->assertSame([2, 1], array_column($map['points'], 'count'));
        $this->assertSame(['Werkstor "Nord"', 'Werkstor "Nord"'], array_column($map['points'], 'label'));
        $this->assertNotSame($map['points'][0]['key'], $map['points'][1]['key']);
        $html = view('livewire.admin.operations.partials.shift-plan-map', ['planMap' => $map])->render();
        $xpath = $this->mapXpath($html);
        $buttons = $xpath->query('//button[@data-map-highlight-key]');
        $this->assertSame(2, $buttons->length);
        $this->assertSame(1, $xpath->query('//section[@x-data="rtMapHighlight"]')->length);
        foreach ($buttons as $index => $button) {
            $this->assertSame($map['points'][$index]['key'], $button->getAttribute('data-map-highlight-key'));
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertSame('Werkstor "Nord"', $button->getAttribute('data-place'));
            $this->assertSame('$wire.showLocation($el.dataset.place, $el.dataset.mapHighlightKey)', $button->getAttribute('x-on:click'));
            $this->assertSame("if (\$event.pointerType !== 'touch') mapHover(\$el.dataset.mapHighlightKey)", $button->getAttribute('x-on:pointerenter'));
            $this->assertSame('mapUnhover($el.dataset.mapHighlightKey)', $button->getAttribute('x-on:pointerleave'));
            $this->assertSame('mapUnhover($el.dataset.mapHighlightKey)', $button->getAttribute('x-on:pointercancel'));
            $this->assertSame('mapFocus($el.dataset.mapHighlightKey)', $button->getAttribute('x-on:focus'));
            $this->assertSame('mapBlur($el.dataset.mapHighlightKey)', $button->getAttribute('x-on:blur'));
            $this->assertSame('shift-map-place-'.md5($map['points'][$index]['key']), $button->parentNode->getAttribute('wire:key'));
            $this->assertSame(1, $xpath->query('//*[@data-map-highlight="'.$map['points'][$index]['key'].'"]')->length);
        }
        $this->assertStringNotContainsString('Unbekannter Einsatzort', $html);
        $this->assertStringContainsString('1 Schicht hat keinen eindeutig verorteten Einsatzort.', $html);
    }

    public function test_planner_map_scope_resets_after_period_filter_or_point_changes(): void
    {
        $map = GermanyMap::planning(collect([$this->shift('Hamburg-Hasselbrook')]));
        $scope = function (array $data): string {
            $xpath = $this->mapXpath(view('livewire.admin.operations.partials.shift-plan-map', $data)->render());
            $section = $xpath->query('//section[@data-shift-plan-map]')->item(0);
            $this->assertSame('mapReset()', $section->getAttribute('x-on:shift-plan-view-changed.window'));
            $this->assertSame('shift-plan-map-'.$section->getAttribute('data-map-scope'), $section->getAttribute('wire:key'));

            return $section->getAttribute('wire:key');
        };
        $data = ['planMap' => $map, 'rangeFrom' => '2026-10-13', 'rangeTo' => '2026-10-19', 'search' => '', 'statusFilter' => 'all'];
        $initial = $scope($data);
        $this->assertSame($initial, $scope($data));
        foreach ([['rangeFrom' => '2026-10-14'], ['rangeTo' => '2026-10-20'], ['search' => 'Hamburg'], ['statusFilter' => 'open'], ['customerFilter' => '42'], ['orderFilter' => '9'], ['attentionFilter' => 'open']] as $changes) {
            $this->assertNotSame($initial, $scope(array_replace($data, $changes)));
        }
        $changed = $map;
        $changed['points'][0]['count']++;
        $this->assertNotSame($initial, $scope(array_replace($data, ['planMap' => $changed])));
        $empty = GermanyMap::planning(collect([$this->shift('Unbekannter Einsatzort')]));
        $this->assertNotSame($initial, $scope(array_replace($data, ['planMap' => $empty])));
        $xpath = $this->mapXpath(view('livewire.admin.operations.partials.shift-plan-map', ['planMap' => $empty])->render());
        $this->assertSame(0, $xpath->query('//*[@data-map-highlight or @data-map-highlight-key]')->length);
    }

    private function mapXpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    public function test_multiple_previews_keep_distinct_places_without_inventing_a_single_coordinate(): void
    {
        $hamburg = TimelineLocationPreview::fromShift($this->shift('Hamburg'));
        $berlin = TimelineLocationPreview::fromShift($this->shift('Berlin'));
        $preview = ['state' => 'multiple', 'label' => 'Mehrere Orte · ungefähr', 'points' => [$hamburg, $berlin, $hamburg, ['state' => 'unknown']]];
        $this->assertNull(GermanyMap::fromPreview($preview));
        $points = GermanyMap::pointsFromPreview($preview);
        $this->assertCount(2, $points);
        $this->assertSame(['Hamburg', 'Berlin'], array_column($points, 'label'));
        $this->assertSame(GermanyMap::fromPreview($hamburg), $points[0]);
        $this->assertSame(GermanyMap::fromPreview($berlin, true), GermanyMap::pointsFromPreview($preview, true)[1]);
        $nearby = array_replace($hamburg, ['latitude' => $hamburg['latitude'] + 0.0001, 'longitude' => $hamburg['longitude'] + 0.0001]);
        $nearbyPreview = ['state' => 'multiple', 'points' => [$hamburg, $nearby]];
        $this->assertCount(2, GermanyMap::pointsFromPreview($nearbyPreview));
        $this->assertCount(1, GermanyMap::pointsFromPreview($nearbyPreview, true));
    }
}
