<?php

namespace Tests\Feature;

use App\Livewire\Operations\StaffTimeline;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelineCellStatusUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Disposition QA', 'role' => 'admin', 'status' => true]);
        User::factory()->create(['name' => 'Alpha QA', 'role' => 'staff', 'status' => true]);
        User::factory()->create(['name' => 'Beta QA', 'role' => 'staff', 'status' => true]);
    }

    private function timeline(array $options = [])
    {
        return Livewire::actingAs($this->manager)->test(StaffTimeline::class, $options + [
            'from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        return new DOMXPath($document);
    }

    private function declarations(string $styles, string $selector): string
    {
        $this->assertSame(1, preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/s', $styles, $matches));

        return $matches[1];
    }

    public function test_each_day_retains_a_full_cell_button_with_one_nonvisual_corner_status_icon(): void
    {
        $timeline = $this->timeline();
        $xpath = $this->xpath($timeline->html());
        $cells = $xpath->query('//button[@data-timeline-cell-action]');
        $this->assertSame(14, $cells->length);
        $statusIds = [];
        foreach ($cells as $cell) {
            $this->assertSame('button', $cell->getAttribute('type'));
            $this->assertSame('dialog', $cell->getAttribute('aria-haspopup'));
            $this->assertStringContainsString('Offene Schicht auswählen:', $cell->getAttribute('aria-label'));
            $this->assertNotEmpty($cell->getAttribute('data-user'));
            $this->assertMatchesRegularExpression('/^2027-05-1[0-6]$/', $cell->getAttribute('data-date'));
            $this->assertFalse($cell->hasAttribute('data-fit'));
            $iconContainer = $xpath->query('./span[@class="rt-timeline-cell-status"]', $cell);
            $this->assertSame(1, $iconContainer->length);
            $this->assertSame('true', $iconContainer->item(0)->getAttribute('aria-hidden'));
            $this->assertSame(1, $xpath->query('.//i', $cell)->length);
            $this->assertSame(1, $xpath->query('./i[@data-timeline-cell-status-icon]', $iconContainer->item(0))->length);
            $description = $xpath->query('./span[@data-timeline-cell-status-text]', $cell);
            $this->assertSame(1, $description->length);
            $this->assertSame('sr-only', $description->item(0)->getAttribute('class'));
            $this->assertSame('', $description->item(0)->textContent);
            $id = $description->item(0)->getAttribute('id');
            $this->assertSame($id, $cell->getAttribute('aria-describedby'));
            $this->assertStringContainsString('timeline-cell-status-'.$timeline->instance()->getId().'-', $id);
            $statusIds[] = $id;
        }
        $this->assertCount(14, array_unique($statusIds));
    }

    public function test_descriptions_remain_component_unique_and_no_hover_status_is_added_without_planning_permission(): void
    {
        $first = $this->xpath($this->timeline()->html());
        $second = $this->xpath($this->timeline()->html());
        $ids = fn (DOMXPath $xpath): array => array_map(fn ($node): string => $node->getAttribute('id'), iterator_to_array($xpath->query('//span[@data-timeline-cell-status-text]')));
        $this->assertCount(14, $ids($first));
        $this->assertSame([], array_intersect($ids($first), $ids($second)));
        $this->timeline(['planningEnabled' => false])->assertDontSee('data-timeline-cell-action', false)->assertDontSee('data-timeline-cell-status-icon', false)->assertDontSee('data-timeline-cell-status-text', false);
        $this->timeline(['absencesOnly' => true])->assertDontSee('data-timeline-cell-action', false)->assertDontSee('data-timeline-cell-status-icon', false);
    }

    public function test_the_visual_status_is_only_a_hidden_twenty_pixel_corner_without_a_whole_cell_badge(): void
    {
        $styles = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $target = $this->declarations($styles, '.rt-timeline-cell-action');
        foreach (['position: absolute', 'inset: 0', 'width: 100%', 'height: 100%', 'background: transparent'] as $declaration) {
            $this->assertStringContainsString($declaration, $target);
        }
        $status = $this->declarations($styles, '.rt-timeline-cell-status');
        foreach (['position: absolute', 'top: 4px', 'right: 4px', 'width: 20px', 'height: 20px', 'opacity: 0', 'pointer-events: none'] as $declaration) {
            $this->assertStringContainsString($declaration, $status);
        }
        foreach (['inset: 0', 'width: 100%', 'height: 100%', 'background:', 'box-shadow:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $status);
        }
        $this->assertStringContainsString('opacity:', $this->declarations($styles, '.rt-timeline-cell-action[data-fit] .rt-timeline-cell-status'));
        $this->assertStringNotContainsString('.rt-timeline-cell-action::before', $styles);
        $this->assertStringNotContainsString('.rt-timeline-cell-action::after', $styles);
        $this->assertStringNotContainsString('attr(data-fit-label)', $styles);
    }

    public function test_corner_states_keep_dark_mode_reduced_motion_and_the_existing_delegated_interactions(): void
    {
        $styles = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        foreach (['checking', 'suitable', 'blocked', 'empty', 'error'] as $state) {
            $this->assertStringContainsString(".rt-timeline-cell-action[data-fit='$state']", $styles);
        }
        foreach (['checking', 'suitable', 'empty'] as $state) {
            $this->assertStringContainsString(".dark .rt-timeline-cell-action[data-fit='$state']", $styles);
        }
        $this->assertStringContainsString('.dark .rt-timeline-cell-action[data-fit=\'blocked\'], .dark .rt-timeline-cell-action[data-fit=\'error\']', $styles);
        $this->assertStringContainsString('animation: rt-timeline-loading-spin', $styles);
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\.rt-timeline-cell-action\[data-fit=\'checking\'\] \.rt-timeline-cell-status > i\s*\{\s*animation:\s*none;/', $styles);
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        foreach (['x-on:click="openPlanner($event)"', 'x-on:pointerover="hoverCell($event)"', 'x-on:pointerout="leaveCell($event)"', 'x-on:focusin="hoverCell($event)"', 'x-on:focusout="leaveCell($event)"'] as $handler) {
            $this->assertSame(1, substr_count($view, $handler));
        }
        $this->assertStringContainsString('x-on:scroll.passive="syncHorizontal($event.target)"', $view);
        $this->assertStringNotContainsString('x-on:pointerover.prevent', $view);
        $this->assertStringNotContainsString('x-on:touchmove.prevent', $view);
    }
}
