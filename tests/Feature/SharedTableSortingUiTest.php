<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SharedTableSortingUiTest extends TestCase
{
    public function test_direct_sorting_uses_the_owner_and_announces_direction_in_desktop_and_mobile_controls(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-tables.table :columns="[['label'=>'Vorgang','key'=>'title','sortable'=>true], ['label'=>'Status','key'=>'status']]"
                sort-action="tableSort" sort-by="title" sort-dir="asc" table-key="inquiries" :items="collect([(object)['id'=>7]])" />
        BLADE);

        $this->assertStringContainsString('role="columnheader"', $html);
        $this->assertStringContainsString('role="table"', $html);
        $this->assertStringContainsString('Sortierung: Vorgang, aufsteigend. Sortierung ändern', $html);
        $this->assertStringContainsString('role="menuitemradio" aria-checked="true"', $html);
        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $this->assertStringContainsString('Vorgang absteigend sortieren', $html);
        $this->assertStringContainsString('wire:click="tableSort(&quot;title&quot;, &quot;desc&quot;)"', $html);
        $this->assertStringContainsString('wire:key="inquiries-row-7"', $html);
        $this->assertStringContainsString('rt-table-mobile-sort__option', $html);
        $this->assertStringContainsString('far fa-arrow-up', $html);
        $this->assertStringNotContainsString('$'."dispatch('table-sort'", $html);
        $this->assertStringNotContainsString('data-sort-key="status"', $html);
    }

    public function test_legacy_employee_sort_event_remains_available_without_a_direct_action(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-tables.table :columns="[['label'=>'Name','key'=>'name','sortable'=>true]]" sort-by="name" sort-dir="desc" />
        BLADE);

        $this->assertStringContainsString('$'."dispatch('table-sort'", $html);
        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertStringContainsString('Name aufsteigend sortieren', $html);
        $this->assertStringNotContainsString('wire:click=', $html);
    }

    public function test_read_only_tables_do_not_gain_sorting_controls(): void
    {
        $html = Blade::render('<x-tables.table :columns="[\'Mitarbeiter\', \'Antwort\']" />');

        $this->assertStringNotContainsString('rt-table-mobile-sort', $html);
        $this->assertStringNotContainsString('data-sort-key=', $html);
    }

    public function test_disposition_lists_share_the_existing_table_and_have_no_local_header_overrides(): void
    {
        foreach (['operations/inquiry-inbox', 'admin/operations/orders', 'admin/operations/shift-management'] as $view) {
            $source = file_get_contents(resource_path('views/livewire/'.$view.'.blade.php'));
            $this->assertStringContainsString('<x-tables.table', $source);
            $this->assertStringContainsString('sort-action="tableSort"', $source);
            $this->assertStringContainsString(':sort-by="$sortBy"', $source);
            $this->assertStringContainsString(':sort-dir="$sortDir"', $source);
        }
        $styles = file_get_contents(resource_path('css/disposition-workspace.css'));
        $this->assertStringNotContainsString('.rt-table-head', $styles);
        $this->assertStringNotContainsString('.rt-table-row-grid', $styles);
    }
}
