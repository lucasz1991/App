<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
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

class OrdersPeriodControlsUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Zeitraum Testfirma', 'is_active' => true]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $this->customer->id, 'title' => 'Lokale Zeitraumleistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-10 06:00:00', 'ends_at' => '2027-05-20 14:00:00',
            'required_staff' => 3, 'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function shift(Order $order, array $attributes = []): Shift
    {
        return Shift::create(array_merge([
            'order_id' => $order->id, 'title' => 'Frühdienst', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'required_staff' => 3,
            'status' => 'open', 'created_by' => $this->admin->id,
        ], $attributes));
    }

    public function test_native_order_period_control_is_directly_before_filter_in_the_same_first_header_host(): void
    {
        $this->order();
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $controls = $xpath->query('//template[@*[name()="x-teleport" and .="[data-page-header-actions]"]]//*[@data-orders-table-filters]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $controls);
        $this->assertSame(1, $xpath->query('.//*[@data-orders-period-control]', $controls)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-orders-filter-trigger]', $controls)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-orders-period-control]/following-sibling::*[1][.//*[@data-orders-filter-trigger]]', $controls)->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-scroll]//*[@data-orders-period-trigger]')->length);
        $trigger = $xpath->query('.//button[@data-orders-period-trigger]', $controls)->item(0);
        $this->assertInstanceOf(DOMElement::class, $trigger);
        $this->assertSame('button', $trigger->getAttribute('type'));
        $this->assertStringStartsWith('Auftragszeitraum auswählen: ', $trigger->getAttribute('aria-label'));
        $this->assertSame('12.04.2027 – 11.06.2027', $trigger->getAttribute('title'));
        $this->assertStringContainsString('rt-shift-plan-period-trigger', $trigger->getAttribute('class'));
        $this->assertSame(1, $xpath->query('.//i[contains(@class,"fa-calendar-alt") and @aria-hidden="true"]', $trigger)->length);
    }

    public function test_period_uses_existing_anchor_and_date_picker_with_the_same_native_livewire_owner(): void
    {
        $this->order();
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $anchor = $xpath->query('//*[@data-orders-period-control]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $anchor);
        $this->assertTrue($anchor->hasAttribute('data-rt-dropdown-root'));
        $this->assertStringContainsString('rtAnchorDropdown', $anchor->getAttribute('x-data'));
        $picker = $xpath->query('.//*[@data-rt-date-range-picker]', $anchor)->item(0);
        $this->assertInstanceOf(DOMElement::class, $picker);
        $this->assertSame('range', $picker->getAttribute('data-selection-mode'));
        $this->assertTrue($picker->hasAttribute('data-rt-dropdown-keep-open'));
        $expression = $picker->getAttribute('x-data');
        $this->assertStringContainsString('rtDateRangePicker', $expression);
        $this->assertStringContainsString("\$wire.entangle('rangeFrom')", $expression);
        $this->assertStringContainsString("\$wire.entangle('rangeTo')", $expression);
        $this->assertStringContainsString("\$wire.call('applyPeriod', range.from, range.until)", $expression);
        $this->assertStringNotContainsString('operations-shift-period', $expression);
        $config = $this->jsonConfig($expression);
        $this->assertSame(94, $config['maxDays']);
        $this->assertTrue($config['resetOnOpen']);
        $this->assertSame('range', $config['selectionMode']);
        $this->assertSame('close(true)', $picker->getAttribute('x-on:date-range-applied'));
        $this->assertSame('close(true)', $picker->getAttribute('x-on:date-range-cancel'));
        $this->assertSame(1, $xpath->query('.//*[@role="dialog" and @aria-label="Auftragszeitraum anpassen"]', $anchor)->length);
    }

    public function test_applied_period_updates_the_native_header_and_preserves_detail_selection_and_business_records(): void
    {
        $order = $this->order();
        $before = $order->fresh()->getAttributes();
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true])
            ->call('selectOrder', $order->id)->call('applyPeriod', '2027-05-10', '2027-05-12')
            ->assertSet('selectedOrderId', $order->id)->assertSet('formOpen', false)->assertSet('detailOpen', false);
        $xpath = $this->xpath($component->html());
        $this->assertSame('10.05.2027 – 12.05.2027', $xpath->query('//button[@data-orders-period-trigger]')->item(0)->getAttribute('title'));
        $this->assertSame(1, $xpath->query('//*[@data-orders-pagination]//nav[@data-table-page-name="ordersPage"]')->length);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_profile_embedded_lists_ignore_period_url_and_do_not_get_a_global_period_control(): void
    {
        $order = $this->order();
        $component = Livewire::actingAs($this->admin)->withQueryParams(['from' => 'not-a-date', 'until' => 'foreign-profile-field'])
            ->test(Orders::class, ['consolidated' => true, 'profileEmbedded' => true, 'customerId' => $this->customer->id])
            ->assertViewHas('orders', fn ($orders): bool => $orders->total() === 1 && $orders->first()->id === $order->id)
            ->assertNotDispatched('rt-workspace-url');
        $xpath = $this->xpath($component->html());
        $this->assertSame(0, $xpath->query('//*[@data-orders-period-control or @data-orders-period-trigger]')->length);
        $this->assertSame(0, $xpath->query('//template[starts-with(@*[name()="x-teleport"],"[data-page-header-")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-orders-list-controls]//*[@data-orders-filter-trigger]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-orders-list-scroll]')->length);
    }

    public function test_standalone_and_detail_only_owners_remain_outside_consolidated_period_ui(): void
    {
        $order = $this->order();
        foreach ([[], ['consolidated' => true, 'detailOnly' => true, 'embeddedDetail' => true, 'initialOrderId' => $order->id]] as $parameters) {
            $component = Livewire::actingAs($this->admin)->withQueryParams(['from' => 'not-a-date', 'until' => 'ignored'])
                ->test(Orders::class, $parameters)->assertNotDispatched('rt-workspace-url');
            $xpath = $this->xpath($component->html());
            $this->assertSame(0, $xpath->query('//*[@data-orders-period-control or @data-orders-period-trigger]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-orders-table-filters]')->length);
        }
    }

    public function test_real_active_staffing_reuses_the_same_overlapping_people_and_open_seat_components(): void
    {
        $order = $this->order();
        $shift = $this->shift($order);
        foreach (['confirmed', 'requested'] as $status) {
            $user = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => ucfirst($status).' Testperson']);
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => $status, 'assigned_by' => $this->admin->id]);
        }
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $cell = $this->staffingCell($xpath);
        $this->assertSame(1, $xpath->query('.//*[@data-service-staffing]', $cell)->length);
        $this->assertSame(2, $xpath->query('.//*[@data-service-assigned-group]/*[@data-service-seat]', $cell)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-service-open-group]/*[@data-service-open-slot]', $cell)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-service-open-slot]//*[contains(@class,"rt-service-avatar__dot")]', $cell)->length);
        $this->assertStringContainsString('2/3', $cell->textContent);
        $this->assertStringContainsString('1 Platz offen', $cell->textContent);
        $this->assertSame(0, $xpath->query('.//*[@data-order-staffing-history or @data-order-staffing-unplanned]', $cell)->length);
    }

    public function test_closed_history_has_a_readable_neutral_state_without_invented_active_avatars_or_vacancies(): void
    {
        $order = $this->order(['status' => 'cancelled']);
        foreach (['completed', 'cancelled'] as $status) {
            $shift = $this->shift($order, ['status' => $status]);
            $user = User::factory()->create(['role' => 'staff', 'status' => true]);
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);
        }
        $component = Livewire::actingAs($this->admin)->test(Orders::class, ['consolidated' => true]);
        $xpath = $this->xpath($component->html());
        $cell = $this->staffingCell($xpath);
        $this->assertSame(1, $xpath->query('.//*[@data-order-staffing-history]', $cell)->length);
        $this->assertStringContainsString('Keine aktiven Schichten', $cell->textContent);
        $this->assertStringNotContainsString('0/0', $cell->textContent);
        $this->assertSame(0, $xpath->query('.//*[@data-service-staffing or @data-service-person or @data-service-open-slot]', $cell)->length);
        $this->assertSame(2, ShiftAssignment::count());
        $this->assertSame(2, $component->viewData('orderTableItems')->first()->shiftDetails->count());
    }

    private function staffingCell(DOMXPath $xpath): DOMElement
    {
        $cell = $xpath->query('//*[@data-table-pagination-key="orders"]//*[@data-table-row-interactive]//*[@data-service-column="staffing"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $cell);

        return $cell;
    }

    private function jsonConfig(string $expression): array
    {
        $this->assertSame(1, preg_match("/JSON\\.parse\\('([^']+)'\\)/", $expression, $match));
        $json = json_decode('"'.$match[1].'"', true, 512, JSON_THROW_ON_ERROR);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
