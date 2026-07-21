<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileAdminCommunicationUiTest extends TestCase
{
    public function test_admin_dashboards_keep_four_compact_kpis_on_mobile(): void
    {
        foreach ([
            resource_path('views/livewire/admin/dashboard.blade.php'),
            resource_path('views/livewire/management-dashboard.blade.php'),
        ] as $view) {
            $contents = file_get_contents($view);

            $this->assertStringContainsString('grid grid-cols-4 gap-1.5', $contents);
            $this->assertSame(4, substr_count($contents, ':compact-mobile="true"'));
        }
    }

    public function test_chat_has_distinct_mobile_panes_and_rich_attachment_previews(): void
    {
        $view = file_get_contents(resource_path('views/livewire/chat-box.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("\$selectedChat ? 'hidden md:flex' : 'flex'", $view);
        $this->assertStringContainsString("\$selectedChat ? 'flex' : 'hidden md:flex'", $view);
        $this->assertStringContainsString("wire:click=\"\$set('selectedChatId', null)\"", $view);
        $this->assertStringContainsString("str_starts_with(\$mime, 'image/')", $view);
        $this->assertStringContainsString("new CustomEvent('filepool-preview'", $view);
        $this->assertStringContainsString('x-data="chatAudioPlayer()"', $view);
        $this->assertStringContainsString("Alpine.data('chatAudioPlayer'", $script);
    }

    public function test_active_mobile_sidebar_group_is_forced_open(): void
    {
        $component = file_get_contents(resource_path('views/components/menu/sidebar-nav-group.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-mobile-expanded=', $component);
        $this->assertStringContainsString('li[data-mobile-expanded="true"] > .mm-collapse', $styles);
        $this->assertStringContainsString('display: block !important', $styles);
    }

    public function test_messages_use_full_width_mobile_rows(): void
    {
        $row = file_get_contents(resource_path('views/components/tables/rows/user-messages/row.blade.php'));

        $this->assertGreaterThanOrEqual(3, substr_count($row, 'col-span-2'));
        $this->assertStringContainsString('md:col-span-1', $row);
    }

    public function test_table_action_containers_do_not_trap_open_dropdowns_in_row_stacking_contexts(): void
    {
        $table = file_get_contents(resource_path('views/components/tables/table.blade.php'));

        $this->assertStringContainsString(
            'rt-table-row-actions absolute right-2 inset-y-0 flex items-center',
            $table
        );
        $this->assertStringNotContainsString('rt-table-row-actions absolute right-2 top-1/2 z-20', $table);
        $this->assertStringNotContainsString('rt-table-row-actions absolute right-2 top-1/2 z-20 -translate-y-1/2', $table);
    }
}
