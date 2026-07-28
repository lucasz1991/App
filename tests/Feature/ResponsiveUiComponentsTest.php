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
        $this->assertStringContainsString('x-anchor.bottom-start.offset.8.fixed="$refs.trigger"', $html);
        $this->assertStringContainsString('data-rt-dropdown-panel', $html);
        $this->assertStringContainsString('data-rt-dropdown-caret', $html);
        $this->assertStringNotContainsString('left:12px; top:12px', $html);
        $this->assertStringContainsString('w-96', $html);
        $this->assertStringContainsString('role="menu"', $html);
    }

    public function test_dropdown_uses_alpine_anchor_for_livewire_safe_fixed_positioning_and_tracks_the_trigger_with_a_caret(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/dropdown/anchor-dropdown.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));

        $this->assertStringContainsString("'x-anchor.' . \$anchorPlacement . '.offset.' . \$anchorOffset . '.fixed'", $component);
        $this->assertStringContainsString('$anchor.x', $component);
        $this->assertStringContainsString('$anchor.y', $component);
        $this->assertStringContainsString('syncAnchoredPanel($el, anchorX, anchorY)', $component);
        $this->assertStringNotContainsString('positionPanel()', $component);
        $this->assertStringNotContainsString("document.addEventListener('scroll'", $component);
        $this->assertStringNotContainsString('left:12px; top:12px', $component);
        $this->assertStringContainsString('--rt-dropdown-caret-x:{{ $anchorCaretX }}', $component);
        $this->assertStringContainsString('--rt-dropdown-connector-size:{{ $anchorConnectorSize }}px', $component);
        $this->assertStringContainsString('--rt-dropdown-caret-x', $component);
        $this->assertStringContainsString('--rt-dropdown-connector-size', $component);
        $this->assertStringContainsString('Math.max(6, this.offset + 2)', $component);
        $this->assertStringContainsString('.rt-viewport-dropdown[data-placement="bottom"] .rt-ui-dropdown-caret', $styles);
        $this->assertStringContainsString('.rt-viewport-dropdown[data-placement="top"] .rt-ui-dropdown-caret', $styles);
        $this->assertStringContainsString('.rt-viewport-dropdown .rt-ui-dropdown-caret::before', $shellStyles);
        $this->assertStringContainsString('.rt-viewport-dropdown .rt-ui-dropdown-caret::after', $shellStyles);
        $this->assertStringContainsString(".rt-viewport-dropdown[data-placement='bottom'] .rt-ui-dropdown-caret", $shellStyles);
        $this->assertStringContainsString(".rt-viewport-dropdown[data-placement='top'] .rt-ui-dropdown-caret", $shellStyles);
    }

    public function test_chat_overview_exposes_vertical_context_actions_on_hover_and_mobile(): void
    {
        $list = file_get_contents(resource_path('views/livewire/chat/partials/chat-list.blade.php'));
        $trigger = file_get_contents(resource_path('views/components/ui/dropdown/action-trigger.blade.php'));
        $styles = file_get_contents(resource_path('css/chat-redesign.css'));

        $this->assertStringContainsString('rt-chat-list-entry group relative', $list);
        $this->assertStringContainsString('rt-chat-list-options absolute right-1.5', $list);
        $this->assertStringContainsString('orientation="vertical"', $list);
        $this->assertStringContainsString("__('app.chat_options') . ': ' . \$chat->displayNameFor(\$me)", $list);
        $this->assertStringContainsString('wire:click="requestDeleteChat({{ $chat->id }})"', $list);
        $this->assertStringContainsString("route('chat.export', ['chat' => \$chat])", $list);
        $this->assertStringContainsString("'fa-ellipsis-v' : 'fa-ellipsis-h'", $trigger);
        $this->assertStringContainsString('x-bind:aria-expanded="open.toString()"', $trigger);
        $this->assertStringContainsString('@media (min-width: 768px) and (hover: hover) and (pointer: fine)', $styles);
        $this->assertStringContainsString('.rt-chat-list-entry:hover .rt-chat-list-options', $styles);
        $this->assertStringContainsString('.rt-chat-list-entry:focus-within .rt-chat-list-options', $styles);
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
        $this->assertStringContainsString('\u0022icon\u0022:\u0022', $html);
        $this->assertStringContainsString('de.svg', $html);
        $this->assertStringContainsString('gb.svg', $html);
        $this->assertStringContainsString('selectedIcon', $html);
        $this->assertStringNotContainsString('<select', $html);

        $this->assertSame(1, substr_count($view, '$store.theme?.toggle()'));
        $this->assertSame(1, substr_count($view, '$store.sound?.toggle()'));
        $this->assertSame(1, substr_count($html, 'data-topbar-preferences-dropdown'));
        $this->assertSame(1, substr_count($html, 'data-topbar-preferences-trigger'));
        $this->assertSame(0, substr_count($html, 'role="menuitemradio"'));
        $this->assertSame(2, substr_count($html, 'role="menuitemcheckbox"'));
        $this->assertStringContainsString('grid grid-cols-2 gap-2', $html);
        $this->assertSame(2, substr_count($view, 'data-topbar-preference="theme"') + substr_count($view, 'data-topbar-preference="sound"'));
        $this->assertSame(1, substr_count($html, 'data-topbar-toggle-track="theme"'));
        $this->assertSame(1, substr_count($html, 'data-topbar-toggle-track="sound"'));
        $this->assertSame(2, substr_count($html, 'translate-x-[22px]'));
        $this->assertGreaterThanOrEqual(4, substr_count($view, 'x-show='));
        $this->assertStringContainsString('aria-label="'.__('app.settings').'"', $html);
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

    public function test_employee_header_uses_the_shared_responsive_page_actions_dropdown(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));
        $actions = file_get_contents(resource_path('views/components/ui/dropdown/page-actions.blade.php'));
        $trigger = file_get_contents(resource_path('views/components/ui/dropdown/action-trigger.blade.php'));

        // EIN Aktionen-Dropdown fuer alle Bildschirmgroessen — nur die drei
        // Punkte, ohne Beschriftung und ohne getrennte Desktop-Buttonreihe.
        $this->assertStringContainsString('<x-ui.dropdown.page-actions>', $view);
        $this->assertStringContainsString('<x-ui.dropdown.anchor-dropdown', $actions);
        $this->assertStringContainsString('orientation="vertical"', $actions);
        $this->assertStringContainsString('responsive-label', $actions);
        $this->assertStringContainsString("'responsiveLabel' => false", $trigger);
        $this->assertStringContainsString("'hidden sm:inline' => \$responsiveLabel", $trigger);
        $this->assertStringNotContainsString('<div class="sm:hidden">', $view);
        $this->assertStringNotContainsString('hidden items-center gap-2 sm:flex', $view);

        // Der Kopf traegt keinen Eyebrow mehr — nur den Titel.
        $this->assertStringNotContainsString('eyebrow', $view);
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

    public function test_tabs_use_a_theme_aware_command_rail_at_every_breakpoint(): void
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

        $this->assertStringContainsString('data-tab-carousel', $html);
        $this->assertStringContainsString('rt-tabs-carousel-track', $html);
        $this->assertStringContainsString('rt-carousel-tab', $html);
        $this->assertStringContainsString('class="rt-tabs-shell rt-tabs-v2"', $html);
        $this->assertStringContainsString('data-tabs-input-policy="swiper-touch"', $html);
        $this->assertStringContainsString('data-slider-library="swiper"', $html);
        $this->assertStringContainsString('swiper-wrapper', $html);
        $this->assertStringContainsString('swiper-slide', $html);
        $this->assertStringContainsString(':data-position="openTab === tab.id ? \'active\' : \'inactive\'"', $html);
        $this->assertStringContainsString('@click="selectTab(tab.id, true)"', $html);
        $this->assertStringContainsString('aria-orientation="horizontal"', $html);
        $this->assertStringContainsString(':tabindex="openTab === tab.id ? 0 : -1"', $html);
        $this->assertStringContainsString('data-rt-tab-active-mark', $html);
        $this->assertStringContainsString(":data-sticky-enabled=\"stickyEnabled ? 'true' : 'false'\"", $html);
        $this->assertStringContainsString("stickyEnabled = !\$root.closest('[role=dialog]')", $html);
        $this->assertStringNotContainsString('aria-haspopup="listbox"', $html);
        $this->assertStringNotContainsString('grid-cols-2', $html);

        $styles = file_get_contents(resource_path('css/tabs-redesign.css'));
        $this->assertStringContainsString('.rt-tabs-v2.rt-tabs-shell {', $styles);
        $this->assertStringContainsString("theme('colors.rt.surface')", $styles);
        $this->assertStringContainsString("theme('colors.rt-dark.surface')", $styles);
        $this->assertStringContainsString(".rt-carousel-tab[data-position='active']", $styles);
        $this->assertStringContainsString(".rt-carousel-tab[data-position='inactive']", $styles);
        $this->assertStringContainsString('@media (hover: hover) and (pointer: fine)', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('--rt-tabs-mobile-gutter: 0.75rem', $styles);
        $this->assertStringContainsString('margin-inline: 0 !important', $styles);
        $this->assertStringContainsString(".rt-tabs-v2.rt-tabs-shell[data-sticky-enabled='true'] .rt-tabs-carousel", $styles);
        $this->assertStringContainsString(".main-content:has(.rt-tabs-v2.rt-tabs-shell[data-sticky-enabled='true'])", $styles);
        $this->assertStringContainsString('position: sticky', $styles);
        $this->assertStringContainsString('max-width: 100%', $styles);
        $this->assertStringNotContainsString('calc(50% - 50vw)', $styles);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}/i', $styles);
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
        $this->assertStringContainsString('p-1 sm:p-1.5 shadow-rt-sm', $view);
        $this->assertStringContainsString('bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6', $view);
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

    public function test_shared_tabs_use_swiper_for_touch_drag_and_keep_clicks_and_keyboard_native(): void
    {
        $tabs = file_get_contents(resource_path('views/components/ui/accordion/tabs.blade.php'));
        $panel = file_get_contents(resource_path('views/components/ui/accordion/tab-panel.blade.php'));
        $styles = file_get_contents(resource_path('css/tabs-redesign.css'));
        $script = file_get_contents(resource_path('js/app.js'));
        $wagon = file_get_contents(resource_path('js/wagon-list-prototype.js'));

        $this->assertStringContainsString('data-tabs-input-policy="swiper-touch"', $tabs);
        $this->assertStringContainsString('new window.Swiper', $tabs);
        $this->assertStringContainsString("slidesPerView: 'auto'", $tabs);
        $this->assertStringContainsString('freeMode: {', $tabs);
        $this->assertStringContainsString('touchStartPreventDefault: false', $tabs);
        $this->assertStringContainsString('preventClicks: false', $tabs);
        $this->assertStringContainsString('preventClicksPropagation: false', $tabs);
        $this->assertStringContainsString('threshold: 5', $tabs);
        $this->assertStringContainsString('@click="selectTab(tab.id, true)"', $tabs);
        $this->assertStringContainsString('@keydown.right.prevent.stop="moveTab(1)"', $tabs);
        $this->assertStringContainsString('@keydown.home.prevent.stop="moveToBoundary(\'start\')"', $tabs);
        $this->assertStringContainsString(':tabindex="openTab === tab.id ? 0 : -1"', $tabs);
        $this->assertStringNotContainsString('@touchstart', $tabs);
        $this->assertStringNotContainsString('@pointerdown', $tabs);
        $this->assertStringNotContainsString('suppressTouchClick', $tabs);
        $this->assertStringNotContainsString('setPointerCapture', $tabs);
        $this->assertStringNotContainsString('@dragstart.prevent', $tabs);
        $this->assertStringContainsString(':data-tab-direction="tabDirection"', $tabs);
        $this->assertStringContainsString('keepSelectedPanelVisible()', $tabs);
        $this->assertStringContainsString('if (anchored || usefulContentVisible) return', $tabs);
        $this->assertStringContainsString('window.scrollTo({ top: target, behavior })', $tabs);
        $this->assertStringContainsString('window.gsap.fromTo(', $tabs);
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)').matches", $tabs);
        $this->assertStringContainsString('x-transition:enter="rt-tab-panel-transition"', $panel);
        $this->assertStringContainsString('x-transition:leave-end="rt-tab-panel-leave-end"', $panel);
        $this->assertStringContainsString("[data-tabs-input-policy='swiper-touch'][data-tab-direction='next']", $styles);
        $this->assertStringContainsString("[data-tabs-input-policy='swiper-touch'][data-tab-direction='previous']", $styles);
        $this->assertStringContainsString(".rt-tabs-carousel[data-swiping='true']", $styles);
        $this->assertStringContainsString('scroll-behavior: auto', $styles);
        $this->assertStringContainsString('@media (any-pointer: coarse)', $styles);
        $this->assertStringContainsString('touch-action: pan-y', $styles);
        $this->assertStringNotContainsString('cursor: grab', $styles);
        $this->assertStringNotContainsString('cursor: grabbing', $styles);
        $this->assertStringNotContainsString('\\"', $tabs);
        $this->assertStringContainsString("import { FreeMode } from 'swiper/modules'", $script);
        $this->assertStringContainsString('window.SwiperFreeMode = FreeMode', $script);
        $this->assertLessThan(
            strrpos($script, 'Livewire.start();'),
            strpos($script, 'window.SwiperFreeMode = FreeMode'),
        );
        $this->assertStringContainsString('initMobileSidebarSwipe()', $script);
        $this->assertStringContainsString('startsAtOpeningEdge', $script);
        $this->assertStringContainsString('setMobileSidebarOpen(true)', $script);
        $this->assertStringContainsString('setMobileSidebarOpen(false)', $script);
        $this->assertStringContainsString('wagonTouchStart', $wagon);
        $this->assertStringContainsString('nextMobileWagon()', $wagon);
        $this->assertStringContainsString('previousMobileWagon()', $wagon);
    }

    public function test_employee_person_preview_is_anchored_and_shared_modals_are_centered(): void
    {
        $employees = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));
        $row = file_get_contents(resource_path('views/components/tables/rows/employees/employee-row.blade.php'));
        $preview = file_get_contents(resource_path('views/components/user/person-anchor-preview.blade.php'));
        $modal = file_get_contents(resource_path('views/components/modal.blade.php'));
        $confirmation = file_get_contents(resource_path('views/components/ui/confirmation-dialog.blade.php'));

        $this->assertStringContainsString('<x-user.person-anchor-preview', $row);
        $this->assertStringContainsString('<x-user.public-info', $preview);
        $this->assertStringContainsString('<x-ui.dropdown.anchor-dropdown', $preview);
        $this->assertStringContainsString('content-role="dialog"', $preview);
        $this->assertStringNotContainsString('person-preview:open', $row);
        $this->assertStringNotContainsString('person-preview-modal', $employees);
        $this->assertStringContainsString('rt-modal-center-shell', $modal);
        $this->assertStringContainsString('my-auto max-h-[calc(100dvh-2rem)]', $modal);
        $this->assertStringContainsString('overflow-x-hidden', $modal);
        $this->assertStringContainsString('max-w-[calc(100vw-2rem)]', $modal);
        $this->assertStringContainsString('rt-modal-center-shell', $confirmation);
        $this->assertStringNotContainsString('flex min-h-full items-center justify-center', $confirmation);
    }

    public function test_file_explorer_uses_equal_compact_cards_and_tabbed_settings(): void
    {
        $view = file_get_contents(resource_path('views/livewire/tools/file-pools/manage-file-pools.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(1, substr_count($view, 'class="rt-file-explorer-grid'));
        $this->assertStringContainsString('Gemeinsames Explorer-Raster: zuerst Ordner, danach Dateien', $view);
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
