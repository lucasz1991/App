<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoadingSkeletonUiTest extends TestCase
{
    public function test_shared_page_uses_a_delayed_layout_skeleton_for_livewire_requests(): void
    {
        $page = file_get_contents(resource_path('views/components/ui/page.blade.php'));
        $skeleton = file_get_contents(resource_path('views/components/ui/loading/skeleton.blade.php'));

        $this->assertStringContainsString('wire:loading.delay.long', $page);
        $this->assertStringContainsString('wire:loading.attr.delay.long="inert"', $page);
        $this->assertStringContainsString('data-page-loading-skeleton', $page);
        $this->assertStringContainsString('data-page-live-content', $page);
        $this->assertStringContainsString("['page', 'list', 'table', 'card']", $skeleton);
        $this->assertStringContainsString('aria-busy="true"', $skeleton);
    }

    public function test_existing_lazy_profile_messages_use_the_shared_skeleton_placeholder(): void
    {
        $component = file_get_contents(app_path('Livewire/Admin/UserProfile/UserMessages.php'));
        $placeholder = file_get_contents(resource_path('views/livewire/placeholders/page-skeleton.blade.php'));

        $this->assertStringContainsString("view('livewire.placeholders.page-skeleton'", $component);
        $this->assertStringContainsString("'variant' => 'list'", $component);
        $this->assertStringContainsString('<x-ui.loading.skeleton', $placeholder);
    }

    public function test_skeleton_motion_is_transform_only_and_respects_reduced_motion(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('@keyframes rt-skeleton-shimmer', $styles);
        $this->assertStringContainsString('transform: translateX(125%)', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('.rt-loading-skeleton__cards', $styles);
    }
}
