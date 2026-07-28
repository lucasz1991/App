<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SharedConfirmationDialogUiTest extends TestCase
{
    public function test_shared_layout_exposes_one_accessible_asynchronous_confirmation_dialog(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $component = file_get_contents(resource_path('views/components/ui/confirmation-dialog.blade.php'));
        $rendered = Blade::render('<x-ui.confirmation-dialog />');

        $this->assertSame(1, substr_count($layout, '<x-ui.confirmation-dialog />'));
        $this->assertStringContainsString('data-rt-confirmation-dialog', $rendered);
        $this->assertStringContainsString('role="alertdialog"', $rendered);
        $this->assertStringContainsString('aria-modal="true"', $rendered);

        $this->assertStringContainsString('x-on:rt-confirm.window="ask($event)"', $component);
        $this->assertStringContainsString('x-on:keydown.escape.window="if (open && !busy) close()"', $component);
        $this->assertStringContainsString('x-trap.inert.noscroll="open"', $component);
        $this->assertStringContainsString("this.variant === 'destructive'", $component);
        $this->assertStringContainsString('this.$refs.cancelButton', $component);
        $this->assertStringContainsString('this.$refs.confirmButton', $component);
        $this->assertStringContainsString('opener.focus()', $component);
        $this->assertStringContainsString('await this.request.action()', $component);
        $this->assertStringContainsString('this.close(true, false)', $component);
        $this->assertStringContainsString("typeof cancelAction === 'function'", $component);
        $this->assertStringContainsString("detail.variant === 'destructive'", $component);
        $this->assertStringContainsString("variant !== 'destructive'", $component);
        $this->assertStringContainsString('x-bind:disabled="busy"', $component);
        $this->assertStringContainsString('x-on:click="close()"', $component);
        $this->assertStringContainsString('window.Dropzone.confirm =', $component);
    }

    public function test_productive_delete_and_restore_actions_no_longer_use_browser_native_confirms(): void
    {
        $relativePaths = [
            'livewire/admin/user-profile.blade.php',
            'livewire/admin/user-profile/user-notes.blade.php',
            'livewire/admin/user-profile/user-messages.blade.php',
            'livewire/admin/user-profile/employee-documents.blade.php',
            'livewire/admin/managed-documents.blade.php',
            'livewire/tools/file-pools/manage-file-pools.blade.php',
            'components/tables/rows/employees/employee-actions.blade.php',
            'components/ui/filepool/file-card.blade.php',
        ];

        $views = collect($relativePaths)
            ->mapWithKeys(fn (string $path): array => [
                $path => file_get_contents(resource_path('views/'.$path)),
            ]);

        foreach ($views as $path => $view) {
            $this->assertStringNotContainsString('wire:confirm', $view, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:window\.)?confirm\s*\(/',
                $view,
                $path,
            );
        }

        $allViews = $views->implode("\n");

        $this->assertSame(9, substr_count($allViews, '$dispatch("rt-confirm"'));
        $this->assertGreaterThanOrEqual(2, substr_count($allViews, 'action: () => $wire.deleteUser('));
        $this->assertStringContainsString('action: () => $wire.deleteNote(', $allViews);
        $this->assertStringContainsString('action: () => $wire.deleteMessage(', $allViews);
        $this->assertStringContainsString('action: () => $wire.remove(', $allViews);
        $this->assertStringContainsString('action: () => $wire.restoreVersion(', $allViews);
        $this->assertStringContainsString('action: () => $wire.deleteFile(', $allViews);
        $this->assertSame(2, substr_count($allViews, 'action: () => $wire.deleteFolder('));
    }

    public function test_productive_javascript_confirmations_use_the_same_dialog_contract(): void
    {
        $wagonList = file_get_contents(resource_path('js/wagon-list-prototype.js'));
        $component = file_get_contents(resource_path('views/components/ui/confirmation-dialog.blade.php'));

        $this->assertStringNotContainsString('window.confirm(', $wagonList);
        $this->assertStringNotContainsString('window.Swal.fire(', $wagonList);
        $this->assertStringContainsString("new CustomEvent('rt-confirm'", $wagonList);
        $this->assertStringContainsString("variant: 'destructive'", $wagonList);
        $this->assertStringContainsString('action: () => finish(true)', $wagonList);
        $this->assertStringContainsString('cancel: () => finish(false)', $wagonList);

        $this->assertStringContainsString('window.Dropzone.confirm =', $component);
        $this->assertStringContainsString('action: () => accepted?.()', $component);
        $this->assertStringContainsString('cancel: () => rejected?.()', $component);
    }
}
