<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatRedesignTest extends TestCase
{
    public function test_chat_surface_is_split_into_morph_safe_feature_partials(): void
    {
        $view = file_get_contents(resource_path('views/livewire/chat-box.blade.php'));

        $this->assertStringContainsString('livewire.chat.partials.chat-list', $view);
        $this->assertStringContainsString('livewire.chat.partials.conversation', $view);
        $this->assertStringContainsString('livewire.chat.partials.new-chat-modal', $view);
        $this->assertStringContainsString('livewire.chat.partials.chat-options-modals', $view);
        $this->assertStringContainsString('data-chat-redesign="vengeance"', $view);
        $this->assertStringContainsString('data-anim="zoom"', $view);
        $this->assertStringContainsString('overflow-hidden px-0 pb-3', $view);
        $this->assertStringNotContainsString('px-3 pb-3', $view);
        $this->assertStringNotContainsString('sm:px-4', $view);
    }

    public function test_chat_redesign_keeps_livewire_and_accessibility_anchors(): void
    {
        $partials = collect(glob(resource_path('views/livewire/chat/partials/*.blade.php')))
            ->map(fn (string $path): string => file_get_contents($path))
            ->implode("\n");

        $this->assertStringContainsString('wire:poll.2s="pollTick"', $partials);
        $this->assertStringContainsString('wire:submit.prevent="send"', $partials);
        $this->assertStringContainsString('x-data="chatTranscriptScroll()"', $partials);
        $this->assertStringNotContainsString('$cleanup', $partials);
        $this->assertStringContainsString('data-no-chat-swipe', $partials);
        $this->assertSame(2, substr_count($partials, 'data-chat-list-toggle'));
        $this->assertStringNotContainsString('mx-auto flex w-full max-w-4xl', $partials);
        $this->assertStringContainsString('role="tablist"', $partials);
        $this->assertStringContainsString('aria-selected=', $partials);
        $this->assertStringContainsString('aria-controls="rt-new-chat-panel-direct"', $partials);
        $this->assertStringContainsString('wire:click="requestDeleteChat"', $partials);
        $this->assertStringContainsString("route('chat.export'", $partials);
        $this->assertStringContainsString('focus-visible:', $partials);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("Alpine.data('chatTranscriptScroll'", $script);
        $this->assertStringContainsString('destroy() {', $script);
        $this->assertStringContainsString('this.messageObserver?.disconnect()', $script);
    }

    public function test_chat_styles_cover_dark_mode_small_screens_and_reduced_motion(): void
    {
        $styles = file_get_contents(resource_path('css/chat-redesign.css'));

        $this->assertStringContainsString("body[data-mode='dark'] .rt-chat-page", $styles);
        $this->assertStringContainsString('@media (max-width: 359.98px)', $styles);
        $this->assertStringContainsString('@media (min-width: 768px)', $styles);
        $this->assertStringContainsString('width: 21.5rem !important', $styles);
        $this->assertStringContainsString('flex: 1 1 0% !important', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('--chat-panel:', $styles);
        $this->assertStringContainsString("theme('colors.rt-ink')", $styles);
        $this->assertStringNotContainsString("theme('colors.rt.ink')", $styles);
        $this->assertMatchesRegularExpression(
            '/\.rt-chat-conversation-header\s*\{[^}]*position:\s*sticky;[^}]*top:\s*0;/s',
            $styles,
        );
    }

    public function test_mobile_chat_tracks_the_visual_viewport_and_cleans_up_its_listeners(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/chat-redesign.css'));

        $this->assertStringContainsString('window.visualViewport?.addEventListener(\'resize\'', $script);
        $this->assertStringContainsString('window.visualViewport?.addEventListener(\'scroll\'', $script);
        $this->assertStringContainsString('window.visualViewport?.removeEventListener(\'resize\'', $script);
        $this->assertStringContainsString('window.visualViewport?.removeEventListener(\'scroll\'', $script);
        $this->assertStringContainsString('window.addEventListener(\'resize\', this.viewportHandler', $script);
        $this->assertStringContainsString('window.removeEventListener(\'resize\', this.viewportHandler', $script);
        $this->assertStringContainsString('window.requestAnimationFrame', $script);
        $this->assertStringContainsString('window.cancelAnimationFrame', $script);
        $this->assertStringContainsString("'--rt-chat-visual-height'", $script);
        $this->assertStringContainsString("'--rt-chat-visual-top'", $script);
        $this->assertStringContainsString("removeProperty('--rt-chat-visual-height')", $script);
        $this->assertStringContainsString("removeProperty('--rt-chat-visual-top')", $script);

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\)\s*\{\s*\.rt-chat-page\s*\{[^}]*'
            .'--rt-chat-visual-height,\s*100dvh\)[^}]*-\s*70px[^}]*'
            .'--rt-chat-visual-top,\s*0px\)/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(hover: none\) and \(pointer: coarse\)\s*\{[^}]*'
            .'\.rt-chat-composer input\[type=\'text\'\]\s*\{[^}]*font-size:\s*1rem\s*!important;/s',
            $styles,
        );
    }

    public function test_mobile_chat_panes_fill_the_same_absolute_slot_instead_of_collapsing_the_conversation(): void
    {
        $styles = file_get_contents(resource_path('css/chat-redesign.css'));
        $list = file_get_contents(resource_path('views/livewire/chat/partials/chat-list.blade.php'));
        $header = file_get_contents(resource_path('views/livewire/chat/partials/conversation-header.blade.php'));

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\)\s*\{\s*'
            .'(?:\.rt-chat-page\s*\{[^}]*\}\s*)?\/\*.*?\*\/\s*\.rt-chat-list-pane,\s*'
            .'\.rt-chat-conversation-pane\s*\{[^}]*position:\s*absolute;[^}]*inset:\s*0;'
            .'[^}]*width:\s*100%\s*!important;[^}]*min-width:\s*0\s*!important;[^}]*height:\s*100%;/s',
            $styles,
        );

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\)\s*\{.*?\.rt-chat-list-pane\s*\{[^}]*'
            .'width:\s*21\.5rem\s*!important;[^}]*flex-basis:\s*21\.5rem\s*!important;/s',
            $styles,
        );
        $this->assertStringContainsString('flex: 1 1 0% !important', $styles);
        $this->assertStringContainsString('[data-chat-list-toggle]', $styles);
        $this->assertStringContainsString('display: inline-flex !important', $styles);
        $this->assertStringContainsString('padding-inline: clamp(1rem, 2vw, 2rem) !important', $styles);
        $this->assertStringContainsString('data-chat-mobile-back', $header);
        $this->assertStringContainsString('.rt-chat-mobile-back', $styles);
        $this->assertStringNotContainsString('class="md:hidden"', $header);
        $this->assertStringNotContainsString('App-Vollflaeche', $styles);
        $this->assertStringNotContainsString('padding: 0 !important', $styles);
        $this->assertStringNotContainsString('rt-chat-list--searching', $styles);
        $this->assertStringNotContainsString('rt-chat-list--searching', $list);
    }
}
