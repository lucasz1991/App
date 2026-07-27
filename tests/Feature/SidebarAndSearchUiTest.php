<?php

namespace Tests\Feature;

use Tests\TestCase;

class SidebarAndSearchUiTest extends TestCase
{
    public function test_desktop_sidebar_uses_two_second_delayed_collapse_and_immediate_content_close(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('const DESKTOP_SIDEBAR_COLLAPSE_DELAY = 2000;', $script);
        $this->assertStringContainsString('}, DESKTOP_SIDEBAR_COLLAPSE_DELAY);', $script);
        $this->assertStringContainsString("element.addEventListener('mouseenter'", $script);
        $this->assertStringContainsString("element.addEventListener('mouseleave'", $script);
        $this->assertStringContainsString("element.addEventListener('focusin'", $script);
        $this->assertStringContainsString('clearSidebarCollapseTimer();', $script);
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\(\\s*'pointerdown'/s",
            $script,
        );
        $this->assertStringContainsString("!target.closest('.vertical-menu, .topbar-brand')", $script);
        $this->assertStringContainsString('420ms cubic-bezier(0.22, 1, 0.36, 1)', $styles);
        $this->assertStringContainsString('will-change: transform, opacity', $styles);
    }

    public function test_shared_expandable_search_is_used_by_every_shared_data_table_and_topbar(): void
    {
        $component = file_get_contents(resource_path('views/components/tables/search-field.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $topbar = file_get_contents(resource_path('views/layouts/topbar.blade.php'));

        $this->assertStringContainsString("'is-expanded': expanded", $component);
        $this->assertStringContainsString("x-show=\"String(value ?? '').length > 0\"", $component);
        $this->assertStringContainsString('x-on:click="clear()"', $component);
        $this->assertStringContainsString('data-search-context="{{ $searchContext }}"', $component);
        $this->assertStringContainsString('.rt-expandable-search.is-expanded', $styles);
        $this->assertStringContainsString("data-search-context='topbar'", $styles);
        $this->assertStringContainsString('<livewire:tools.global-search />', $topbar);

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
