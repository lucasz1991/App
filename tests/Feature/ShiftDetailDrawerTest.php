<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ShiftDetailDrawerTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Testkorridor', 'is_active' => true]);
        $order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Korridorleistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-10 00:00:00', 'ends_at' => '2027-05-20 23:00:00',
            'required_staff' => 3, 'created_by' => $this->admin->id,
        ]);
        $this->shift = Shift::create([
            'order_id' => $order->id, 'title' => 'Detaildienst', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'required_staff' => 1, 'status' => 'open',
            'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_opening_details_keeps_the_current_planning_context_without_redirect_or_data_changes(): void
    {
        $original = $this->shift->fresh()->getRawOriginal();

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('setView', 'table')
            ->set('rangeFrom', '2027-05-17')->set('rangeTo', '2027-05-18')
            ->set('orderFilter', (string) $this->shift->order_id)
            ->set('statusFilter', 'draft')->set('attentionFilter', 'unpublished')
            ->set('search', 'Nicht sichtbarer Dienst')
            ->call('openDetails', $this->shift->id)
            ->assertNoRedirect()
            ->assertSet('detailOpen', true)
            ->assertSet('selectedShiftId', $this->shift->id)
            ->assertSet('viewMode', 'table')
            ->assertSet('rangeFrom', '2027-05-17')->assertSet('rangeTo', '2027-05-18')
            ->assertSet('orderFilter', (string) $this->shift->order_id)
            ->assertSet('statusFilter', 'draft')->assertSet('attentionFilter', 'unpublished')
            ->assertSet('search', 'Nicht sichtbarer Dienst')
            ->assertViewHas('selectedShift', fn ($selected) => $selected->is($this->shift));

        $this->assertSame($original, $this->shift->fresh()->getRawOriginal());
    }

    public function test_detail_requests_reauthorize_after_the_component_was_mounted(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($employee);

        $component->call('openDetails', $this->shift->id)->assertForbidden();
    }

    public function test_disabled_administrators_cannot_request_shift_details(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $this->admin->update(['status' => false]);
        $this->actingAs($this->admin->fresh());

        $component->call('openDetails', $this->shift->id)->assertForbidden();
    }

    public function test_missing_shift_is_not_treated_as_successfully_loaded_details(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $this->shift->id + 999);
    }

    public function test_deleted_shift_is_not_treated_as_successfully_loaded_details(): void
    {
        $id = $this->shift->id;
        $this->shift->delete();
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $id);
    }

    public function test_existing_shift_deep_link_still_opens_the_detail_panel(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['shift' => $this->shift->id])
            ->test(ShiftManagement::class)
            ->assertNoRedirect()
            ->assertSet('detailOpen', true)
            ->assertSet('selectedShiftId', $this->shift->id)
            ->assertSet('viewMode', 'timeline');
    }

    public function test_table_and_card_details_use_the_locally_controlled_drawer_and_its_own_loader(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);

        foreach (['table', 'day'] as $view) {
            $component->call('setView', $view);
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML($component->html());
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($document);
            $root = $xpath->query('//*[@data-operations-shift-management]')->item(0);
            $this->assertSame('rtShiftDetailDrawer', $root->getAttribute('x-data'));
            $this->assertStringContainsString('openShiftDetail(', $root->getAttribute('x-on:operations-shift-detail-request.window'));

            $drawer = $xpath->query("//*[starts-with(@id, 'shift-plan-detail-') and @data-rt-modal-shell]")->item(0);
            $this->assertNotNull($drawer);
            $wrapper = $xpath->query('ancestor::*[@x-init][1]', $drawer)->item(0);
            $this->assertNotNull($wrapper, substr($document->saveHTML($drawer->parentNode->parentNode), 0, 900));
            $this->assertStringContainsString("\$watch('detailVisible'", $wrapper->getAttribute('x-init'));
            $this->assertStringContainsString("\$watch('show'", $wrapper->getAttribute('x-init'));
            $content = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' rt-modal-content ')]", $drawer)->item(0);
            $this->assertNotNull($content);
            $this->assertFalse($content->hasAttribute('data-rt-modal-body'));
            $this->assertSame(1, $xpath->query('.//*[@data-shift-detail-loading]', $content)->length);
            $this->assertSame(1, $xpath->query('.//*[@data-shift-detail-error]', $content)->length);

            $triggerClass = $view === 'table' ? 'rt-table-record__title' : 'rt-disposition-shift';
            $trigger = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' {$triggerClass} ')]")->item(0);
            $this->assertNotNull($trigger);
            $this->assertStringContainsString('operations-shift-detail-request', $trigger->getAttribute('x-on:click'));
            $this->assertFalse($trigger->hasAttribute('wire:click'));
        }
    }
}
