<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileAdminCommunicationUiTest extends TestCase
{
    public function test_admin_dashboards_keep_responsive_mobile_kpis(): void
    {
        $adminDashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));
        $managementDashboard = file_get_contents(resource_path('views/livewire/management-dashboard.blade.php'));

        $this->assertStringContainsString('rt-admin-hero', $adminDashboard);
        $this->assertStringContainsString('grid grid-cols-4 gap-1.5 sm:gap-2.5', $adminDashboard);
        $this->assertGreaterThanOrEqual(4, substr_count($adminDashboard, 'data-dashboard-count'));

        $this->assertStringContainsString('grid grid-cols-4 gap-1.5', $managementDashboard);
        $this->assertSame(4, substr_count($managementDashboard, ':compact-mobile="true"'));
    }

    public function test_chat_has_distinct_mobile_panes_and_rich_attachment_previews(): void
    {
        $view = file_get_contents(resource_path('views/livewire/chat-box.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $component = file_get_contents(app_path('Livewire/ChatBox.php'));
        $layout = file_get_contents(resource_path('views/layouts/master.blade.php'));

        $this->assertStringContainsString('x-data="chatPaneNavigation(', $view);
        $this->assertStringContainsString('x-on:touchstart.passive="touchStart($event)"', $view);
        $this->assertStringContainsString('x-on:touchend.passive="touchEnd($event)"', $view);
        $this->assertStringContainsString('x-on:click="showList()"', $view);
        $this->assertStringNotContainsString("\$set('selectedChatId', null)", $view);
        $this->assertStringContainsString('rt-chat-list-collapsed', $view);
        $this->assertStringContainsString("Alpine.data('chatPaneNavigation'", $script);
        $this->assertStringContainsString("deltaX > 0 && this.mobilePane === 'chat'", $script);
        $this->assertStringContainsString("deltaX < 0 && this.mobilePane === 'list'", $script);
        $this->assertStringNotContainsString("deltaX < 0 && this.mobilePane === 'chat'", $script);
        $this->assertStringNotContainsString("deltaX > 0 && this.mobilePane === 'list'", $script);
        $this->assertStringContainsString(".rt-chat-page[data-mobile-pane='chat']", $styles);
        $this->assertMatchesRegularExpression(
            "/\[data-mobile-pane='list'\] \.rt-chat-conversation-pane\s*\{[^}]*translateX\(100%\)/s",
            $styles
        );
        $this->assertMatchesRegularExpression(
            "/\[data-mobile-pane='chat'\] \.rt-chat-list-pane\s*\{[^}]*translateX\(-100%\)/s",
            $styles
        );
        $this->assertStringContainsString('@media (min-width: 768px)', $styles);
        $this->assertStringContainsString("['contentMode' => 'viewport']", $component);
        $this->assertStringContainsString('rt-viewport-layout', $layout);
        $this->assertStringNotContainsString('<x-ui.page', $view);
        $this->assertStringContainsString("str_starts_with(\$mime, 'image/')", $view);
        $this->assertStringContainsString("new CustomEvent('filepool-preview'", $view);
        $this->assertStringContainsString('x-data="chatAudioPlayer()"', $view);
        $this->assertStringContainsString("Alpine.data('chatAudioPlayer'", $script);
        $this->assertStringContainsString("message_type' => 'voice'", $component);
        $this->assertStringContainsString('wire:click="deleteMessage(', $view);
        $this->assertStringContainsString('@click="startRecording()"', $view);
        $this->assertStringContainsString('@click="toggleViewOnce()"', $view);
        $this->assertStringContainsString('x-show.important="!recording && !sendingVoice"', $view);
        $this->assertStringContainsString('x-show.important="recording || sendingVoice"', $view);
        $this->assertSame(2, substr_count($view, 'data-chat-composer-mode='));
        $this->assertStringContainsString('x-data="chatAudioPlayer({', file_get_contents(resource_path('views/components/chat/voice-message.blade.php')));
        $this->assertStringContainsString('$voiceFile = $message->voiceFile();', $view);
        $this->assertStringContainsString('x-show.important="consumed"', file_get_contents(resource_path('views/components/chat/voice-message.blade.php')));
        $this->assertStringContainsString('x-show.important="!consumed"', file_get_contents(resource_path('views/components/chat/voice-message.blade.php')));
        $this->assertStringContainsString('durationHint:', file_get_contents(resource_path('views/components/chat/voice-message.blade.php')));
        $this->assertStringContainsString('window.requestAnimationFrame(syncProgress)', $script);
    }

    public function test_active_mobile_sidebar_group_is_forced_open(): void
    {
        $component = file_get_contents(resource_path('views/components/menu/sidebar-nav-group.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-mobile-expanded=', $component);
        $this->assertStringContainsString('li[data-mobile-expanded="true"] > .mm-collapse', $styles);
        $this->assertStringContainsString('display: block !important', $styles);
        $this->assertStringContainsString(':not([data-sidebar-expanded="true"]) .vertical-menu .mm-collapse .sidebar-nav-link', $styles);
        $this->assertStringContainsString('padding-left: var(--webreach-sidebar-collapsed-padding) !important', $styles);
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
