<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DispositionSharedSelectTest extends TestCase
{
    public function test_select_variants_preserve_counts_selection_and_safe_action_binding(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.forms.select id="compact-status" variant="compact" value="review" action="setStatus" :counts="['review' => 2]" icon="far fa-filter" aria-label="Status">
                <option value="all">Alle</option><option value="review">Zu prüfen</option>
            </x-ui.forms.select>
            <x-ui.forms.select id="icon-status" variant="icon" value="all" icon="far fa-sliders-h" aria-label="Status filtern"><option value="all">Alle</option></x-ui.forms.select>
        BLADE);
        $this->assertSame(2, substr_count($html, 'data-rt-select-trigger'));
        $this->assertSame(2, substr_count($html, 'role="combobox"'));
        $this->assertSame(2, substr_count($html, 'data-select-open="false"'));
        $this->assertStringContainsString('data-rt-select-value="review"', $html);
        $this->assertStringContainsString('data-rt-select-counts="{&quot;review&quot;:2}"', $html);
        $this->assertStringContainsString('wire:target="setStatus"', $html);
        $this->assertStringContainsString('this.$wire.call(', $html);
        $invalid = Blade::render('<x-ui.forms.select id="unsafe-action" action="setStatus(1)"><option value="all">Alle</option></x-ui.forms.select>');
        $this->assertStringNotContainsString('this.$wire.call(', $invalid);
        $document = new \DOMDocument;
        @$document->loadHTML($invalid);
        $trigger = (new \DOMXPath($document))->query('//button[@id="unsafe-action"]')->item(0);
        $this->assertNotNull($trigger);
        $this->assertTrue($trigger->hasAttribute('disabled'));
    }
}
