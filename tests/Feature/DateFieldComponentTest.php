<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DateFieldComponentTest extends TestCase
{
    public function test_existing_alpine_binding_is_preserved(): void
    {
        $html = Blade::render('<x-ui.forms.date-field id="wagon-date" x-model="meta.date" aria-label="Diensttag" />');

        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('x-model="meta.date"', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringNotContainsString('$wire.entangle', $html);
        $this->assertStringNotContainsString('type="date"', $html);
        $this->assertMatchesRegularExpression('/<input\b[^>]*id="wagon-date"/s', $html);
        $this->assertStringContainsString('aria-label="Diensttag"', $html);
    }

    public function test_livewire_live_binds_iso_state_without_attaching_model_to_the_display(): void
    {
        $html = html_entity_decode(Blade::render('<x-ui.forms.date-field wire:model.live="anchorDate" :clearable="false" aria-label="Kalenderdatum" />'), ENT_QUOTES);

        $this->assertStringContainsString('$wire.entangle(\'anchorDate\').live', $html);
        $this->assertStringContainsString('x-model="display"', $html);
        $this->assertStringContainsString('data-autosave-model="anchorDate"', $html);
        $this->assertStringNotContainsString('wire:model.live=', $html);
        $this->assertStringNotContainsString('@click="clear()"', $html);
    }

    public function test_deferred_livewire_binding_and_plain_form_iso_value_remain_available(): void
    {
        $html = html_entity_decode(Blade::render('<x-ui.forms.date-field wire:model="form.date" name="service_date" />'), ENT_QUOTES);

        $this->assertStringContainsString('$wire.entangle(\'form.date\')', $html);
        $this->assertStringNotContainsString("entangle('form.date').live", $html);
        $this->assertStringContainsString('type="hidden" name="service_date" :value="value"', $html);
        $this->assertStringContainsString('@click="clear()"', $html);
    }

    public function test_accessibility_and_month_year_navigation_are_rendered(): void
    {
        $html = Blade::render('<x-ui.forms.date-field min="2026-09-01" max="2026-09-30" required aria-describedby="date-help" />');

        $this->assertStringContainsString('role="grid"', $html);
        $this->assertStringContainsString('role="row"', $html);
        $this->assertStringContainsString('role="columnheader"', $html);
        $this->assertStringContainsString('role="gridcell"', $html);
        $this->assertStringContainsString('aria-modal="false"', $html);
        $this->assertStringContainsString('@keydown="handlePanelKeydown($event)"', $html);
        $this->assertStringContainsString('@keydown="handleGridKeydown($event)"', $html);
        $this->assertStringContainsString('@click="shiftMonth(-12)"', $html);
        $this->assertStringContainsString('@click="shiftMonth(12)"', $html);
        $this->assertStringContainsString('aria-describedby="date-help"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('2026-09-01', $html);
        $this->assertStringContainsString('2026-09-30', $html);
    }

    public function test_disabled_and_readonly_keep_both_display_and_trigger_locked(): void
    {
        $html = Blade::render('<x-ui.forms.date-field :disabled="true" :readonly="true" />');

        $this->assertMatchesRegularExpression('/<input\b[^>]*\bdisabled\b[^>]*\breadonly\b/s', $html);
        $this->assertMatchesRegularExpression('/<button\b[^>]*\bdisabled\b[^>]*class="rt-ui-date-field__trigger"/s', $html);
    }

    public function test_only_the_alpine_owned_teleport_is_protected_from_livewire_morphing(): void
    {
        $html = Blade::render('<x-ui.forms.date-field wire:model.live="anchorDate" :clearable="false" />');

        // Der Body-Klon bekommt seine ID erst durch Alpine. Ohne ignore kann
        // Livewire beim naechsten Serverrender dessen Scope-freien Klon einsetzen.
        $this->assertMatchesRegularExpression('/<template\b[^>]*x-teleport="body"[^>]*wire:ignore/s', $html);
        $this->assertSame(1, substr_count($html, 'wire:ignore'));
        $this->assertDoesNotMatchRegularExpression('/<div\b[^>]*wire:ignore/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*wire:ignore/s', $html);
        $this->assertStringContainsString('$wire.entangle', $html);
        $this->assertStringContainsString('x-model="display"', $html);
    }
}
