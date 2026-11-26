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
        foreach (['operations/inquiry-inbox', 'operations/orders', 'operations/shift-management'] as $view) {
            $source = file_get_contents(resource_path('views/livewire/'.$view.'.blade.php'));
            // Die Leistungsliste der Auftraege nutzt die gemeinsame Tabelle ueber
            // die Huelle x-operations.service-table (dieselbe Darstellung wie im
            // Schichtplan); Sortierung reicht sie unveraendert durch (siehe unten).
            $this->assertMatchesRegularExpression('/<x-(?:tables\.table|operations\.service-table)\b/', $source, $view);
            $this->assertStringNotContainsString('<table', $source, $view);
            $this->assertStringContainsString('sort-action="tableSort"', $source);
            $this->assertStringContainsString(':sort-by="$sortBy"', $source);
            $this->assertStringContainsString(':sort-dir="$sortDir"', $source);
        }
        $serviceTable = file_get_contents(resource_path('views/components/operations/service-table.blade.php'));
        $this->assertStringContainsString(
            '<x-tables.table :columns="$columns" :items="$items" :sort-action="$sortAction" :sort-by="$sortBy" :sort-dir="$sortDir"',
            $serviceTable,
        );
        $this->assertStringContainsString(
            '<x-operations.service-table :items="$orderTableItems" sort-action="tableSort"',
            file_get_contents(resource_path('views/livewire/operations/orders.blade.php')),
        );
        $styles = file_get_contents(resource_path('css/disposition-workspace.css'));
        // Das Aussehen von Kopf und Zeilenraster bleibt Sache der gemeinsamen
        // Tabelle. Erlaubt sind im Dispositions-Stylesheet nur die reinen
        // Lage-Angaben, die der eigene Scrollbereich der Listen braucht
        // (Kopf nicht kleben lassen bzw. ueber dem scrollenden Rumpf halten).
        $this->assertStringNotContainsString('.rt-table-row-grid', $styles);
        $this->assertStringNotContainsString('.rt-table-head-cell', $styles);
        $this->assertStringNotContainsString('.rt-table-sort', $styles);
        preg_match_all('/([^{}]*\.rt-table-head\b[^{}]*)\{([^{}]*)\}/', $styles, $headerRules, PREG_SET_ORDER);
        $this->assertLessThanOrEqual(2, count($headerRules));
        foreach ($headerRules as [, $selector, $body]) {
            preg_match_all('/([a-z-]+)\s*:/', $body, $properties);
            $this->assertSame(
                [],
                array_values(array_diff($properties[1], ['position', 'z-index', 'flex'])),
                'Lokale Kopf-Regel mit Gestaltung: '.trim($selector),
            );
        }
    }
}
