<?php

namespace Tests\Feature;

use Tests\TestCase;

class SidebarAndSearchUiTest extends TestCase
{
    public function test_sidebar_and_ambient_shell_persist_across_livewire_navigation(): void
    {
        $master = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $link = file_get_contents(resource_path('views/components/menu/sidebar-nav-link.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));
        $motion = file_get_contents(resource_path('js/vengeance-motion.js'));

        $this->assertStringContainsString("@persist('railtime-sidebar-'.\$area)", $sidebar);
        $this->assertStringContainsString("@persist('railtime-shell-ambient')", $master);
        $this->assertStringContainsString('wire:current="active"', $link);
        $this->assertStringContainsString("Alpine.store('shell'", $script);
        $this->assertStringContainsString(
            'x-bind:data-sidebar-expanded="$store.shell?.desktopSidebarExpanded',
            $master,
        );
        $this->assertStringContainsString(
            "document.addEventListener('livewire:navigating', captureDesktopSidebarState);",
            $script,
        );
        $this->assertStringContainsString(
            "document.addEventListener('livewire:navigated', restoreDesktopSidebarState);",
            $script,
        );
        $this->assertStringContainsString('item.hasAttribute(\'data-current\')', $script);
        $this->assertStringContainsString('const ambientSnapshot = {', $motion);
        $this->assertStringContainsString('applyAmbientSnapshot(true);', $motion);
        $this->assertStringContainsString(
            "document.addEventListener('livewire:navigating', releaseCardGlowForNavigation);",
            $motion,
        );
        $this->assertStringNotContainsString(
            "document.addEventListener('livewire:navigating', resetGlow);",
            $motion,
        );
    }

    public function test_profile_is_standalone_and_help_and_support_share_a_support_submenu(): void
    {
        foreach (['admin-sidebar.blade.php', 'user-sidebar.blade.php'] as $sidebarView) {
            $sidebar = file_get_contents(resource_path('views/layouts/'.$sidebarView));
            $profilePosition = strpos($sidebar, "route('profile.show')");
            $supportGroupPosition = strpos(
                $sidebar,
                "<x-menu.sidebar-nav-group",
                $profilePosition === false ? 0 : $profilePosition,
            );
            $helpPosition = strpos($sidebar, "route('help')", $supportGroupPosition ?: 0);
            $supportPosition = strpos($sidebar, "route('support')", $supportGroupPosition ?: 0);

            $this->assertNotFalse($profilePosition, $sidebarView);
            $this->assertNotFalse($supportGroupPosition, $sidebarView);
            $this->assertNotFalse($helpPosition, $sidebarView);
            $this->assertNotFalse($supportPosition, $sidebarView);
            $this->assertGreaterThan($profilePosition, $supportGroupPosition, $sidebarView);
            $this->assertGreaterThan($supportGroupPosition, $helpPosition, $sidebarView);
            $this->assertGreaterThan($helpPosition, $supportPosition, $sidebarView);
            $this->assertSame(1, substr_count($sidebar, "route('profile.show')"), $sidebarView);
        }
    }

    public function test_sidebar_submenus_rebind_per_alpine_navigation_generation_and_scroll_locally(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $scrollHelper = file_get_contents(resource_path('js/sidebar-scroll.js'));
        $group = file_get_contents(resource_path('views/components/menu/sidebar-nav-group.blade.php'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));

        foreach (['admin-sidebar.blade.php', 'user-sidebar.blade.php'] as $sidebarView) {
            $sidebar = file_get_contents(resource_path('views/layouts/'.$sidebarView));

            $this->assertStringContainsString(
                '<ul id="side-menu" x-data="rtSidebarNavigation">',
                $sidebar,
                $sidebarView,
            );
        }

        $this->assertStringContainsString("Alpine.data('rtSidebarNavigation', sidebarNavigation);", $script);
        $this->assertStringContainsString('initMetisMenu(this.$root)', $script);
        $this->assertStringContainsString("this.\$root.addEventListener('shown.metisMenu'", $script);
        $this->assertStringContainsString('this.metisMenu.dispose();', $script);
        $this->assertStringContainsString('window.__rtSidebarGlobalInteractionsBound', $script);
        $this->assertStringContainsString(
            "document.addEventListener('livewire:navigated', queueAdminLayoutInit);",
            $script,
        );
        $this->assertStringNotContainsString("document.addEventListener('livewire:load'", $script);

        $this->assertStringContainsString("sidebar?.querySelector('.simplebar-content-wrapper')", $script);
        $this->assertStringContainsString("window.matchMedia?.('(prefers-reduced-motion: reduce)')", $script);
        $this->assertStringContainsString('scroller.scrollTo({ top, behavior });', $script);
        $this->assertStringNotContainsString('scrollIntoView(', $script);
        $this->assertStringNotContainsString('window.scrollTo(', $script);
        $this->assertStringContainsString("return prefersReducedMotion ? 'auto' : 'smooth';", $scrollHelper);
        $this->assertStringContainsString('sidebar-nav-link__chevron', $group);
        $this->assertStringContainsString('aria-expanded="{{ $active ? \'true\' : \'false\' }}"', $group);
        $this->assertStringContainsString("[aria-expanded='true'] .sidebar-nav-link__chevron", $shellStyles);
        $this->assertStringContainsString('transform: rotate(90deg)', $shellStyles);
        $this->assertMatchesRegularExpression(
            '/\.rt-shell-sidebar \.sidebar-nav-link__chevron\s*\{[^}]*border:\s*0;[^}]*background:\s*transparent;/s',
            $shellStyles,
        );
    }

    public function test_desktop_sidebar_opens_after_half_the_close_delay_and_clicks_immediately(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));

        $this->assertStringContainsString('const DESKTOP_SIDEBAR_EXPAND_DELAY = 750;', $script);
        $this->assertStringContainsString('const DESKTOP_SIDEBAR_COLLAPSE_DELAY = 1500;', $script);
        $this->assertStringContainsString('}, DESKTOP_SIDEBAR_EXPAND_DELAY);', $script);
        $this->assertStringContainsString('}, DESKTOP_SIDEBAR_COLLAPSE_DELAY);', $script);
        $this->assertStringContainsString('scheduleDesktopSidebarExpand();', $script);
        $this->assertStringContainsString('clearSidebarExpandTimer();', $script);
        $this->assertStringContainsString("element.addEventListener('mouseenter'", $script);
        $this->assertStringContainsString("element.addEventListener('mouseleave'", $script);
        $this->assertStringContainsString("element.addEventListener('focusin'", $script);
        $this->assertStringContainsString('clearSidebarCollapseTimer();', $script);
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\(\\s*'pointerdown'/s",
            $script,
        );
        $this->assertStringContainsString("target?.closest('.vertical-menu, .topbar-brand')", $script);
        $this->assertStringContainsString('520ms cubic-bezier(0.22, 1, 0.36, 1)', $styles);
        $this->assertStringContainsString('will-change: transform, opacity', $styles);
        $this->assertMatchesRegularExpression(
            '/\.rt-shell-sidebar #sidebar-menu\s*\{\s*padding-inline:\s*0;/s',
            $shellStyles,
        );
        $this->assertMatchesRegularExpression(
            '/\[data-rt-sidebar-group\]\s*\{[^}]*border-radius:\s*0\s*!important;/s',
            $shellStyles,
        );
    }

