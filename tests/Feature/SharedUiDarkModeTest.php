<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SharedUiDarkModeTest extends TestCase
{
    public function test_shared_primitives_expose_the_central_theme_hooks(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-secondary-button id="secondary">Sekundaer</x-secondary-button>
            <x-ui.buttons.button-basic id="basic" mode="basic">Basis</x-ui.buttons.button-basic>
            <x-ui.forms.input id="name" />
            <x-ui.forms.textarea id="notes" rows="3">Notiz</x-ui.forms.textarea>
            <x-ui.forms.select id="team"><option>Team</option></x-ui.forms.select>
            <x-ui.forms.checkbox id="check" label="Aktiv" />
            <x-ui.forms.checkbox id="switch" toggle label="E-Mail" />
            <x-ui.surface.card>Karte</x-ui.surface.card>
            <x-ui.badge color="green">Aktiv</x-ui.badge>
            <x-ui.feedback.alert type="warning">Hinweis</x-ui.feedback.alert>
        BLADE);

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'rt-ui-button-secondary'));
        $this->assertSame(3, substr_count($html, 'rt-ui-control'));
        $this->assertStringContainsString('rt-ui-checkbox', $html);
        $this->assertStringContainsString('rt-ui-toggle-control', $html);
        $this->assertStringContainsString('rt-ui-surface', $html);
        $this->assertStringContainsString('rt-ui-badge', $html);
        $this->assertStringContainsString('data-rt-tone="green"', $html);
        $this->assertStringContainsString('rt-ui-alert', $html);
        $this->assertStringContainsString('data-rt-tone="warning"', $html);
    }

    public function test_disabled_anchor_buttons_remove_navigation_and_click_actions(): void
    {
        $button = Blade::render(<<<'BLADE'
            <x-ui.buttons.button-basic href="/secret" :can="false" wire:click="save">
                Gesperrt
            </x-ui.buttons.button-basic>
        BLADE);

        $dropdown = Blade::render(<<<'BLADE'
            <x-dropdown-link href="/secret" :can="false" wire:click="destroy">
                Gesperrt
            </x-dropdown-link>
        BLADE);

        foreach ([$button, $dropdown] as $html) {
            $this->assertStringContainsString('aria-disabled="true"', $html);
            $this->assertStringContainsString('tabindex="-1"', $html);
            $this->assertStringNotContainsString('href="/secret"', $html);
        }

        $this->assertStringNotContainsString('wire:click="save"', $button);
        $this->assertStringNotContainsString('wire:click="destroy"', $dropdown);
    }

    public function test_shared_buttons_use_css_feedback_without_per_button_alpine_timers(): void
    {
        $templates = [
            resource_path('views/components/button.blade.php'),
            resource_path('views/components/secondary-button.blade.php'),
            resource_path('views/components/ui/buttons/button-basic.blade.php'),
        ];

        foreach ($templates as $template) {
            $source = file_get_contents($template);

            $this->assertStringContainsString('active:scale-[0.98]', $source);
            $this->assertStringNotContainsString('scale(0.9)', $source);
            $this->assertStringNotContainsString('setTimeout', $source);
        }
    }

    public function test_theme_contract_supports_both_runtime_signals_and_component_states(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('html.dark .rt-ui-button-secondary', $styles);
        $this->assertStringContainsString('body[data-mode="dark"] .rt-ui-button-secondary', $styles);
        $this->assertStringContainsString('html.dark .rt-ui-control', $styles);
        $this->assertStringContainsString('body[data-mode="dark"] .rt-ui-control', $styles);
        $this->assertStringContainsString('.rt-ui-button[aria-disabled="true"]', $styles);
        $this->assertStringContainsString('input:checked + .rt-ui-toggle-control', $styles);
        $this->assertStringContainsString('.rt-ui-dropdown-link[data-rt-tone="danger"]', $styles);
        $this->assertStringContainsString('.rt-pagination [aria-current="page"] > span', $styles);
        $this->assertStringContainsString('background-color: #111a27 !important', $styles);
    }

    public function test_global_theme_contract_uses_the_current_railtime_palette(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        foreach ([
            '#111827' => '#111a27',
            '#172033' => '#182435',
            '#0f172a' => '#0c1421',
            '#273449' => '#2a394d',
            '#e7edf7' => '#edf2f8',
            '#a9b6c9' => '#aeb9c9',
            '#8c9bb0' => '#8d9bad',
            '#ff8295' => '#ff7189',
            '#1b2a43' => '#1a293d',
            '#101827' => '#0d1624',
            '#0b1120' => '#080d16',
            '#263653' => '#223249',
            '#64748b' => '#637188',
            '#94a3b8' => '#91a0b4',
        ] as $legacyColor => $currentColor) {
            $this->assertStringNotContainsString($legacyColor, $styles);
            $this->assertStringContainsString($currentColor, $styles);
        }
    }

    public function test_modal_table_and_pagination_templates_are_connected_to_the_contract(): void
    {
        $modal = file_get_contents(resource_path('views/components/modal.blade.php'));
        $table = file_get_contents(resource_path('views/components/tables/table.blade.php'));

        $this->assertStringContainsString('rt-ui-modal-panel', $modal);
        $this->assertStringContainsString('role="dialog"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString('rt-ui-table', $table);
        $this->assertStringContainsString('aria-sort=', $table);

        foreach ([
            'views/vendor/pagination/tailwind.blade.php',
            'views/vendor/pagination/simple-tailwind.blade.php',
            'views/vendor/livewire/tailwind.blade.php',
            'views/vendor/livewire/simple-tailwind.blade.php',
        ] as $view) {
            $this->assertStringContainsString('rt-pagination', file_get_contents(resource_path($view)));
        }
    }

    public function test_toasts_use_themeable_markup_and_an_accessible_live_region(): void
    {
        $script = file_get_contents(public_path('js/rt-toast.js'));

        $this->assertStringContainsString("toast.className = 'rt-toast'", $script);
        $this->assertStringContainsString("textElement.className = 'rt-toast__message'", $script);
        $this->assertStringContainsString("container.setAttribute('aria-live', 'polite')", $script);
        $this->assertStringContainsString("toast.setAttribute('role'", $script);
        $this->assertStringContainsString('max-width:calc(100vw - 32px)', $script);
        $this->assertStringNotContainsString('background:#fff', $script);
    }

    public function test_production_css_contains_the_shared_theme_contract(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $asset = $manifest['resources/css/app.css']['file'] ?? null;

        $this->assertNotNull($asset, 'The Vite manifest does not contain the application stylesheet.');

        $compiledPath = public_path('build/' . $asset);
        $this->assertFileExists($compiledPath);

        $compiled = file_get_contents($compiledPath);
        $this->assertStringContainsString('.rt-ui-button-secondary', $compiled);
        $this->assertStringContainsString('.rt-ui-control', $compiled);
        $this->assertStringContainsString('.rt-pagination', $compiled);
        $this->assertStringContainsString('.rt-admin-hero-secondary', $compiled);
        $this->assertStringContainsString('.rt-operational-page .rt-operational-stat', $compiled);
        $this->assertStringContainsString('.rt-operational-page .rt-operational-nav-link', $compiled);
        $this->assertStringContainsString('body[data-mode=dark]', $compiled);
    }
}
