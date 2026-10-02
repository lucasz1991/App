<?php

namespace Tests\Feature;

use Tests\TestCase;

class CompactStaffTimelineUiTest extends TestCase
{
    public function test_timeline_uses_shared_people_compact_rows_and_native_snapping_without_empty_markers(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        foreach (['rtStaffTimeline', 'snap-both snap-mandatory', 'snap-start', 'data-no-sidebar-swipe', 'canScrollLeft', 'canScrollRight', 'x-user.public-info', 'x-user.person-anchor-preview', 'x-intersect.once="$wire.loadMore()"'] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        $this->assertStringNotContainsString('rt-personnel-timeline-no-entry', $view);
        $this->assertStringNotContainsString('Kein Eintrag', $view);
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringNotContainsString('min-height: 74px', $css);
        $this->assertStringContainsString('min-height: 48px', $css);
        $this->assertStringContainsString('scroll-padding-left: var(--timeline-name-width)', $css);
    }

    public function test_period_trigger_has_no_timezone_subtitle_and_shell_uses_global_drawer(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/operations/shift-management.blade.php'));
        $this->assertStringNotContainsString('class="rt-calendar-timezone"', $view);
        $shell = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $this->assertStringContainsString('data-sidebar-drawer="true"', $shell);
        $this->assertStringContainsString('data-sidebar-collapsible="false"', $shell);
        $topbar = file_get_contents(resource_path('views/layouts/topbar.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/class="vertical-menu-btn[^"\r\n]*lg:hidden/', $topbar);
    }
}
