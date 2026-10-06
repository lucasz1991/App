<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Operations\InquiryInbox;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerProfileCommerceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_07_18_000001_create_activity_log_table.php', '2026_07_22_000002_create_employee_document_requirements_table.php', '2026_10_04_121000_create_customer_workflow_extensions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->withoutVite();
        Mail::fake();
        Bus::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Profile Commerce Customer', 'is_active' => true]);
        $this->other = Customer::create(['company_name' => 'Other Commerce Customer', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    private function parameters(): array
    {
        return ['customerId' => $this->customer->id, 'consolidated' => true, 'profileEmbedded' => true];
    }

    private function order(string $title, ?Customer $customer = null): Order
    {
        return Order::create([
            'customer_id' => ($customer ?? $this->customer)->id, 'title' => $title, 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-06-10T08:00', 'ends_at' => '2027-06-10T16:00',
            'required_staff' => 2, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }

    private function inquiry(string $title, ?Customer $customer = null): OperationInquiry
    {
        return OperationInquiry::create([
            'customer_id' => ($customer ?? $this->customer)->id, 'title' => $title, 'channel' => 'manual',
            'original' => 'Synthetic profile inquiry', 'status' => 'new', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-06-10T08:00', 'ends_at' => '2027-06-10T16:00',
            'role_name' => 'Tf', 'required_staff' => 2, 'revision' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }

    private function missing(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a missing customer-scoped record.');
        } catch (ModelNotFoundException $exception) {
            $this->assertNotEmpty($exception->getIds());
        }
    }

    public function test_profile_orders_ignore_customer_list_query_and_do_not_rewrite_profile_url(): void
    {
        $order = $this->order('Scoped Profile Order');
        $other = $this->order('Foreign Profile Order', $this->other);
        $before = $order->fresh()->getAttributes();
        $component = Livewire::withQueryParams([
            'view' => 'orders', 'search' => 'Foreign customer search', 'status' => 'inactive',
            'filter' => 'active', 'detail' => 'contacts', 'order' => $other->id,
        ])->test(Orders::class, $this->parameters())
            ->assertSet('profileEmbedded', true)->assertSet('selectedOrderId', null)
            ->assertSet('search', '')->assertSet('statusFilter', 'all')->assertSet('detailSection', 'overview')
            ->assertSee('Scoped Profile Order')->assertDontSee('Foreign Profile Order')->assertDontSee('Other Commerce Customer')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1 && $orders->first()->id === $order->id)
            ->assertViewHas('customers', fn ($customers) => $customers->pluck('id')->all() === [$this->customer->id])
            ->assertNotDispatched('rt-workspace-url');
        $component->call('openDetails', $order->id)->assertSet('selectedOrderId', $order->id)->assertSet('detailOpen', true)
            ->assertNotDispatched('rt-workspace-url')->call('setDetailSection', 'history')
            ->assertSet('detailSection', 'history')->assertNotDispatched('rt-workspace-url')
            ->set('search', 'Scoped')->set('statusFilter', 'confirmed')->assertNotDispatched('rt-workspace-url')
            ->call('closeDetails')->assertSet('selectedOrderId', null)->assertNotDispatched('rt-workspace-url');
        $this->assertSame($before, $order->fresh()->getAttributes());
        Mail::assertNothingSent();
    }

    public function test_profile_inquiries_ignore_customer_list_query_and_do_not_rewrite_profile_url(): void
    {
        $inquiry = $this->inquiry('Scoped Profile Inquiry');
        $this->inquiry('Foreign Profile Inquiry', $this->other);
        $before = $inquiry->fresh()->getAttributes();
        $component = Livewire::withQueryParams([
            'view' => 'inquiries', 'search' => 'Foreign customer search', 'status' => 'inactive',
            'filter' => 'not-an-inquiry-channel', 'detail' => 'contacts',
        ])->test(InquiryInbox::class, $this->parameters())
            ->assertSet('profileEmbedded', true)->assertSet('search', '')->assertSet('statusFilter', 'all')
            ->assertSet('filter', 'active')->assertSet('detailSection', 'overview')
            ->assertSee('Scoped Profile Inquiry')->assertDontSee('Foreign Profile Inquiry')->assertDontSee('Other Commerce Customer')
            ->assertViewHas('inquiries', fn ($inquiries) => $inquiries->total() === 1 && $inquiries->first()->id === $inquiry->id)
            ->assertViewHas('customers', fn ($customers) => $customers->pluck('id')->all() === [$this->customer->id])
            ->assertNotDispatched('rt-workspace-url');
        $component->call('select', $inquiry->id)->assertSet('selectedId', $inquiry->id)->assertSet('detailOpen', true)
            ->assertNotDispatched('rt-workspace-url')->call('setDetailSection', 'history')
            ->assertSet('detailSection', 'history')->assertNotDispatched('rt-workspace-url')
            ->set('search', 'Scoped')->set('filter', 'all')->set('statusFilter', 'new')
            ->assertNotDispatched('rt-workspace-url')->call('close')->assertSet('selectedId', null)
            ->assertNotDispatched('rt-workspace-url');
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        Mail::assertNothingSent();
    }

    public function test_profile_commerce_requires_consolidated_fixed_customer_context(): void
    {
        foreach ([Orders::class, InquiryInbox::class] as $component) {
            Livewire::test($component, ['consolidated' => true, 'profileEmbedded' => true])->assertNotFound();
            Livewire::test($component, ['customerId' => $this->customer->id, 'profileEmbedded' => true])->assertNotFound();
            Livewire::test($component, ['customerId' => 0, 'consolidated' => true, 'profileEmbedded' => true])->assertNotFound();
        }
    }

    public function test_profile_order_creation_preselects_customer_and_rejects_another_customer(): void
    {
        $component = Livewire::test(Orders::class, $this->parameters())->call('createOrder')
            ->assertSet('formOpen', true)->assertSet('editingOrderId', null)
            ->assertSet('customerId', $this->customer->id)->assertNotDispatched('rt-workspace-url')
            ->assertDontSee('Other Commerce Customer');
        $component->set('customerId', $this->other->id)->call('saveOrder')->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('operation_inquiries', 0);
        Mail::assertNothingSent();
    }

    public function test_profile_inquiry_creation_preselects_customer_and_rejects_another_customer(): void
    {
        $component = Livewire::test(InquiryInbox::class, $this->parameters())->call('create')
            ->assertSet('editing', true)->assertSet('selectedId', null)->assertSet('detailOpen', true)
            ->assertSet('form.customer_id', $this->customer->id)->assertNotDispatched('rt-workspace-url')
            ->assertDontSee('Other Commerce Customer');
        $component->set('form.customer_id', $this->other->id)->call('save')->assertStatus(422);
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        Mail::assertNothingSent();
    }

    public function test_profile_order_records_remain_customer_scoped(): void
    {
        $order = $this->order('Scoped order');
        $other = $this->order('Foreign order', $this->other);
        $this->missing(fn () => Livewire::test(Orders::class, $this->parameters() + ['initialOrderId' => $other->id]));
        $this->missing(fn () => Livewire::test(Orders::class, $this->parameters())->call('openDetails', $other->id));
        $this->missing(fn () => Livewire::test(Orders::class, $this->parameters())->call('editOrder', $other->id));
        Livewire::test(Orders::class, $this->parameters() + ['initialOrderId' => $order->id])
            ->assertSet('selectedOrderId', $order->id)->assertSet('detailOpen', true)
            ->assertSee('Scoped order')->assertDontSee('Foreign order');
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_profile_inquiry_records_remain_customer_scoped(): void
    {
        $inquiry = $this->inquiry('Scoped inquiry');
        $other = $this->inquiry('Foreign inquiry', $this->other);
        $this->missing(fn () => Livewire::test(InquiryInbox::class, $this->parameters() + ['initialInquiryId' => $other->id]));
        $this->missing(fn () => Livewire::test(InquiryInbox::class, $this->parameters())->call('select', $other->id));
        Livewire::test(InquiryInbox::class, $this->parameters() + ['initialInquiryId' => $inquiry->id])
            ->assertSet('selectedId', $inquiry->id)->assertSet('detailOpen', true)
            ->assertSee('Scoped inquiry')->assertDontSee('Foreign inquiry');
        $this->assertDatabaseCount('operation_inquiries', 2);
    }

    public function test_embedded_profile_adapters_do_not_grant_commerce_permissions(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($employee)->test(Orders::class, $this->parameters())->assertForbidden();
        Livewire::actingAs($employee)->test(InquiryInbox::class, $this->parameters())->assertForbidden();
    }

    public function test_profile_order_embedding_flag_cannot_be_changed_by_client(): void
    {
        $component = Livewire::test(Orders::class, $this->parameters());
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('profileEmbedded', false);
    }

    public function test_profile_inquiry_customer_scope_cannot_be_changed_by_client(): void
    {
        $component = Livewire::test(InquiryInbox::class, $this->parameters());
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('customerFilterId', $this->other->id);
    }
}
