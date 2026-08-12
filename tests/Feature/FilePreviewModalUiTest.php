<?php

namespace Tests\Feature;

use Tests\TestCase;

class FilePreviewModalUiTest extends TestCase
{
    public function test_dialog_modal_exposes_one_top_right_close_button_and_optional_horizontal_toolbar(): void
    {
        $dialog = file_get_contents(resource_path('views/components/dialog-modal.blade.php'));

        $this->assertSame(1, substr_count($dialog, 'data-dialog-close'));
        $this->assertSame(1, substr_count($dialog, 'x-on:click="$dispatch(\'close\')"'));
        $this->assertStringContainsString('grid-cols-[minmax(0,1fr)_auto]', $dialog);
        $this->assertStringContainsString('@isset($headerActions)', $dialog);
        $this->assertStringContainsString('data-dialog-header-toolbar', $dialog);
        $this->assertStringContainsString('data-dialog-header-actions', $dialog);
        $this->assertStringContainsString('class="flex items-center gap-1"', $dialog);
        $this->assertStringNotContainsString('data-dialog-header-action-rail', $dialog);
        $this->assertStringNotContainsString('flex-col items-center', $dialog);
        $this->assertStringContainsString('role="toolbar"', $dialog);
    }

    public function test_file_preview_uses_its_single_fullscreen_close_and_keeps_actions_in_the_header(): void
    {
        $preview = file_get_contents(resource_path('views/livewire/tools/file-pools/file-preview-modal.blade.php'));

        $this->assertStringContainsString('<x-ui.fullscreen-modal', $preview);
        $this->assertStringContainsString('<x-slot:actions>', $preview);
        $this->assertStringContainsString(':show-default-close="false"', $preview);
        $this->assertSame(1, substr_count($preview, 'data-file-preview-close'));
        $this->assertStringContainsString('data-file-preview-action="download"', $preview);
        $this->assertStringContainsString('data-file-preview-action="print"', $preview);
        $this->assertStringContainsString('data-file-preview-action="open"', $preview);
        $this->assertStringNotContainsString('wire:click="close"', $preview);
        $this->assertStringNotContainsString('fa-times', $preview);
        $this->assertStringNotContainsString('<x-dialog-modal', $preview);
        $this->assertStringNotContainsString('<x-slot:footer>', $preview);
        $this->assertStringNotContainsString('data-file-preview-footer-actions', $preview);
    }

    public function test_dialog_modal_only_renders_a_footer_when_a_footer_slot_exists(): void
    {
        $dialog = file_get_contents(resource_path('views/components/dialog-modal.blade.php'));

        $footerGuardPosition = strpos($dialog, '@isset($footer)');
        $footerPosition = strpos($dialog, '<footer class="rt-modal-footer');

        $this->assertNotFalse($footerGuardPosition);
        $this->assertNotFalse($footerPosition);
        $this->assertLessThan($footerPosition, $footerGuardPosition);
    }

    public function test_file_preview_media_is_mobile_safe_and_accessible(): void
    {
        $preview = file_get_contents(resource_path('views/livewire/tools/file-pools/file-preview-modal.blade.php'));
        $styles = file_get_contents(resource_path('css/file-preview.css'));

        $this->assertStringContainsString('playsinline', $preview);
        $this->assertStringContainsString('preload="metadata"', $preview);
        $this->assertStringContainsString('wire:key="file-preview-stage-', $preview);
        $this->assertStringContainsString('data-file-preview-image-stage', $preview);
        $this->assertStringContainsString('data-file-preview-mobile-menu', $preview);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $styles);
        $this->assertStringContainsString('title="{{ $file->name_with_extension ?? $file->name }}"', $preview);
        $this->assertStringNotContainsString('min-h-[420px]', $preview);
        $this->assertStringNotContainsString('h-[75vh]', $preview);
    }
}