    public function test_shared_expandable_search_is_used_by_every_shared_data_table_and_topbar(): void
    {
        $component = file_get_contents(resource_path('views/components/tables/search-field.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));
        $topbar = file_get_contents(resource_path('views/layouts/topbar.blade.php'));

        $this->assertStringContainsString("'is-expanded': expanded", $component);
        $this->assertStringContainsString("x-show=\"String(value ?? '').length > 0\"", $component);
        $this->assertStringContainsString('x-on:click="clear()"', $component);
        $this->assertStringContainsString('data-search-context="{{ $searchContext }}"', $component);
        $this->assertStringContainsString('.rt-expandable-search.is-expanded', $styles);
        $this->assertStringContainsString("data-search-context='topbar'", $styles);
        $this->assertStringContainsString(".rt-expandable-search[data-search-context='topbar'] .rt-expandable-search__input:focus", $shellStyles);
        $this->assertStringContainsString('.is-expanded:focus-within', $shellStyles);
        $this->assertStringContainsString('border-color: transparent !important', $shellStyles);
        $this->assertStringNotContainsString('inset 0 -2px 0 rgba(228, 0, 43, 0.52)', $shellStyles);
        $this->assertStringContainsString('border-radius: 999px', $shellStyles);
        $this->assertStringNotContainsString('outline: 2px solid rgba(228, 0, 43, 0.38)', $shellStyles);
        $this->assertStringContainsString('<livewire:tools.global-search />', $topbar);
        $this->assertStringContainsString('<div class="relative" data-topbar-profile>', $topbar);
        $this->assertStringNotContainsString('<div class="relative ms-1 sm:ms-2" data-topbar-profile>', $topbar);

        foreach ([
            'livewire/admin/employees.blade.php',
            'livewire/admin/admin-tasks-list.blade.php',
            'livewire/admin/mail-management.blade.php',
            'livewire/message-box.blade.php',
        ] as $view) {
            $this->assertStringContainsString(
                '<x-tables.search-field',
                file_get_contents(resource_path('views/'.$view)),
                $view,
            );
        }
    }

    public function test_global_search_modal_only_renders_server_generated_result_links(): void
    {
        $component = file_get_contents(app_path('Livewire/Tools/GlobalSearch.php'));
        $searchField = file_get_contents(resource_path('views/components/tables/search-field.blade.php'));
        $view = file_get_contents(resource_path('views/livewire/tools/global-search.blade.php'));

        $this->assertStringContainsString('Gate::allows(', $component);
        $this->assertStringContainsString('$user->receivedMessages()', $component);
        $this->assertStringContainsString('$user->chats()', $component);
        $this->assertStringContainsString("route('chat', ['chat' => \$chat->id])", $component);
        $this->assertStringContainsString('x-on:keydown.enter.prevent="$wire.openResults', $searchField);
        $this->assertStringContainsString('x-on:submit.prevent="$wire.openResults', $view);
        $this->assertStringContainsString('href="{{ $result[\'url\'] }}"', $view);
        $this->assertStringContainsString('role="dialog"', $view);
        $this->assertStringContainsString('aria-modal="true"', $view);
        $this->assertStringNotContainsString('{!! $result', $view);
    }
}
