<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ServiceOrdersSharedPresentationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    private const CELL_LABELS = ['Leistung', 'Kunde', 'Zeitraum', 'Ort', 'Schichten', 'Besetzung', 'Status'];

    private const CELL_KEYS = ['service', 'customer', 'period', 'location', 'count', 'staffing', 'status'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Gemeinsame Darstellung', 'is_active' => true]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $this->customer->id, 'title' => 'Korridorleistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-10 06:00:00', 'ends_at' => '2027-05-20 14:00:00',
            'required_staff' => 3, 'created_by' => $this->admin->id,
            'location_name' => 'Hamburg',
        ], $attributes));
    }

    private function shift(Order $order, array $attributes = []): Shift
    {
        return Shift::create(array_merge([
            'order_id' => $order->id, 'title' => 'Frühdienst', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'required_staff' => 2,
            'status' => 'open', 'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ], $attributes));
    }

    public function test_orders_and_services_share_the_same_seven_column_arrangement_and_native_visual_content(): void
    {
        $order = $this->order();
        $shift = $this->shift($order);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => 'Anna Testperson']);
        ShiftAssignment::create([
            'shift_id' => $shift->id, 'user_id' => $employee->id,
            'status' => 'confirmed', 'assigned_by' => $this->admin->id,
        ]);
        $orders = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $services = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'orders');

        foreach ([[$orders, 'orders', 'selectOrder', 'openDetails'], [$services, 'service-overview', 'openServiceDetails', 'openServiceDetails']] as [$component, $key, $selection, $details]) {
            $xpath = $this->xpath($component->html());
            $table = $this->table($xpath, $key);
            $this->assertSame(self::CELL_LABELS, $this->texts($xpath->query('.//*[@role="table"]/*[@role="row"]/*[@role="columnheader"]', $table)));
            $row = $xpath->query('.//*[@data-table-row-interactive]', $table)->item(0);
            $this->assertInstanceOf(DOMElement::class, $row);
            $this->assertSame(self::CELL_LABELS, $this->attributes($xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-table-row-grid ")]/*[@data-rt-table-label]', $row), 'data-rt-table-label'));
            $this->assertSame(self::CELL_KEYS, $this->attributes($xpath->query('.//*[@data-service-column]', $row), 'data-service-column'));
            $this->assertStringContainsString("selectionAction: '$selection'", $row->getAttribute('x-data'));
            $this->assertStringContainsString("detailAction: '$details'", $row->getAttribute('x-data'));
            $this->assertSame('details', $row->getAttribute('data-table-row-click'));
            $this->assertStringContainsString('detailOnClick: true', $row->getAttribute('x-data'));
            $this->assertStringContainsString('x-on:keydown.space=', $component->html());
            $this->assertFalse($row->hasAttribute('x-on:dblclick.prevent'));
            $this->assertSame(1, $xpath->query('.//button[@*[name()="wire:click" and .="'.$details.'('.$order->id.')"] and @aria-label="Leistungsdetails: Korridorleistung"]', $row)->length);
            $this->assertSame(1, $xpath->query('.//*[@data-rt-table-label="Kunde"]//*[@data-rt-dropdown-trigger]//*[@data-company-avatar]', $row)->length);
            $this->assertSame(1, $xpath->query('.//*[@data-rt-table-label="Kunde"]//*[@data-service-cell-preview="customer"]', $row)->length);
            $this->assertSame(0, $xpath->query('.//*[@data-rt-table-label="Leistung"]//*[@data-company-avatar]', $row)->length);
            $this->assertSame(1, $xpath->query('.//*[@data-rt-table-label="Besetzung"]//*[@data-service-staffing]', $row)->length);
            $this->assertStringContainsString('Anna Testperson', $row->textContent);
            $this->assertStringContainsString('1 Platz offen', $row->textContent);
            $this->assertStringContainsString('Bestätigt', $xpath->query('.//*[@data-rt-table-label="Status"]', $row)->item(0)->textContent);
            $this->assertSame(['service', 'customer', 'period', 'status'], $this->attributes($xpath->query('.//*[@role="columnheader"]//*[@data-sort-key]', $table), 'data-sort-key'));
        }
    }

    public function test_orders_global_pagination_retains_its_original_named_owner_and_fifty_row_limit(): void
    {
        for ($number = 1; $number <= 51; $number++) {
            $this->order(['title' => sprintf('Leistung %03d', $number)]);
        }
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $table = $this->table($xpath, 'orders');
        $this->assertSame('rtListScrollPagination', $table->getAttribute('x-data'));
        $this->assertSame('ordersPage', $table->getAttribute('data-list-page-name'));
        $this->assertSame('1', $table->getAttribute('data-list-page'));
        $this->assertSame('true', $table->getAttribute('data-list-has-next'));
        $this->assertSame(50, $xpath->query('.//*[@data-list-pagination-content]/*[@data-table-row-interactive]', $table)->length);
        $pager = $xpath->query('//nav[@data-table-pagination-key="orders"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $pager);
        $this->assertSame('ordersPage', $pager->getAttribute('data-table-page-name'));
        $actions = $this->attributes($xpath->query('.//button[@data-table-page-action]', $pager), 'wire:click');
        $this->assertContains('nextPage("ordersPage")', $actions);
        $this->assertContains('gotoPage(2, "ordersPage")', $actions);
        foreach ($actions as $action) {
            $this->assertStringNotContainsString('servicesPage', $action);
        }

        $component->call('nextPage', 'ordersPage');
        $table = $this->table($this->xpath($component->html()), 'orders');
        $this->assertSame('2', $table->getAttribute('data-list-page'));
        $this->assertSame('true', $table->getAttribute('data-list-has-previous'));
        $this->assertSame('false', $table->getAttribute('data-list-has-next'));
        $component->call('previousPage', 'ordersPage')->assertSet('paginators.ordersPage', 1);
    }

    public function test_main_order_table_has_one_scrollable_body_and_stationary_header_and_pager(): void
    {
        $this->order();
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $table = $this->table($xpath, 'orders');
        $this->assertSame('body', $table->getAttribute('data-table-scroll'));
        $this->assertSame(1, $xpath->query('.//*[@data-table-scroll-body and @role="rowgroup" and @tabindex="0"]', $table)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-table-scroll-body]/*[@data-list-pagination-content]', $table)->length);
        $this->assertSame(0, $xpath->query('.//*[@data-table-scroll-body]//*[@role="columnheader"]', $table)->length);
        $this->assertSame(0, $xpath->query('//nav[@data-table-pagination-key="orders"]/ancestor::*[@data-table-scroll-body]')->length);
        $this->assertSame(0, $xpath->query('.//nav[@role="navigation"]', $table)->length);
    }

    public function test_shared_shift_preview_uses_its_host_native_detail_action_instead_of_another_owners_event(): void
    {
        $order = $this->order();
        $this->shift($order);
        $orders = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $services = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'orders');

        foreach ([[$orders, 'orders', true], [$services, 'service-overview', false]] as [$component, $key, $native]) {
            $xpath = $this->xpath($component->html());
            $table = $this->table($xpath, $key);
            $button = $xpath->query('.//*[@data-rt-table-label="Schichten"]//button[contains(@class,"rt-service-preview-details")]', $table)->item(0);
            $this->assertInstanceOf(DOMElement::class, $button);
            if ($native) {
                $this->assertSame('openDetails('.$order->id.')', $button->getAttribute('wire:click'));
                $this->assertStringNotContainsString('operations-service-detail-request', $button->getAttribute('x-on:click'));
                $this->assertStringContainsString('Gesamter Auftrag', $xpath->query('.//*[@data-rt-table-label="Schichten"]', $table)->item(0)->textContent);
            } else {
                $this->assertStringContainsString('operations-service-detail-request', $button->getAttribute('x-on:click'));
                $this->assertStringContainsString('ownerId: $wire.$id', $button->getAttribute('x-on:click'));
                $this->assertStringContainsString('Im Zeitraum', $xpath->query('.//*[@data-rt-table-label="Schichten"]', $table)->item(0)->textContent);
            }
        }
    }

    public function test_orders_and_service_lists_wait_four_hundred_milliseconds_with_cursor_progress_before_all_cell_hover_previews(): void
    {
        $order = $this->order();
        $this->shift($order);
        $orders = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $services = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'orders');

        foreach ([[$orders, 'orders'], [$services, 'service-overview']] as [$component, $key]) {
            $xpath = $this->xpath($component->html());
            $table = $this->table($xpath, $key);
            $previews = $xpath->query('.//*[@data-service-cell-preview or @data-service-staffing]', $table);
            $this->assertSame(5, $previews->length);
            $this->assertSame(['customer', 'period', 'location', 'shifts'], $this->attributes($xpath->query('.//*[@data-service-cell-preview]', $table), 'data-service-cell-preview'));
            foreach ($previews as $preview) {
                $this->assertTrue($preview->hasAttribute('data-rt-dropdown-root'));
                $expression = $preview->getAttribute('x-data');
                $this->assertStringStartsWith('rtAnchorDropdown(', $expression);
                $this->assertSame(1, preg_match("/JSON\\.parse\\('([^']+)'\\)/", $expression, $match));
                $json = json_decode('"'.$match[1].'"', true, 512, JSON_THROW_ON_ERROR);
                $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($config['openOnHover']);
                $this->assertSame(400, $config['hoverOpenDelay']);
                $this->assertTrue($config['hoverCursorProgress']);
                $this->assertSame(180, $config['hoverCloseDelay']);
                $this->assertSame('service-staffing', $config['layerGroup']);
                $this->assertSame('dialog', $config['popupRole']);
                $this->assertFalse($config['overlapAnchor']);
                $this->assertSame(1, $xpath->query('.//*[@data-rt-dropdown-caret]', $preview)->length);
                $progress = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-service-hover-progress ")]', $preview)->item(0);
                $this->assertInstanceOf(DOMElement::class, $progress);
                $this->assertSame('true', $progress->getAttribute('aria-hidden'));
                $this->assertSame('false', $progress->getAttribute('focusable'));
                $this->assertSame('hoverOpenTimer !== null && !open', $progress->getAttribute('x-show'));
                $this->assertSame('body', $progress->parentNode->getAttribute('x-teleport'));
                $this->assertSame(1, $xpath->query('.//*[@data-table-row-ignore and @role="button" and @tabindex="0"]', $preview)->length);
                $trigger = $xpath->query('.//*[@data-table-row-ignore and @role="button"]', $preview)->item(0);
                $this->assertSame("open ? 'open' : (hoverOpenTimer !== null ? 'pending' : 'idle')", $trigger->getAttribute('x-bind:data-service-preview-state'));
                $this->assertSame("{ '--rt-service-hover-wait': hoverOpenDelay + 'ms' }", $trigger->getAttribute('x-bind:style'));
            }
            // libxml drops @-prefixed handlers; retain native immediate keyboard paths in actual rendered HTML.
            $this->assertStringContainsString('@keydown.enter.prevent.stop="toggle()"', $component->html());
            $this->assertStringContainsString('@keydown.space.prevent.stop="toggle()"', $component->html());
            $this->assertStringContainsString('@keydown.arrow-down.prevent.stop="focusHoverPanel()"', $component->html());
        }
    }

    public function test_order_controls_share_the_first_header_row_without_duplicating_the_filter_toolbar(): void
    {
        $this->order();
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $this->assertSame(1, $xpath->query('//template[@*[name()="x-teleport" and .="[data-page-header-search]"]]//*[@data-orders-list-controls]')->length);
        $this->assertSame(1, $xpath->query('//template[@*[name()="x-teleport" and .="[data-page-header-actions]"]]//*[@data-orders-filter-trigger]')->length);
        $bulk = $xpath->query('//*[@data-orders-list-controls]//*[@data-orders-bulk-trigger and @data-tables-bulk-unavailable]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $bulk);
        $this->assertTrue($bulk->hasAttribute('disabled'));
        $this->assertFalse($bulk->hasAttribute('wire:click'));
        $this->assertStringContainsString('keine Sammelaktionen', $bulk->getAttribute('aria-label'));
        $this->assertGreaterThan(0, $xpath->query('//*[@data-autosave-model="perPage"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-tables-toolbar]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-scroll]//*[@data-orders-list-controls]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-scroll]//*[@data-orders-filter-trigger]')->length);
    }

    public function test_unplanned_orders_keep_contractual_dates_and_demand_without_fake_shift_vacancies(): void
    {
        $order = $this->order(['title' => 'Noch nicht geplante Leistung', 'required_staff' => 3]);
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $table = $this->table($xpath, 'orders');
        $row = $xpath->query('.//*[@data-table-row-interactive]', $table)->item(0);
        $period = $xpath->query('.//*[@data-rt-table-label="Zeitraum"]', $row)->item(0);
        $this->assertStringContainsString($order->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'), $period->textContent);
        $this->assertStringContainsString($order->ends_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'), $period->textContent);
        $shifts = $xpath->query('.//*[@data-rt-table-label="Schichten"]', $row)->item(0);
        $this->assertStringContainsString('0 Schichten', $shifts->textContent);
        $this->assertStringContainsString('3', $shifts->textContent);
        $this->assertSame(0, $xpath->query('.//*[@data-service-open-group]/*', $row)->length);
        $this->assertSame(0, Shift::where('order_id', $order->id)->count());
        $this->assertSame(3, $order->fresh()->required_staff);
    }

    public function test_embedded_customer_orders_use_the_same_cells_without_global_header_teleports_or_bounded_body(): void
    {
        $this->order();
        $other = Customer::create(['company_name' => 'Anderer Kunde', 'is_active' => true]);
        $this->order(['customer_id' => $other->id, 'title' => 'Fremde Leistung']);
        $component = Livewire::actingAs($this->admin)->withQueryParams(['search' => 'Fremde', 'status' => 'cancelled'])
            ->test(Orders::class, ['consolidated' => true, 'profileEmbedded' => true, 'customerId' => $this->customer->id])
            ->assertDontSee('Fremde Leistung')->assertNotDispatched('rt-workspace-url');
        $xpath = $this->xpath($component->html());
        $table = $this->table($xpath, 'orders');
        $this->assertFalse($table->hasAttribute('data-table-scroll'));
        $this->assertSame('ordersPage', $table->getAttribute('data-list-page-name'));
        $this->assertSame(self::CELL_LABELS, $this->texts($xpath->query('.//*[@role="table"]/*[@role="row"]/*[@role="columnheader"]', $table)));
        $this->assertSame(0, $xpath->query('//template[starts-with(@*[name()="x-teleport"],"[data-page-header-")]')->length);
        $this->assertSame(1, $xpath->query('//nav[@data-table-pagination-key="orders"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-orders-list-controls]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-orders-filter-trigger]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-scroll]')->length);
    }

    public function test_native_order_selection_and_details_do_not_mutate_workflow_records(): void
    {
        $order = $this->order();
        $this->shift($order);
        $before = $order->fresh()->getAttributes();
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $component->call('selectOrder', $order->id)->assertSet('selectedOrderId', $order->id)
            ->call('openDetails', $order->id)->assertSet('detailOpen', true)->assertSet('formOpen', false)
            ->call('setDetailSection', 'planning')->assertSet('detailSection', 'planning')
            ->call('closeDetails')->assertSet('detailOpen', false)->assertSet('selectedOrderId', null);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_detail_only_embedded_service_owner_does_not_receive_list_controls_or_pagination(): void
    {
        $order = $this->order();
        $this->shift($order);
        $component = Livewire::actingAs($this->admin)->test(Orders::class, [
            'consolidated' => true, 'detailOnly' => true, 'embeddedDetail' => true, 'initialOrderId' => $order->id,
        ]);
        $xpath = $this->xpath($component->html());
        $this->assertSame(0, $xpath->query('//*[@data-rt-premium-table]')->length);
        $this->assertSame(0, $xpath->query('//nav[@data-table-pagination-key="orders"]')->length);
        $this->assertSame(0, $xpath->query('//template[starts-with(@*[name()="x-teleport"],"[data-page-header-")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-service-detail-loaded="'.$order->id.'"]')->length);
    }

    public function test_legacy_standalone_orders_keep_document_flow_and_local_controls(): void
    {
        $this->order();
        $component = Livewire::actingAs($this->admin)->test(Orders::class);
        $xpath = $this->xpath($component->html());
        $table = $this->table($xpath, 'orders');
        $this->assertFalse($table->hasAttribute('data-table-scroll'));
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-scroll]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-orders-list-controls]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-orders-filter-trigger]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-controls]/ancestor::template')->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-filter-trigger]/ancestor::template')->length);
    }

    public function test_shared_layout_responds_to_actual_container_width_without_removing_any_record_fields(): void
    {
        $css = file_get_contents(resource_path('css/service-table.css'));
        $component = file_get_contents(resource_path('views/components/operations/service-table.blade.php'));
        $orders = file_get_contents(resource_path('views/livewire/admin/operations/orders.blade.php'));
        $services = file_get_contents(resource_path('views/livewire/admin/operations/partials/service-overview.blade.php'));
        $this->assertStringContainsString('container: rt-service-list / inline-size;', $css);
        $this->assertStringContainsString('@container rt-service-list (max-width: 1040px)', $css);
        $this->assertStringContainsString('@container rt-service-list (max-width: 600px)', $css);
        $this->assertStringContainsString('.rt-service-table .rt-table-row-grid', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(6, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)', $css);
        foreach (self::CELL_KEYS as $key) {
            $this->assertStringContainsString("[data-service-column='$key']", $css);
            $this->assertDoesNotMatchRegularExpression('/\[data-service-column=[\'"]'.preg_quote($key, '/').'[\'"]\]\s*\{[^}]*display\s*:\s*none/s', $css);
        }
        $this->assertStringContainsString('<x-tables.table', $component);
        $this->assertStringContainsString('row-view="components.tables.rows.operations.service-overview"', $component);
        $this->assertStringContainsString('class="rt-service-table"', $component);
        $this->assertSame(1, substr_count($orders, '<x-operations.service-table'));
        $this->assertSame(1, substr_count($services, '<x-operations.service-table'));
        $this->assertStringNotContainsString('<x-tables.table', $orders);
        $this->assertStringNotContainsString('<x-tables.table', $services);
    }

    private function table(DOMXPath $xpath, string $key): DOMElement
    {
        $tables = $xpath->query('//*[@data-rt-premium-table and @data-table-pagination-key="'.$key.'"]');
        $this->assertSame(1, $tables->length);
        $table = $tables->item(0);
        $this->assertInstanceOf(DOMElement::class, $table);

        return $table;
    }

    private function texts(\DOMNodeList $nodes): array
    {
        return array_map(fn ($node): string => trim($node->textContent), iterator_to_array($nodes));
    }

    private function attributes(\DOMNodeList $nodes, string $name): array
    {
        return array_map(fn ($node): string => $node->getAttribute($name), iterator_to_array($nodes));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
