<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MultiToggleComponentTest extends TestCase
{
    private function render(array $options = [], array $attributes = []): string
    {
        return Blade::render('<x-ui.buttons.multi-toggle :options="$options" :value="$value" :action="$action" :disabled="$disabled" label="Kalenderansicht" id="calendar-view-toggle" />', array_merge([
            'options' => $options ?: [
                ['value' => 'day', 'label' => 'Tag', 'icon' => 'fa-calendar-day'],
                ['value' => 'week', 'label' => 'Woche', 'icon' => 'fa-calendar-week'],
                ['value' => 'month', 'label' => 'Monat', 'icon' => 'fa-calendar-days'],
            ],
            'value' => 'week', 'action' => 'switchView', 'disabled' => false,
        ], $attributes));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $prior = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prior);

        return new DOMXPath($document);
    }

    public function test_group_uses_named_icon_buttons_and_one_selected_tab_stop(): void
    {
        $html = $this->render();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//*[@role="group" and @aria-label="Kalenderansicht"]')->length);
        $this->assertSame(3, $xpath->query('//button[@data-multi-toggle-option]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-pressed="true" and @aria-label="Woche" and @tabindex="0"]')->length);
        $this->assertSame(2, $xpath->query('//button[@aria-pressed="false" and @tabindex="-1"]')->length);
        $this->assertSame(3, $xpath->query('//button/i[@aria-hidden="true"]')->length);
        $this->assertSame(3, $xpath->query('//button/span[@class="sr-only"]')->length);
        $this->assertSame(0, $xpath->query('//button[@disabled]')->length);
        $this->assertStringContainsString('rt-ui-button', $html);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('wire:target="switchView"', $html);
    }

    public function test_disabled_options_are_not_actions_or_tab_stops_and_selection_does_not_move(): void
    {
        $xpath = $this->xpath($this->render([
            ['value' => 'week', 'label' => 'Woche', 'icon' => 'fa-calendar-week', 'disabled' => true],
            ['value' => 'day', 'label' => 'Tag', 'icon' => 'fa-calendar-day'],
        ]));
        $this->assertSame(1, $xpath->query('//button[@aria-pressed="true" and @disabled and @tabindex="-1"]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-label="Tag" and @tabindex="0" and @aria-pressed="false"]')->length);
        $this->assertSame(0, $xpath->query('//button[@disabled]/@*[name()="wire:click"]')->length);

        $allDisabled = $this->xpath($this->render(attributes: ['disabled' => true]));
        $this->assertSame(3, $allDisabled->query('//button[@disabled]')->length);
        $this->assertSame(0, $allDisabled->query('//button[@tabindex="0"]')->length);
    }

    public function test_numeric_looking_values_are_compared_strictly_for_selected_focus(): void
    {
        $xpath = $this->xpath($this->render([
            ['value' => '01', 'label' => 'Erste Ansicht', 'icon' => 'fa-calendar-day'],
            ['value' => 1, 'label' => 'Zweite Ansicht', 'icon' => 'fa-calendar-week'],
        ], ['value' => 1]));
        $this->assertSame(1, $xpath->query('//button[@data-toggle-value="1" and @aria-pressed="true" and @tabindex="0"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-toggle-value="01" and @aria-pressed="false" and @tabindex="-1"]')->length);
    }

    public function test_invalid_action_is_inert_and_malformed_or_duplicate_options_are_omitted(): void
    {
        $html = $this->render(attributes: ['action' => 'switchView();alert(1)']);
        $this->assertStringNotContainsString('switchView();alert(1)', $html);
        $this->assertStringNotContainsString('wire:click=', $html);
        $this->assertSame(3, $this->xpath($html)->query('//button[@disabled]')->length);

        $html = $this->render([
            ['value' => 'day', 'label' => 'Tag', 'icon' => 'fa-calendar-day'],
            ['value' => 'day', 'label' => 'Doppelt', 'icon' => 'fa-calendar-day'],
            ['value' => 'unsafe', 'label' => 'Ungültig', 'icon' => 'fa-day" onclick="alert(1)'],
            ['value' => [], 'label' => 'Array', 'icon' => 'fa-list'],
        ]);
        $this->assertSame(1, $this->xpath($html)->query('//button[@data-multi-toggle-option]')->length);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('Doppelt', $html);
    }

    public function test_labels_and_serialized_values_cannot_inject_markup_or_action_arguments(): void
    {
        $value = "day');window.injected=true;//\"<script>bad</script>";
        $label = '<img src=x onerror=alert(1)> & Tag';
        $html = $this->render([['value' => $value, 'label' => $label, 'icon' => 'fa-calendar-day']], ['value' => $value]);
        $xpath = $this->xpath($html);
        $this->assertSame(0, $xpath->query('//script|//img')->length);
        $button = $xpath->query('//button')->item(0);
        $this->assertSame($label, $button->getAttribute('aria-label'));
        $this->assertSame($value, $button->getAttribute('data-toggle-value'));
        $this->assertStringContainsString('\\u0027', $button->getAttribute('wire:click'));
        $this->assertStringNotContainsString('<script>', $button->getAttribute('wire:click'));
        $this->assertSame('true', $button->getAttribute('aria-pressed'));
    }

    public function test_keyboard_tooltip_and_loading_contracts_are_present_without_another_js_library(): void
    {
        $html = $this->render();
        foreach (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'buttons[index].click()', 'button.disabled', 'MutationObserver', 'observer?.disconnect()', 'focusAfterLoad'] as $contract) {
            $this->assertStringContainsString($contract, $html);
        }
        foreach (['x-teleport="body"', 'role="tooltip"', 'x-on:focus=', 'x-on:keydown.escape=', 'getBoundingClientRect()', 'window.visualViewport', 'x-text="tooltipText"', 'wire:loading.attr="aria-busy"'] as $contract) {
            $this->assertStringContainsString($contract, $html);
        }
        $styles = file_get_contents(resource_path('css/multi-toggle.css'));
        foreach (['width: 44px', 'height: 44px', ':focus-visible', 'prefers-reduced-motion', 'var(--rt-shell-panel)', 'position: fixed', "[aria-pressed='true']"] as $contract) {
            $this->assertStringContainsString($contract, $styles);
        }
    }
}
