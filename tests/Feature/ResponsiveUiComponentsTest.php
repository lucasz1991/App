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

        $this->assertStringContainsString('x-data="{', $html);
        $this->assertStringNotContainsString('viewportDropdown(', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('data-rt-dropdown-panel', $html);
        $this->assertStringContainsString('data-rt-dropdown-caret', $html);
        $this->assertStringContainsString('gutter: 12', $html);
        $this->assertStringContainsString('w-96', $html);
        $this->assertStringContainsString('role="menu"', $html);
    }

    public function test_dropdown_positioner_clamps_all_edges_and_tracks_the_trigger_with_a_caret(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/dropdown/anchor-dropdown.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('positionPanel()', $component);
        $this->assertStringContainsString("this.placement === 'bottom'", $component);
        $this->assertStringContainsString('viewportRight - this.gutter - panelWidth', $component);
        $this->assertStringContainsString("--rt-dropdown-caret-x", $component);
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

        $this->assertStringContainsString('id="topbar-language"', $html);
        $this->assertStringContainsString('data-rt-custom-select', $html);
        $this->assertStringContainsString('\u0022value\u0022:\u0022de', $html);
        $this->assertStringContainsString('\u0022value\u0022:\u0022en', $html);
        $this->assertStringNotContainsString('<select', $html);

        $this->assertSame(1, substr_count($view, '$store.theme?.toggle()'));
        $this->assertSame(1, substr_count($view, '$store.sound?.toggle()'));
        $this->assertSame(1, substr_count($html, 'data-topbar-preferences-dropdown'));
        $this->assertSame(1, substr_count($html, 'data-topbar-preferences-trigger'));
        $this->assertSame(0, substr_count($html, 'role="menuitemradio"'));
        $this->assertSame(2, substr_count($html, 'role="menuitemcheckbox"'));
        $this->assertStringContainsString('grid grid-cols-2 gap-2', $html);
        $this->assertSame(2, substr_count($view, 'data-topbar-preference="theme"') + substr_count($view, 'data-topbar-preference="sound"'));
        $this->assertGreaterThanOrEqual(4, substr_count($view, 'x-show='));
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
                :selected-items="[7]"
                selection-action="toggleSelection"
                detail-route="admin.user-profile"
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
        $this->assertStringContainsString('rt-table-row-actions absolute right-3 top-3', $html);
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('data-table-row-interactive="true"', $html);
        $this->assertStringContainsString('data-selected="true"', $html);
        $this->assertStringContainsString('rt-table-row-selected', $html);
        $this->assertStringContainsString('x-on:click="queueSelection($event)"', $html);
        $this->assertStringContainsString('x-on:dblclick.prevent="openDetails($event)"', $html);
        $this->assertStringContainsString('window.setTimeout(() => this.toggleSelection(), 220)', $html);
        $this->assertStringContainsString('administrator\\/user\\/7', $html);
        $this->assertStringContainsString("event.target.closest('a, button, input, select, textarea, label, [role=button], [data-table-row-ignore]')", $html);
    }

    public function test_all_shared_application_tables_configure_selection_and_details(): void
    {
        $employees = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));
        $mails = file_get_contents(resource_path('views/livewire/admin/mail-management.blade.php'));
        $messages = file_get_contents(resource_path('views/livewire/message-box.blade.php'));
        $tasks = file_get_contents(resource_path('views/livewire/admin/admin-tasks-list.blade.php'));

        $this->assertStringContainsString('selection-action="toggleEmployeeSelection"', $employees);
        $this->assertStringContainsString("'admin.user-profile' : 'employees.show'", $employees);
        $this->assertStringContainsString('selection-action="toggleMailSelection"', $mails);
        $this->assertStringContainsString('detail-action="toggleMailDetails"', $mails);
        $this->assertStringContainsString('selection-action="toggleMessageSelection"', $messages);
        $this->assertStringContainsString('detail-action="openMessageDetail"', $messages);
        $this->assertStringContainsString('selection-action="toggleTaskSelection"', $tasks);
        $this->assertStringContainsString('detail-action="openTaskDetail"', $tasks);
    }

    public function test_tables_use_single_column_mobile_cards_and_scrollable_permission_matrices(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $table = file_get_contents(resource_path('views/components/tables/table.blade.php'));
        $contacts = file_get_contents(resource_path('views/livewire/admin/manage-contacts.blade.php'));
        $filePools = file_get_contents(resource_path('views/livewire/tools/file-pools/manage-file-pools.blade.php'));

        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $styles);
        $this->assertStringContainsString('[data-rt-table-label]:not(.hidden)', $styles);
        $this->assertStringContainsString('rt-table-row-details', $table);
        $this->assertStringContainsString('rt-responsive-data-table', $contacts);
        $this->assertGreaterThanOrEqual(7, substr_count($contacts, 'data-rt-table-label='));
        $this->assertStringContainsString('rt-table-scroll', $filePools);
        $this->assertStringContainsString('min-w-[34rem]', $filePools);
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

    public function test_shared_select_uses_the_anchor_dropdown_instead_of_a_native_select(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.forms.select id="team" placeholder="Team wählen">
                <option value="1">Verwaltung</option>
                <option value="2">Mitarbeiter</option>
            </x-ui.forms.select>
        BLADE);
        $component = file_get_contents(resource_path('views/components/ui/forms/select.blade.php'));

        $this->assertStringContainsString('data-rt-custom-select', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('Verwaltung', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('<select', $component);
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

    public function test_tabs_use_a_visible_wrapping_mobile_icon_switcher_by_default(): void
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
        $this->assertStringContainsString('grid min-w-0 grid-cols-2', $html);
        $this->assertStringContainsString("items.length % 2 === 1", $html);
        $this->assertStringContainsString("'col-span-2'", $html);
        $this->assertStringContainsString('far fa-check-circle', $html);
        $this->assertStringContainsString('break-words', $html);
        $this->assertStringNotContainsString('flex-1 truncate', $html);
        $this->assertStringNotContainsString('aria-haspopup="listbox"', $html);
        $this->assertStringContainsString('rt-mobile-tab', $html);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString("body[data-mode='dark'] .rt-mobile-tab", $styles);
        $this->assertStringContainsString(".rt-mobile-tab[data-active='true']", $styles);
    }

    public function test_employee_list_remains_one_row_per_employee_on_mobile(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('rt-employee-mobile-header', $view);
        $this->assertStringContainsString('class="rt-employee-table"', $view);
        $this->assertStringContainsString('.rt-employee-table .rt-table-row-grid', $styles);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr)', $styles);
        $this->assertStringContainsString('.rt-employee-table .rt-employee-email-cell', $styles);
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

    public function test_mobile_sidebar_leaves_accordion_state_to_metis_menu(): void
    {
        $group = file_get_contents(resource_path('views/components/menu/sidebar-nav-group.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("'mm-show' => \$active", $group);
        $this->assertStringNotContainsString('data-mobile-expanded', $group);
        $this->assertStringContainsString('toggle: true', $script);
        $this->assertStringNotContainsString('[data-mobile-expanded="true"]', $styles);
        $this->assertStringContainsString('.rt-ui-sidebar .sidebar-nav-link:focus-visible', $styles);
        $this->assertStringContainsString('background-color: #fff0f3 !important', $styles);
    }

    public function test_shared_tabs_and_layout_expose_mobile_swipe_navigation(): void
    {
        $tabs = file_get_contents(resource_path('views/components/ui/accordion/tabs.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));
        $wagon = file_get_contents(resource_path('js/wagon-list-prototype.js'));

        $this->assertStringContainsString('data-swipe-tabs', $tabs);
        $this->assertStringContainsString('@touchstart.passive="touchStart($event)"', $tabs);
        $this->assertStringContainsString("this.moveTab(deltaX < 0 ? 1 : -1, false)", $tabs);
        $this->assertStringNotContainsString('\\"', $tabs);
        $this->assertStringContainsString('initMobileSidebarSwipe()', $script);
        $this->assertStringContainsString("startsAtOpeningEdge", $script);
        $this->assertStringContainsString('setMobileSidebarOpen(true)', $script);
        $this->assertStringContainsString('setMobileSidebarOpen(false)', $script);
        $this->assertStringContainsString('wagonTouchStart', $wagon);
        $this->assertStringContainsString('nextMobileWagon()', $wagon);
        $this->assertStringContainsString('previousMobileWagon()', $wagon);
    }

    public function test_file_explorer_uses_equal_compact_cards_and_tabbed_settings(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/file-pools/manage-file-pools.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(2, substr_count($view, 'class="rt-file-explorer-grid'));
        $this->assertGreaterThanOrEqual(2, substr_count($view, 'rt-file-explorer-card'));
        $this->assertStringContainsString('.rt-file-explorer-grid', $styles);
        $this->assertStringContainsString('repeat(auto-fill, minmax(5.75rem, 6.5rem))', $styles);
        $this->assertStringContainsString('persist-key="file-settings.tabs"', $view);
        $this->assertStringContainsString('persist-key="folder-settings.tabs"', $view);
        $this->assertStringContainsString("'fileName' =>", $view);
        $this->assertStringContainsString("'fileVisibility' =>", $view);
        $this->assertStringContainsString("'fileDeletion' =>", $view);
        $this->assertStringContainsString("'folderName' =>", $view);
        $this->assertStringContainsString("'folderVisibility' =>", $view);
        $this->assertStringContainsString("'folderDeletion' =>", $view);
        $this->assertSame(2, substr_count($view, 'class="mt-1 block" required'));
    }
}
