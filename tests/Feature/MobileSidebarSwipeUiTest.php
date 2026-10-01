<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileSidebarSwipeUiTest extends TestCase
{
    public function test_mobile_sidebar_swipe_opens_only_at_the_edge_and_respects_scroll_owners(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $resolver = file_get_contents(resource_path('js/mobile-sidebar-swipe.js'));
        $tabs = file_get_contents(resource_path('views/components/ui/accordion/tabs.blade.php'));
        $chat = file_get_contents(resource_path('views/livewire/chat-box.blade.php'));
        $wagon = file_get_contents(resource_path('views/livewire/operations/partials/wagon-mobile-wizard.blade.php'));

        $this->assertStringContainsString("from './mobile-sidebar-swipe'", $script);
        $this->assertStringContainsString('initMobileSidebarSwipe()', $script);
        $this->assertStringContainsString('window.__rtMobileSidebarSwipeBound', $script);
        $this->assertStringContainsString("document.addEventListener('touchstart'", $script);
        $this->assertStringContainsString("document.addEventListener('touchend'", $script);
        $this->assertStringContainsString("document.addEventListener('touchcancel'", $script);
        $this->assertStringContainsString("document.addEventListener('livewire:navigating'", $script);
        $this->assertStringContainsString('event.preventDefault();', $script);
        $this->assertStringContainsString("action === 'open'", $script);
        $this->assertStringContainsString("action === 'close'", $script);
        $this->assertStringContainsString("document.getElementById('app-sidebar')?.isConnected !== true", $script);
        $this->assertStringContainsString('beginSidebarDrag({', $script);
        $this->assertStringContainsString('isMobileSidebarSwipeExcluded(target)', $script);
        $this->assertStringContainsString('sidebarGestureIsCurrent()', $script);
        $this->assertStringContainsString('event.touches[0].identifier !== sidebarSwipeStart.identifier', $script);
        $this->assertStringContainsString('event.changedTouches[0].identifier !== sidebarSwipeStart.identifier', $script);
        $this->assertStringContainsString("window.addEventListener('resize', cancelSidebarSwipe", $script);
        $this->assertStringContainsString("document.addEventListener('scroll'", $script);
        $this->assertStringContainsString('sidebarDragState.rejected', $script);

        $this->assertStringContainsString('MOBILE_SIDEBAR_BREAKPOINT = 1024', $resolver);
        $this->assertStringContainsString('MOBILE_SIDEBAR_EDGE_ZONE_PX = 28', $resolver);
        $this->assertStringContainsString('!open && x > MOBILE_SIDEBAR_EDGE_ZONE_PX', $resolver);
        $this->assertStringContainsString('!sidebarOpen && resolvedStartX > MOBILE_SIDEBAR_EDGE_ZONE_PX', $resolver);
        $this->assertStringContainsString('element.scrollWidth > element.clientWidth + 1', $resolver);
        $this->assertStringContainsString('getComputedStyle(element).overflowX', $resolver);
        $this->assertStringContainsString('element = element.parentElement', $resolver);
        $this->assertStringNotContainsString('element.scrollLeft', $resolver);
        $this->assertStringContainsString('[data-no-sidebar-swipe]', $resolver);
        $this->assertStringContainsString('resolveMobileSidebarSwipe', $resolver);

        foreach ([$tabs, $chat, $wagon] as $gestureOwner) {
            $this->assertStringContainsString('data-no-sidebar-swipe', $gestureOwner);
        }
    }
}
