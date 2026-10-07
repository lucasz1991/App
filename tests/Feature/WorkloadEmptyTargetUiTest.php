<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class WorkloadEmptyTargetUiTest extends TestCase
{
    private function renderWorkload(array $overrides = []): string
    {
        return Livewire::test(WorkloadEmptyTargetPreview::class, ['projection' => $overrides + [
            'target' => null, 'ratio' => null, 'percent' => null, 'state' => 'unknown',
            'confirmed' => 120.0, 'requested' => 60.0, 'training' => 0.0, 'planned' => 180.0,
            'shift_count' => 2, 'absence_count' => 0, 'missing_days' => 7, 'clipped_breaks' => false,
        ]])->html();
    }

    private function trigger(string $html): string
    {
        $this->assertSame(1, preg_match('/<button\b[^>]*class="rt-timeline-workload"[^>]*>.*?<\/button>/s', $html, $match));

        return $match[0];
    }

    public function test_unknown_target_is_a_neutral_dash_without_a_misleading_ring(): void
    {
        $html = $this->renderWorkload();
        $trigger = $this->trigger($html);
        $this->assertStringContainsString('data-target="unknown"', $trigger);
        $this->assertStringContainsString('class="rt-timeline-workload__unknown" aria-hidden="true">–</span>', $trigger);
        $this->assertStringNotContainsString('<svg', $trigger);
        $this->assertStringNotContainsString('<circle', $trigger);
        $this->assertStringNotContainsString('0 %', $html);
        $this->assertStringContainsString('Auslastung QA Mitarbeiter: Auslastung ohne Sollquote. Details anzeigen', $trigger);
        $this->assertStringContainsString('Nicht vollständig hinterlegt', $html);
        $this->assertStringContainsString('Bestätigte Schichten', $html);
        $this->assertStringContainsString('3,0 h', $html);
    }

    public function test_known_zero_target_is_not_treated_as_missing_and_warns_when_planned(): void
    {
        $html = $this->renderWorkload(['target' => 0, 'state' => 'over', 'missing_days' => 0]);
        $trigger = $this->trigger($html);
        $this->assertStringContainsString('data-target="zero"', $trigger);
        $this->assertStringContainsString('data-state="over"', $trigger);
        $this->assertStringContainsString('<circle', $trigger);
        $this->assertStringContainsString('>!</span>', $trigger);
        $this->assertStringNotContainsString('rt-timeline-workload__unknown', $trigger);
        $this->assertStringContainsString('Kein Soll im Zeitraum', $html);
        $this->assertStringContainsString('0,0 h', $html);
        $this->assertStringNotContainsString('0 %', $html);
    }

    public function test_real_nonzero_target_preserves_ring_percentage_and_period_details(): void
    {
        $html = $this->renderWorkload(['target' => 360, 'ratio' => .5, 'percent' => 50, 'state' => 'available', 'missing_days' => 0]);
        $trigger = $this->trigger($html);
        $this->assertStringContainsString('data-target="known"', $trigger);
        $this->assertStringContainsString('stroke-dasharray="50 100"', $trigger);
        $this->assertStringContainsString('50 % verplant', $html);
        $this->assertStringContainsString('6,0 h', $html);
        $this->assertStringContainsString('Noch bis Soll', $html);
        $this->assertStringContainsString('12.05.2027', $html);
        $this->assertStringNotContainsString('rt-timeline-workload__unknown', $trigger);
    }

    public function test_unknown_target_retains_native_dropdown_and_compact_column_geometry(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/partials/timeline-workload.blade.php'));
        $css = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $this->assertStringContainsString(':open-on-hover="true"', $view);
        $this->assertStringContainsString(':hover-open-delay="180"', $view);
        $this->assertStringContainsString('content-role="dialog"', $view);
        $this->assertStringContainsString('data-rt-dropdown-keep-open', $view);
        $this->assertMatchesRegularExpression('/\.rt-timeline-workload\s*\{[^}]*width: 30px; height: 40px;/s', $css);
        $this->assertMatchesRegularExpression('/\.rt-timeline-workload\[data-target=\'unknown\'\]\s*\{ color: var\(--rt-muted, #[a-f0-9]+\); \}/', $css);
        $this->assertStringContainsString('.dark .rt-timeline-workload[data-target=\'unknown\'] { color: var(--rt-dark-muted, #a5b2c5); }', $css);
        $compact = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringContainsString('[data-personnel-compact=\'true\'] .rt-timeline-workload-anchor { display: none !important; }', $compact);
    }
}

class WorkloadEmptyTargetPreview extends Component
{
    public array $projection = [];

    public function render()
    {
        return view('livewire.operations.partials.timeline-workload', [
            'workload' => $this->projection,
            'person' => new User(['id' => 1, 'name' => 'QA Mitarbeiter']),
            'days' => collect([CarbonImmutable::parse('2027-05-12', 'Europe/Berlin')]),
        ]);
    }
}
