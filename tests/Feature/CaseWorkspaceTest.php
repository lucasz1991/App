<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\CommercialOfferIndex;
use App\Livewire\Operations\CommercialOffers;
use App\Livewire\Operations\InquiryInbox;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\Operations\OperationsPages;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CaseWorkspaceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_07_18_000001_create_activity_log_table.php', '2026_07_22_000002_create_employee_document_requirements_table.php', '2026_10_04_121000_create_customer_workflow_extensions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic Case Customer', 'is_active' => true]);
        if (! Route::has('operations.page')) {
            Route::get('/test-case-page/{page}', fn () => 'fixture')->name('operations.page');
        }
    }

    private function inquiry(string $title = 'Synthetic Inquiry', ?Customer $customer = null): OperationInquiry
    {
        return app(InquiryWorkflowService::class)->save(null, ['channel' => 'manual', 'title' => $title, 'original' => 'Synthetic original', 'customer_id' => ($customer ?? $this->customer)->id,
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T06:00', 'role_name' => 'Tf', 'location_name' => 'Hamburg', 'required_staff' => 2], $this->admin);
    }

    private function order(string $title = 'Synthetic Order', ?Customer $customer = null): Order
    {
        return Order::create(['title' => $title, 'customer_id' => ($customer ?? $this->customer)->id, 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal',
            'starts_at' => '2027-05-14 20:00:00', 'ends_at' => '2027-05-15 04:00:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'created_by' => $this->admin->id]);
    }

    private function offer(OperationInquiry|Order $subject, int $revision, string $status = 'draft', array $snapshot = []): CommercialOfferRevision
    {
        return CommercialOfferRevision::create(['subject_type' => $subject instanceof Order ? 'Order' : 'OperationInquiry', 'subject_id' => $subject->id, 'revision' => $revision, 'state_version' => 1,
            'status' => $status, 'kind' => 'offer', 'snapshot' => $snapshot + ['terms' => 'Synthetic terms', 'positions' => [], 'source_revision' => $subject->revision ?? 1], 'total_cents' => 10000, 'created_by' => $this->admin->id]);
    }

    private function missing(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a missing scoped record.');
        } catch (ModelNotFoundException $exception) {
            $this->assertNotEmpty($exception->getIds());
        }
    }

    private function withPostReferer(Testable $component, string $referer): Testable
    {
        // Livewire's initial headers are not retained by SubsequentRender.
        $broker = (new \ReflectionProperty(Testable::class, 'requestBroker'))->getValue($component);
        $broker->withHeader('Referer', $referer);

        return $component;
    }

    public function test_case_views_are_filtered_by_existing_separate_rights(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn ($user) => $user->id === $employee->id);
        Gate::define('operations.manage', fn () => false);
        $this->assertSame(['inbox', 'offers'], array_keys(CaseWorkspace::availableViews($employee)));
        $this->actingAs($employee);
        Livewire::test(CaseWorkspace::class)->assertSet('view', 'inbox')->assertSee('Eingang');
        Livewire::test(CaseWorkspace::class, ['initialView' => 'orders'])->assertForbidden();
    }

    public function test_empty_wrapper_view_uses_an_authorized_default_and_invalid_explicit_view_fails(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CaseWorkspace::class, ['initialView' => ''])->assertSet('view', 'inbox')->assertSet('section', 'overview');
        Livewire::test(CaseWorkspace::class, ['initialView' => 'unsupported'])->assertNotFound();
    }

    public function test_parent_workspace_mounts_actual_inquiry_offer_and_order_lists_not_literal_directives(): void
    {
        $inquiry = $this->inquiry('Visible nested inquiry fixture');
        $order = $this->order('Visible nested order fixture');
        $this->offer($inquiry, 1);
        $this->actingAs($this->admin);
        Livewire::test(CaseWorkspace::class, ['initialView' => ''])->assertSee('Visible nested inquiry fixture')->assertDontSee('@livewire(', false);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'offers'])->assertSee('Visible nested inquiry fixture')->assertDontSee('@livewire(', false);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'orders'])->assertSee('Visible nested order fixture')->assertDontSee('@livewire(', false);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('new', $inquiry->fresh()->status);
    }

    public function test_costs_only_role_keeps_its_cost_workspace_without_order_crm_or_create_access(): void
    {
        (require database_path('migrations/2026_10_06_102000_create_operations_enhancements.php'))->up();
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        foreach (['operations.manage', 'operations.inquiries.manage', 'employees.master-data.view', 'customers.portal.manage'] as $ability) {
            Gate::define($ability, fn () => false);
        }
        Gate::define('operations.costs.manage', fn ($user) => $user->id === $employee->id);
        $this->actingAs($employee);
        $this->assertSame(['orders'], array_keys(CaseWorkspace::availableViews($employee)));
        Livewire::test(CaseWorkspace::class, ['initialView' => ''])
            ->assertSet('view', 'orders')->assertSet('section', 'costs')->assertViewHas('costsOnly', true)
            ->assertDontSee('data-operations-orders', false)->assertDontSee('Kundenakte')->assertDontSee('operations-create', false);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'orders', 'initialSection' => 'overview'])->assertForbidden();
        $order = $this->order();
        Livewire::test(CaseWorkspace::class, ['initialView' => 'orders', 'initialSection' => 'costs', 'context' => ['order' => $order->id]])
            ->assertSet('section', 'costs')->assertViewHas('costsOnly', true)->assertDontSee('data-operations-orders', false);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('operation_workflows', 0);
    }

    public function test_disabled_actor_has_no_case_views_or_sections(): void
    {
        $this->admin->forceFill(['status' => false])->save();
        $this->assertSame([], CaseWorkspace::availableViews($this->admin));
        $this->assertSame([], CaseWorkspace::availableSections($this->admin));
    }

    public function test_missing_optional_fulfilment_schema_does_not_break_basic_case_view(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'orders'])->assertSee($order->title);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'orders', 'initialSection' => 'proofs', 'context' => ['order' => $order->id, 'record_type' => 'proof', 'record' => 1]])->assertStatus(503);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'portal', 'context' => ['customer' => $this->customer->id, 'record' => 1, 'source' => 'submission']])->assertForbidden();
    }

    public function test_specific_inquiry_and_customer_context_open_the_correct_record(): void
    {
        $first = $this->inquiry('First inquiry');
        $this->inquiry('Newer inquiry');
        $this->actingAs($this->admin);
        Livewire::test(InquiryInbox::class, ['initialInquiryId' => $first->id, 'customerId' => $this->customer->id, 'consolidated' => true])
            ->assertSet('selectedId', $first->id)->assertSet('detailOpen', true)->assertSee('First inquiry');
        $foreign = $this->inquiry('Other customer', Customer::create(['company_name' => 'Other Synthetic', 'is_active' => true]));
        $this->missing(fn () => Livewire::test(InquiryInbox::class, ['initialInquiryId' => $foreign->id, 'customerId' => $this->customer->id]));
    }

    public function test_specific_order_opens_instead_of_the_latest_order_and_filters_customer(): void
    {
        $first = $this->order('First order');
        $this->order('Newer order');
        $this->actingAs($this->admin);
        Livewire::test(Orders::class, ['initialOrderId' => $first->id, 'customerId' => $this->customer->id, 'consolidated' => true])
            ->assertSet('selectedOrderId', $first->id)->assertSet('detailOpen', true)->assertSee('First order');
        $foreign = $this->order('Foreign order', Customer::create(['company_name' => 'Foreign Synthetic', 'is_active' => true]));
        $this->missing(fn () => Livewire::test(Orders::class, ['initialOrderId' => $foreign->id, 'customerId' => $this->customer->id]));
    }

    public function test_case_rejects_wrong_customer_origin_and_stale_inquiry_revision(): void
    {
        $inquiry = $this->inquiry();
        $order = $this->order();
        $this->actingAs($this->admin);
        Livewire::test(CaseWorkspace::class, ['context' => ['inquiry' => $inquiry->id, 'order' => $order->id]])->assertNotFound();
        Livewire::test(CaseWorkspace::class, ['context' => ['inquiry' => $inquiry->id, 'revision' => 99]])->assertStatus(409);
        $this->missing(fn () => Livewire::test(CaseWorkspace::class, ['context' => ['inquiry' => 999999]]));
    }

    public function test_offer_index_uses_latest_subject_revision_and_hides_copied_origin(): void
    {
        $inquiry = $this->inquiry();
        $old = $this->offer($inquiry, 1);
        $latest = $this->offer($inquiry, 2, 'accepted');
        $order = $this->order();
        $copy = $this->offer($order, 1, 'accepted', ['origin_inquiry_id' => $inquiry->id]);
        $this->actingAs($this->admin);
        Livewire::test(CommercialOfferIndex::class)->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->all() === [$latest->id]);
        Livewire::test(CommercialOfferIndex::class, ['orderId' => $order->id])->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->all() === [$copy->id]);
        Livewire::test(CommercialOfferIndex::class, ['inquiryId' => $inquiry->id, 'initialRevision' => 1])->assertSet('selectedId', $old->id);
    }

    public function test_offer_index_never_discloses_order_offers_to_inquiry_only_role(): void
    {
        $inquiry = $this->inquiry();
        $own = $this->offer($inquiry, 1);
        $hidden = $this->offer($this->order('Hidden order'), 1);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn ($user) => $user->id === $employee->id);
        Gate::define('operations.manage', fn () => false);
        $this->actingAs($employee);
        $component = Livewire::test(CommercialOfferIndex::class)->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->all() === [$own->id]);
        $this->missing(fn () => $component->call('select', $hidden->id));
        Livewire::test(CommercialOffers::class, ['subjectType' => 'Order', 'subjectId' => $hidden->subject_id])->assertForbidden();
    }

    public function test_offer_modal_opens_exact_revision_and_reopens_without_data_mutation(): void
    {
        $inquiry = $this->inquiry();
        $offer = $this->offer($inquiry, 1);
        $this->actingAs($this->admin);
        Livewire::test(CommercialOffers::class, ['subjectType' => 'OperationInquiry', 'subjectId' => $inquiry->id, 'initialOfferId' => $offer->id, 'showList' => false])
            ->assertSet('selectedId', $offer->id)->assertSet('detailOpen', true)->assertSet('showList', false);
        Livewire::test(CommercialOfferIndex::class)->call('select', $offer->id)->assertSet('detailGeneration', 1)->call('select', $offer->id)->assertSet('detailGeneration', 2);
        $this->assertSame('draft', $offer->fresh()->status);
        $this->assertSame('new', $inquiry->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_portal_inquiry_never_uses_generic_conversion_or_save(): void
    {
        $inquiry = $this->inquiry();
        Schema::create('customer_portal_submission_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inquiry_id');
            $table->unsignedBigInteger('submission_id');
        });
        DB::table('customer_portal_submission_items')->insert(['inquiry_id' => $inquiry->id, 'submission_id' => 7]);
        $this->actingAs($this->admin);
        Livewire::test(InquiryInbox::class, ['initialInquiryId' => $inquiry->id])->call('transition', 'convert')->assertStatus(409);
        Livewire::test(InquiryInbox::class, ['initialInquiryId' => $inquiry->id])->call('save')->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('new', $inquiry->fresh()->status);
    }

    public function test_conversion_opens_the_exact_created_order_without_creating_shifts(): void
    {
        $inquiry = $this->inquiry();
        $service = app(InquiryWorkflowService::class);
        $service->transition($inquiry, 1, 'verify', [], $this->admin);
        $service->transition($inquiry, 1, 'offer', ['amount' => '100', 'terms' => 'Synthetic accepted terms'], $this->admin);
        $service->transition($inquiry, 1, 'accept', ['note' => 'Synthetic customer accepted', 'authorized' => true], $this->admin);
        $this->actingAs($this->admin);
        $component = Livewire::test(InquiryInbox::class, ['initialInquiryId' => $inquiry->id, 'consolidated' => true])->call('transition', 'convert');
        $inquiry->refresh();
        $this->assertSame('converted', $inquiry->status);
        $component->assertRedirect(route('operations.page', ['page' => 'cases', 'view' => 'orders', 'order' => $inquiry->order_id, 'inquiry' => $inquiry->id]));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_customer_filtered_offer_list_and_actions_reject_another_customer(): void
    {
        $own = $this->offer($this->inquiry(), 1);
        $other = $this->offer($this->inquiry('Different customer inquiry', Customer::create(['company_name' => 'Foreign offers', 'is_active' => true])), 1);
        $this->actingAs($this->admin);
        $component = Livewire::test(CommercialOfferIndex::class, ['customerId' => $this->customer->id])->assertViewHas('offers', fn ($offers) => $offers->pluck('id')->all() === [$own->id]);
        $this->missing(fn () => $component->call('select', $other->id));
        $this->missing(fn () => Livewire::test(CommercialOffers::class, ['subjectType' => 'OperationInquiry', 'subjectId' => $own->subject_id, 'initialOfferId' => $other->id]));
    }

    public function test_new_offer_shortcut_preserves_customer_without_carrying_a_selected_order_or_inquiry_into_the_offer_list(): void
    {
        $inquiry = $this->inquiry('Requested customer inquiry');
        $order = $this->order('Selected customer order');
        $own = $this->offer($inquiry, 1);
        $foreignCustomer = Customer::create(['company_name' => 'Foreign shortcut customer', 'is_active' => true]);
        $foreign = $this->offer($this->inquiry('Do not disclose another customer', $foreignCustomer), 1);
        $this->actingAs($this->admin);
        foreach ([['inbox', ['inquiry' => $inquiry->id]], ['orders', ['order' => $order->id]]] as [$view, $detail]) {
            Livewire::test(CaseWorkspace::class, ['initialView' => $view, 'context' => ['customer' => $this->customer->id, 'search' => 'Old list search'] + $detail])
                ->call('setPlanningView', 'offers')->assertRedirect(OperationsPages::url('cases', ['view' => 'offers', 'customer' => $this->customer->id]));
        }
        Livewire::test(CaseWorkspace::class, ['initialView' => 'offers', 'context' => ['customer' => $this->customer->id]])
            ->assertSee($inquiry->title)->assertDontSee('Do not disclose another customer');
        $this->assertSame('draft', $own->fresh()->status);
        $this->assertSame('draft', $foreign->fresh()->status);
        $this->assertSame('new', $inquiry->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_order_customer_filter_cannot_be_escaped_by_selection_or_edit(): void
    {
        $own = $this->order();
        $other = $this->order('Outside the customer context', Customer::create(['company_name' => 'Foreign order customer', 'is_active' => true]));
        $this->actingAs($this->admin);
        $component = Livewire::test(Orders::class, ['initialOrderId' => $own->id, 'customerId' => $this->customer->id]);
        $this->missing(fn () => $component->call('selectOrder', $other->id));
        $component = Livewire::test(Orders::class, ['initialOrderId' => $own->id, 'customerId' => $this->customer->id]);
        $this->missing(fn () => $component->call('editOrder', $other->id));
        $this->assertSame('confirmed', $other->fresh()->status->value);
    }

    public function test_existing_versioned_offer_has_no_second_generic_acceptance_form(): void
    {
        $inquiry = $this->inquiry();
        $service = app(InquiryWorkflowService::class);
        $service->transition($inquiry, 1, 'verify', [], $this->admin);
        $service->transition($inquiry, 1, 'offer', ['amount' => '100', 'terms' => 'Synthetic terms'], $this->admin);
        $this->actingAs($this->admin);
        Livewire::test(InquiryInbox::class, ['initialInquiryId' => $inquiry->id])->assertDontSee('Kundenzusage · Person, Zeitpunkt, Referenz')->assertSee('Angebotsstände');
        $this->assertSame('offered', $inquiry->fresh()->status);
    }

    public function test_consolidated_inquiry_restores_filters_and_tracks_selected_and_closed_details_in_url(): void
    {
        $inquiry = $this->inquiry('Synthetic URL Inquiry');
        $this->actingAs($this->admin);
        $component = Livewire::withQueryParams(['search' => 'Synthetic URL', 'status' => 'new', 'filter' => 'all', 'detail' => 'history'])
            ->test(InquiryInbox::class, ['initialInquiryId' => $inquiry->id, 'customerId' => $this->customer->id, 'consolidated' => true])
            ->assertSet('search', 'Synthetic URL')->assertSet('statusFilter', 'new')->assertSet('filter', 'all')->assertSet('detailSection', 'history');
        $component->call('select', $inquiry->id)->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', [
            'view' => 'inbox', 'section' => 'overview', 'customer' => $this->customer->id, 'search' => 'Synthetic URL', 'status' => 'new', 'filter' => 'all', 'inquiry' => $inquiry->id, 'detail' => 'history',
        ]));
        $component->set('search', 'Updated URL')->set('filter', 'email')->set('statusFilter', 'all')
            ->call('setDetailSection', 'overview')->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', [
                'view' => 'inbox', 'section' => 'overview', 'customer' => $this->customer->id, 'search' => 'Updated URL', 'filter' => 'email', 'inquiry' => $inquiry->id,
            ]));
        $component->set('detailOpen', false)->assertSet('selectedId', null)->assertSet('detailSection', 'overview')
            ->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'overview', 'customer' => $this->customer->id, 'search' => 'Updated URL', 'filter' => 'email']));
        $this->assertSame('new', $inquiry->fresh()->status);
    }

    public function test_consolidated_orders_restore_url_and_clear_record_context_when_details_close(): void
    {
        $order = $this->order('Synthetic URL Order');
        $this->order('Newer unrequested order');
        $this->actingAs($this->admin);
        $component = Livewire::withQueryParams(['search' => 'Synthetic URL', 'status' => 'confirmed'])
            ->test(Orders::class, ['initialOrderId' => $order->id, 'customerId' => $this->customer->id, 'initialSection' => 'history', 'consolidated' => true])
            ->assertSet('search', 'Synthetic URL')->assertSet('statusFilter', 'confirmed')->assertSet('selectedOrderId', $order->id);
        $component->call('openDetails', $order->id)->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', [
            'view' => 'orders', 'section' => 'history', 'customer' => $this->customer->id, 'search' => 'Synthetic URL', 'status' => 'confirmed', 'order' => $order->id,
        ]));
        $component->set('search', 'Updated Order')->set('statusFilter', 'planned')->call('setDetailSection', 'overview')
            ->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', ['view' => 'orders', 'section' => 'overview', 'customer' => $this->customer->id, 'search' => 'Updated Order', 'status' => 'planned', 'order' => $order->id]));
        $component->set('detailOpen', false)->assertSet('selectedOrderId', null)->assertSet('fulfilmentRecordId', null)
            ->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', ['view' => 'orders', 'section' => 'overview', 'customer' => $this->customer->id, 'search' => 'Updated Order', 'status' => 'planned']));
        $component->call('resetFilters')->assertDispatched('rt-workspace-url', url: OperationsPages::url('cases', ['view' => 'orders', 'section' => 'overview', 'customer' => $this->customer->id]));
        Livewire::withQueryParams(['search' => 'Synthetic URL', 'status' => 'confirmed'])->test(Orders::class, ['consolidated' => true])->assertSet('selectedOrderId', null)->assertSet('detailOpen', false);
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_consolidated_mount_rejects_array_long_and_unknown_list_query_values(): void
    {
        $this->actingAs($this->admin);
        foreach ([['search' => ['private']], ['search' => str_repeat('x', 101)], ['status' => 'unsupported'], ['filter' => ['email']], ['filter' => 'unknown'], ['detail' => 'proofs']] as $query) {
            Livewire::withQueryParams($query)->test(InquiryInbox::class, ['consolidated' => true])->assertStatus(422);
        }
        foreach ([['search' => ['private']], ['search' => str_repeat('x', 101)], ['status' => 'new'], ['status' => ['confirmed']], ['filter' => 'email']] as $query) {
            Livewire::withQueryParams($query)->test(Orders::class, ['consolidated' => true])->assertStatus(422);
        }
    }

    public function test_case_switch_preserves_only_current_list_and_customer_context_not_detail_ids(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);
        $referer = OperationsPages::url('cases', ['view' => 'orders', 'section' => 'history', 'search' => 'Latest search', 'status' => 'confirmed', 'order' => 999999, 'inquiry' => 999999, 'record' => 999999, 'record_type' => 'proof', 'customer' => 999999]);
        $component = Livewire::withQueryParams([])->withHeaders(['Referer' => $referer])->test(CaseWorkspace::class, [
            'initialView' => 'orders', 'context' => ['customer' => $this->customer->id, 'order' => $order->id],
        ]);
        $this->withPostReferer($component, $referer);
        $component->call('setView', 'orders')->assertRedirect(OperationsPages::url('cases', ['view' => 'orders', 'customer' => $this->customer->id, 'search' => 'Latest search', 'status' => 'confirmed']));
        $component->call('setView', 'inbox')->assertRedirect(OperationsPages::url('cases', ['view' => 'inbox', 'customer' => $this->customer->id, 'search' => 'Latest search']));
        $component->call('setView', 'offers')->assertRedirect(OperationsPages::url('cases', ['view' => 'offers', 'customer' => $this->customer->id]));
        $foreignReferer = 'https://other.example.test/arbeitsplatz/ansicht/cases?view=orders&search=Untrusted';
        $foreign = Livewire::withHeaders(['Referer' => $foreignReferer])
            ->test(CaseWorkspace::class, ['initialView' => 'orders', 'context' => ['customer' => $this->customer->id, 'search' => 'Known search']]);
        $this->withPostReferer($foreign, $foreignReferer)
            ->call('setView', 'orders')->assertRedirect(OperationsPages::url('cases', ['view' => 'orders', 'customer' => $this->customer->id, 'search' => 'Known search']));
    }

    public function test_portal_only_inbox_remains_available_when_unrelated_time_export_schema_is_missing(): void
    {
        foreach (['2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::drop('work_time_export_items');
        Schema::drop('work_time_exports');
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        foreach (['operations.inquiries.manage', 'operations.manage', 'operations.costs.manage', 'customers.portal.publish'] as $ability) {
            Gate::define($ability, fn () => false);
        }
        Gate::define('customers.portal.manage', fn ($user) => $user->id === $employee->id);
        DB::table('customer_portal_manager_grants')->insert(['customer_id' => $this->customer->id, 'user_id' => $employee->id, 'active' => true, 'abilities' => json_encode(['customers.portal.manage']), 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $this->actingAs($employee);
        $this->assertSame(['inbox'], array_keys(CaseWorkspace::availableViews($employee)));
        $this->assertSame(['portal'], array_keys(CaseWorkspace::availableSections($employee)));
        Livewire::test(CaseWorkspace::class, ['initialView' => '', 'context' => ['customer' => $this->customer->id]])
            ->assertSet('view', 'inbox')->assertSet('section', 'portal')->assertDontSee('Posteingang')->assertDontSee('inquiry-filters', false);
        Livewire::test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'overview', 'context' => ['customer' => $this->customer->id]])->assertForbidden();
        $this->assertDatabaseCount('customer_portal_invitations', 0);
        $this->assertDatabaseCount('customer_portal_deliveries', 0);
    }
}
