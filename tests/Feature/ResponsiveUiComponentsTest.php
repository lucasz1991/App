<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ResponsiveUiComponentsTest extends TestCase
{
    public function test_legacy_dropdown_alias_renders_the_shared_anchor_dropdown(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-dropdown align="left" width="96">
                <x-slot:trigger><button type="button">Öffnen</button></x-slot:trigger>
                <x-slot:content><button type="button">Aktion</button></x-slot:content>
            </x-dropdown>
        BLADE);

        $this->assertStringContainsString('x-anchor.bottom-start.offset.8.flip.shift', $html);
        $this->assertStringContainsString('w-96', $html);
        $this->assertStringContainsString('role="menu"', $html);
    }

    public function test_table_uses_mobile_summary_grid_and_fixed_right_actions(): void
    {
        $item = (object) ['id' => 7, 'status' => 1];

        $html = Blade::render(<<<'BLADE'
            <x-tables.table
                :columns="$columns"
                :items="$items"
                actions-view="components.tables.rows.user-messages.actions"
            />
        BLADE, [
            'columns' => [
                ['label' => 'Name', 'key' => 'name', 'width' => '60%', 'hideOn' => 'none'],
                ['label' => 'Status', 'key' => 'status', 'width' => '40%', 'hideOn' => 'none'],
            ],
            'items' => collect([$item]),
        ]);

        $this->assertStringContainsString('rt-table-row-grid', $html);
        $this->assertStringContainsString('rt-table-row-actions absolute right-2', $html);
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
    }

    public function test_toast_script_replaces_old_listeners_and_suppresses_immediate_duplicates(): void
    {
        $script = file_get_contents(public_path('js/rt-toast.js'));

        $this->assertStringContainsString('window.__rtToastAbortController.abort()', $script);
        $this->assertStringContainsString('signal: listenerController.signal', $script);
        $this->assertStringContainsString('return now - lastShownAt < 500', $script);
    }

    public function test_employee_header_has_one_mobile_actions_dropdown(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));

        $this->assertSame(1, substr_count($view, '<div class="sm:hidden">'));
        $this->assertStringContainsString('<x-ui.dropdown.anchor-dropdown align="right" width="64">', $view);
        $this->assertStringContainsString(':label="__(\'app.actions\')"', $view);
    }
}
