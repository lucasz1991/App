<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ResponsiveUiComponentsTest extends TestCase
{
    public function test_legacy_dropdown_alias_renders_the_viewport_safe_shared_anchor_dropdown(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-dropdown align="left" width="96">
                <x-slot:trigger><button type="button">Öffnen</button></x-slot:trigger>
                <x-slot:content><button type="button">Aktion</button></x-slot:content>
            </x-dropdown>
        BLADE);

        $this->assertStringContainsString('x-data="viewportDropdown({', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('data-rt-dropdown-panel', $html);
        $this->assertStringContainsString('data-rt-dropdown-caret', $html);
        $this->assertStringContainsString('gutter: 12', $html);
        $this->assertStringContainsString('w-96', $html);
        $this->assertStringContainsString('role="menu"', $html);
    }

    public function test_dropdown_positioner_clamps_all_edges_and_tracks_the_trigger_with_a_caret(): void
    {
        $script = file_get_contents(resource_path('js/viewport-dropdown.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('calculateViewportDropdownPosition', $script);
        $this->assertStringContainsString("placement === 'bottom'", $script);
        $this->assertStringContainsString('viewportRight - safeGutter - width', $script);
        $this->assertStringContainsString("--rt-dropdown-caret-x", $script);
        $this->assertStringContainsString('.rt-viewport-dropdown[data-placement="bottom"] .rt-ui-dropdown-caret', $styles);
        $this->assertStringContainsString('.rt-viewport-dropdown[data-placement="top"] .rt-ui-dropdown-caret', $styles);
    }

    public function test_topbar_preferences_are_grouped_in_one_shared_anchor_dropdown(): void
    {
        $view = file_get_contents(resource_path('views/layouts/topbar.blade.php'));
        $html = view('layouts.topbar', ['area' => 'user'])->render();

        $this->assertSame(1, substr_count($view, 'data-topbar-preferences-dropdown'));
        $this->assertSame(1, substr_count($view, 'data-topbar-preferences-trigger'));
        $this->assertSame(1, substr_count($view, 'data-topbar-preferences-icon'));
        $this->assertStringContainsString('<x-ui.dropdown.anchor-dropdown', $view);
        $this->assertSame(1, substr_count($view, '<x-topbar.control-button'));

        preg_match_all('/data-topbar-preference="([^"]+)"/', $view, $preferences);
        $this->assertSame(['language', 'theme', 'sound'], $preferences[1]);

        $this->assertStringContainsString('href="' . route('locale.switch', 'de') . '"', $html);
        $this->assertStringContainsString('href="' . route('locale.switch', 'en') . '"', $html);

        $this->assertSame(1, substr_count($view, '$store.theme?.toggle()'));
        $this->assertSame(1, substr_count($view, '$store.sound?.toggle()'));
        $this->assertSame(1, substr_count($html, 'data-topbar-preferences-dropdown'));
        $this->assertSame(1, substr_count($html, 'data-topbar-preferences-trigger'));
        $this->assertSame(2, substr_count($html, 'role="menuitemradio"'));
        $this->assertSame(2, substr_count($html, 'role="menuitemcheckbox"'));
        $this->assertStringContainsString('aria-label="' . __('app.settings') . '"', $html);
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('x-bind:aria-expanded="open.toString()"', $html);

        $this->assertDoesNotMatchRegularExpression(
            '/data-topbar-(?:language|locale|theme|sound)-(?:trigger|toggle)/',
            $view,
        );
    }

    public function test_table_uses_mobile_summary_grid_and_fixed_right_actions(): void
    {
        $item = (object) ['id' => 7, 'status' => 1];

        $html = Blade::render(<<<'BLADE'
            <x-tables.table
                :columns="$columns"
                :items="$items"
                actions-view="components.tables.rows.user-messages.actions"
            />
        BLADE, [
            'columns' => [
                ['label' => 'Name', 'key' => 'name', 'width' => '60%', 'hideOn' => 'none'],
                ['label' => 'Status', 'key' => 'status', 'width' => '40%', 'hideOn' => 'none'],
            ],
            'items' => collect([$item]),
        ]);

        $this->assertStringContainsString('rt-table-row-grid', $html);
        $this->assertStringContainsString('rt-table-row-actions absolute right-2', $html);
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
    }

    public function test_toast_script_replaces_old_listeners_and_suppresses_immediate_duplicates(): void
    {
        $script = file_get_contents(public_path('js/rt-toast.js'));

        $this->assertStringContainsString('window.__rtToastAbortController.abort()', $script);
        $this->assertStringContainsString('signal: listenerController.signal', $script);
        $this->assertStringContainsString('return now - lastShownAt < 500', $script);
    }

    public function test_employee_header_has_one_mobile_actions_dropdown(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));

        $this->assertSame(1, substr_count($view, '<div class="sm:hidden">'));
        $this->assertStringContainsString('<x-ui.dropdown.anchor-dropdown align="right" width="64">', $view);
        $this->assertStringContainsString(':label="__(\'app.actions\')"', $view);
    }

    public function test_text_controls_use_mobile_safe_font_sizes_and_polished_focus_states(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.forms.input id="name" />
            <x-ui.forms.select id="team"><option>Team</option></x-ui.forms.select>
            <x-input id="legacy" />
        BLADE);
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(3, substr_count($html, 'text-base'));
        $this->assertSame(3, substr_count($html, 'sm:text-sm'));
        $this->assertSame(3, substr_count($html, 'min-h-11'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'focus:ring-4'));
        $this->assertStringContainsString("input[type='text']", $styles);
        $this->assertStringContainsString('font-size: 1rem !important', $styles);
        $this->assertStringContainsString('textarea {', $styles);
    }

    public function test_toggle_components_share_a_larger_accessible_switch_design(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.forms.toggle-button id="primary-toggle" label="Aktiv" />
            <x-ui.forms.checkbox id="secondary-toggle" toggle label="E-Mail" />
        BLADE);

        $this->assertSame(2, substr_count($html, 'role="switch"'));
        $this->assertSame(2, substr_count($html, 'data-toggle-control'));
        $this->assertSame(2, substr_count($html, 'h-7 w-12'));
        $this->assertSame(2, substr_count($html, 'peer-focus-visible:ring-4'));
        $this->assertSame(2, substr_count($html, 'peer-checked:after:translate-x-full'));
        $this->assertStringNotContainsString('aria-checked="true"', $html);
    }

    public function test_tabs_use_a_full_width_mobile_section_selector_by_default(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.accordion.tabs
                :tabs="[
                    'general' => ['label' => 'Allgemein', 'icon' => 'fad fa-sliders-h'],
                    'company' => ['label' => 'Firmendaten', 'icon' => 'fad fa-building'],
                    'users' => ['label' => 'Benutzer', 'icon' => 'fad fa-users'],
                    'system' => ['label' => 'System', 'icon' => 'fad fa-server'],
                ]"
            >
                Inhalt
            </x-ui.accordion.tabs>
        BLADE);

        $this->assertStringContainsString("setupMQ('md')", $html);
        $this->assertStringContainsString('aria-haspopup="listbox"', $html);
        $this->assertStringContainsString('relative min-w-0 flex-1', $html);
        $this->assertStringContainsString('max-h-[min(22rem,60dvh)]', $html);
    }

    public function test_admin_settings_use_mobile_spacing_full_width_actions_and_safe_grids(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/settings.blade.php'));

        $this->assertStringContainsString('content-class="mt-4 sm:mt-6"', $view);
        $this->assertStringContainsString('p-4 shadow-rt-sm', $view);
        $this->assertStringContainsString('sm:grid-cols-2', $view);
        $this->assertGreaterThanOrEqual(4, substr_count($view, 'class="w-full sm:w-auto"'));
        $this->assertGreaterThanOrEqual(4, substr_count($view, 'class="hidden h-11 w-11'));
    }
}
