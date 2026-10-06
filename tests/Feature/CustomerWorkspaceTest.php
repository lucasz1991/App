<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Customers;
use App\Livewire\Operations\CustomerPortalManagement;
use App\Livewire\Operations\CustomerRelations;
use App\Livewire\Operations\CustomerWorkspace;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerWorkspaceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private array $permissions = [];

    private Customer $customer;

    private Customer $other;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        config(['customer_portal.delivery_enabled' => false]);
        Mail::fake();
        Bus::fake();
        $this->withoutVite();
        foreach (['operations.manage', 'operations.inquiries.manage', 'customers.portal.manage', 'customers.portal.publish', 'customers.portal.automation'] as $ability) {
            Gate::define($ability, fn ($user) => in_array($ability, $this->permissions[$user->id] ?? [], true));
        }
        $this->approver = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Scoped Customer', 'email' => 'crm-private@example.test', 'notes' => 'PRIVATE CRM CONTENT', 'is_active' => true]);
        $this->other = Customer::create(['company_name' => 'Other Customer', 'notes' => 'OTHER PRIVATE CRM', 'is_active' => true]);
        CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Approved Contact', 'email' => 'contact@example.test', 'roles' => ['dispatch'], 'is_active' => true, 'updated_by' => $this->approver->id]);
    }

    private function actor(array $abilities): User
    {
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->permissions[$actor->id] = $abilities;

        return $actor;
    }

    private function grant(User $actor, array $abilities): void
    {
        DB::table('customer_portal_manager_grants')->insert(['customer_id' => $this->customer->id, 'user_id' => $actor->id, 'abilities' => json_encode($abilities), 'active' => true, 'approved_by' => $this->approver->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_crm_only_mounts_master_without_unauthorized_relations_child(): void
    {
        $actor = $this->actor(['operations.manage']);
        $this->assertSame(['master'], array_keys(CustomerWorkspace::availableViews($actor)));
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['customer' => $this->customer->id]])
            ->assertSet('view', 'master')->assertSee('PRIVATE CRM CONTENT')->assertDontSee('Approved Contact')->assertDontSee('Portalverwaltung');
    }

    public function test_inquiries_only_exposes_contacts_and_conditions_without_crm_fields(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $this->assertSame(['contacts', 'conditions'], array_keys(CustomerWorkspace::availableViews($actor)));
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['customer' => $this->customer->id]])
            ->assertSet('view', 'contacts')->assertSee('Approved Contact')->assertDontSee('PRIVATE CRM CONTENT')->assertDontSee('crm-private@example.test')
            ->call('setView', 'conditions')->assertSee('Keine Konditionen hinterlegt.')->assertDontSee('Approved Contact');
    }

    public function test_portal_only_list_and_children_are_customer_scoped(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $this->assertSame(['portal'], array_keys(CustomerWorkspace::availableViews($actor)));
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)
            ->assertSet('customerId', null)->assertSet('view', 'portal')->assertSee('Scoped Customer')->assertDontSee('Other Customer')
            ->assertDontSee('crm-private@example.test')->call('selectCustomer', $this->customer->id)
            ->assertSet('customerId', $this->customer->id)
            ->assertDontSee('PRIVATE CRM CONTENT')->assertDontSee('crm-private@example.test')->assertDontSee('Kontingent anfragen')->assertDontSee('Stand freigeben');
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
    }

    public function test_portal_scope_is_rechecked_for_explicit_customer_and_section(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['initialView' => 'portal', 'initialSection' => 'automation', 'context' => ['customer' => $this->customer->id]])->assertForbidden();
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['initialView' => 'master', 'context' => ['customer' => $this->customer->id]])->assertForbidden();
    }

    public function test_portal_only_cannot_select_an_ungranted_customer(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['customer' => $this->other->id]]);
    }

    public function test_mixed_rights_do_not_extend_portal_to_other_customers(): void
    {
        $actor = $this->actor(['operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['initialView' => 'portal', 'context' => ['customer' => $this->customer->id]])
            ->assertDontSee('Other Customer')->call('showList')->assertSee('Other Customer')
            ->call('selectCustomer', $this->other->id)->assertSet('view', 'contacts')->assertSet('contextRevision', 2)
            ->assertDontSee('Portalverwaltung')->assertDontSee('OTHER PRIVATE CRM')->call('setView', 'portal')->assertForbidden();
    }

    public function test_embedded_relations_cannot_change_customer_or_leak_other_selector(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($actor)->test(CustomerRelations::class, ['customerId' => $this->customer->id, 'section' => 'contacts', 'embedded' => true])
            ->assertSee('Approved Contact')->assertDontSee('Other Customer')->assertDontSee('Kunde auswählen')
            ->set('customerId', (string) $this->other->id)->assertForbidden();
    }

    public function test_embedded_master_cannot_edit_or_activate_another_customer(): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->test(Customers::class, ['customerId' => $this->customer->id, 'embedded' => true])
            ->assertDontSee('Other Customer')->call('editCustomer', $this->other->id)->assertForbidden();
        Livewire::actingAs($actor)->test(Customers::class, ['customerId' => $this->customer->id, 'embedded' => true])
            ->call('toggleCustomerActive', $this->other->id)->assertForbidden();
        $this->assertTrue($this->other->fresh()->is_active);
    }

    public function test_embedded_portal_context_cannot_change_customer_or_tab(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        Livewire::actingAs($actor)->test(CustomerPortalManagement::class, ['customerId' => $this->customer->id, 'embedded' => true, 'tab' => 'access'])
            ->assertDontSee('portal-management-customer')->call('selectCustomer', $this->other->id)->assertForbidden();
        Livewire::actingAs($actor)->test(CustomerPortalManagement::class, ['customerId' => $this->customer->id, 'embedded' => true, 'tab' => 'access'])
            ->call('setTab', 'requests')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_no_rights_or_malformed_customer_fail_closed(): void
    {
        $actor = $this->actor([]);
        $this->assertSame([], CustomerWorkspace::availableViews($actor));
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->assertForbidden();
        $actor = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['customer' => '12invalid']])->assertNotFound();
    }

    public function test_customer_context_changes_remount_modal_state_and_respect_revocation(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $component = Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['customer' => $this->customer->id]])->call('setSection', 'delivery')->assertSet('contextRevision', 1);
        DB::table('customer_portal_manager_grants')->where('user_id', $actor->id)->update(['active' => false]);
        $component->call('setSection', 'access')->assertForbidden();
    }

    public function test_contextual_portal_request_only_opens_existing_review_without_mutation(): void
    {
        $actor = $this->actor(['customers.portal.manage', 'customers.portal.publish']);
        $this->grant($actor, ['customers.portal.manage', 'customers.portal.publish']);
        $identity = CustomerPortalIdentity::create(['name' => 'Inactive fixture', 'email' => 'inactive@example.test', 'password' => 'Synthetic-password-123', 'active' => false, 'revision' => 1]);
        $member = CustomerPortalMembership::create(['customer_id' => $this->customer->id, 'contact_id' => CustomerContact::first()->id, 'identity_id' => $identity->id, 'role' => 'reader', 'status' => 'pending', 'capabilities' => [], 'location_ids' => [], 'updated_by' => $this->approver->id]);
        $request = CustomerPortalRequest::create(['customer_id' => $this->customer->id, 'membership_id' => $member->id, 'identity_id' => $identity->id, 'client_uuid' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'kind' => 'change', 'title' => 'Scoped change proposal', 'payload' => ['message' => 'Please review'], 'status' => 'submitted']);
        Livewire::actingAs($actor)->test(CustomerPortalManagement::class, ['customerId' => $this->customer->id, 'tab' => 'requests', 'embedded' => true, 'initialRecordId' => $request->id, 'initialSource' => 'request'])
            ->assertSet('formOpen', true)->assertSet('modal', 'review-request')->assertSet('recordId', $request->id)->assertSee('Scoped change proposal');
        $this->assertSame('submitted', $request->fresh()->status);
        $this->assertFalse($identity->fresh()->active);
        $this->assertSame('pending', $member->fresh()->status);
        $this->assertSame(0, DB::table('customer_portal_deliveries')->count());
        Mail::assertNothingSent();
        Livewire::actingAs($actor)->test(CustomerPortalManagement::class, ['customerId' => $this->customer->id, 'tab' => 'access', 'embedded' => true, 'initialRecordId' => $request->id, 'initialSource' => 'request'])->assertNotFound();
    }

    public function test_default_customer_overview_uses_standard_list_without_implicit_profile(): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)
            ->assertSet('customerId', null)->assertSet('view', 'master')
            ->assertSee('customer-list-search', false)->assertSee('data-rt-premium-table', false)
            ->assertSee('Scoped Customer')->assertSee('Other Customer')->assertSee('Kundenprofil öffnen')
            ->assertSee('Bearbeiten')->assertSee('Kunde anlegen')->assertDontSee('customer-workspace-select', false)
            ->assertDontSee('PRIVATE CRM CONTENT')->assertDontSee('Kundenstammdaten');
    }

    public function test_crm_list_search_status_sort_and_named_pagination(): void
    {
        $inactive = Customer::create(['company_name' => 'Archived Railway', 'is_active' => false]);
        for ($i = 1; $i <= 17; $i++) {
            Customer::create(['company_name' => sprintf('Demo Customer %02d', $i), 'is_active' => true]);
        }
        $actor = $this->actor(['operations.manage']);
        $component = Livewire::actingAs($actor)->test(CustomerWorkspace::class)
            ->assertViewHas('customers', fn ($items) => $items->total() === 19 && $items->count() === 15 && $items->getPageName() === 'customersPage')
            ->assertDontSee('Archived Railway')->call('gotoPage', 2, 'customersPage')
            ->assertSet('paginators.customersPage', 2)->set('search', 'crm-private@example.test')
            ->assertSet('paginators.customersPage', 1)->assertSee('Scoped Customer')->assertDontSee('Other Customer')
            ->call('resetFilters')->set('activeFilter', 'inactive')->assertSee('Archived Railway')->assertDontSee('Scoped Customer')
            ->set('activeFilter', 'all')->call('sort', 'company_name', 'desc')
            ->assertViewHas('customers', fn ($items) => $items->first()->id === $this->customer->id)
            ->set('perPage', 30)->assertViewHas('customers', fn ($items) => $items->count() === 20);
        $this->assertFalse($inactive->fresh()->is_active);
        $component->set('search', $this->customer->customer_number)->assertViewHas('customers', fn ($items) => $items->total() === 1);
    }

    public function test_opening_profile_and_back_preserves_list_context_and_url(): void
    {
        $actor = $this->actor(['operations.manage']);
        $component = Livewire::actingAs($actor)->test(CustomerWorkspace::class)
            ->set('search', 'Scoped')->set('activeFilter', 'all')->set('perPage', 30)->call('sort', 'company_name', 'desc')
            ->call('toggleCustomerSelection', $this->customer->id)->assertSet('selectedListCustomerId', $this->customer->id)
            ->call('selectCustomer', $this->customer->id)->assertSee('PRIVATE CRM CONTENT')->assertDontSee('customer-list-search', false)
            ->assertDispatched('rt-workspace-url', fn ($event, $params) => str_contains($params['url'], 'customer='.$this->customer->id) && str_contains($params['url'], 'search=Scoped'))
            ->call('showList')->assertSet('customerId', null)->assertSet('search', 'Scoped')->assertSet('activeFilter', 'all')
            ->assertSet('perPage', 30)->assertSet('sortDir', 'desc')->assertSee('Scoped Customer')->assertDontSee('Other Customer')
            ->assertDontSee('PRIVATE CRM CONTENT')->assertDontSee('customer-workspace-select', false);
        $component->assertDispatched('rt-workspace-url', fn ($event, $params) => ! str_contains($params['url'], 'customer=') && str_contains($params['url'], 'status=all'));
    }

    public function test_initial_list_context_is_restored_without_loading_another_profile(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            Customer::create(['company_name' => sprintf('Demo %02d', $i), 'is_active' => true]);
        }
        Livewire::actingAs($this->actor(['operations.manage']))->test(CustomerWorkspace::class, [
            'context' => ['search' => 'Demo', 'status' => 'all', 'per_page' => '15', 'customersPage' => '2', 'sort' => 'company_name', 'direction' => 'desc'],
        ])->assertSet('customerId', null)->assertSet('paginators.customersPage', 2)
            ->assertViewHas('customers', fn ($items) => $items->total() === 20 && $items->count() === 5)
            ->assertDontSee('PRIVATE CRM CONTENT')->call('selectCustomer', $this->customer->id)
            ->call('showList')->assertSet('paginators.customersPage', 2)
            ->assertViewHas('customers', fn ($items) => $items->count() === 5);
    }

    public function test_restricted_lists_do_not_query_or_render_private_crm_fields(): void
    {
        foreach (['operations.inquiries.manage', 'customers.portal.manage'] as $ability) {
            $actor = $this->actor([$ability]);
            if ($ability === 'customers.portal.manage') {
                $this->grant($actor, [$ability]);
            }
            $component = Livewire::actingAs($actor)->test(CustomerWorkspace::class)
                ->assertSee('Scoped Customer')->assertDontSee('crm-private@example.test')->assertDontSee('PRIVATE CRM CONTENT')
                ->assertDontSee('Bearbeiten')->assertDontSee('customer-status-filter', false)
                ->assertViewHas('customers', fn ($items) => array_keys($items->first()->getAttributes()) === ['id', 'company_name'])
                ->set('search', 'crm-private@example.test')->assertViewHas('customers', fn ($items) => $items->total() === 0)
                ->set('search', 'PRIVATE CRM')->assertViewHas('customers', fn ($items) => $items->total() === 0);
            $component->call('editCustomer', $this->customer->id)->assertForbidden();
        }
    }

    public function test_list_permissions_and_portal_grants_are_rechecked_after_revocation(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $component = Livewire::actingAs($actor)->test(CustomerWorkspace::class)->assertSee('Scoped Customer');
        DB::table('customer_portal_manager_grants')->where('user_id', $actor->id)->update(['active' => false]);
        $component->set('search', 'Scoped')->assertForbidden();
        $actor = $this->actor(['operations.manage']);
        $component = Livewire::actingAs($actor)->test(CustomerWorkspace::class);
        $actor->update(['status' => false]);
        $component->call('selectCustomer', $this->customer->id)->assertForbidden();
    }

    public function test_manipulated_list_settings_fail_closed(): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->set('perPage', 100000)->assertStatus(422);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->set('activeFilter', 'invalid')->assertStatus(422);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->set('search', str_repeat('a', 101))->assertStatus(422);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->call('sort', 'notes', 'asc')->assertForbidden();
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->call('sort', 'company_name', 'drop table')->assertStatus(422);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['per_page' => '1000']])->assertStatus(422);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class, ['context' => ['search' => ['bad']]])->assertStatus(422);
        $actor = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->set('activeFilter', 'inactive')->assertForbidden();
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->call('sort', 'city', 'asc')->assertForbidden();
    }

    public function test_list_create_and_edit_reuse_modals_and_clear_old_form_context(): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->test(CustomerWorkspace::class)->set('search', 'Scoped')
            ->call('editCustomer', $this->customer->id)->assertSet('editingCustomerId', $this->customer->id)
            ->assertSee('customer-list-search', false)->assertSee('Kunde bearbeiten')
            ->call('closeCustomerForm')->assertSet('editingCustomerId', null)->assertDontSee('Kunde bearbeiten')
            ->call('createCustomer')->assertSet('startCreating', true)->assertSee('Neuen Kunden anlegen')
            ->call('selectCustomer', $this->other->id)->assertSet('startCreating', false)->assertSet('editingCustomerId', null)
            ->assertSet('contextRevision', 4)->assertDontSee('crm-private@example.test');
        Livewire::actingAs($actor)->test(Customers::class, ['customerId' => $this->customer->id, 'embedded' => true, 'modalOnly' => true, 'startEditing' => true])
            ->assertSet('formOpen', true)->assertSet('editingCustomerId', $this->customer->id)
            ->assertDontSee('Kundenstammdaten')->set('formOpen', false)->assertSet('email', '')->assertDispatched('customer-form-closed');
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
    }

    public function test_modal_edit_saves_existing_customer_without_portal_or_mail_side_effects(): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->test(Customers::class, ['customerId' => $this->customer->id, 'embedded' => true, 'modalOnly' => true, 'startEditing' => true])
            ->set('companyName', 'Updated Scoped Customer')->call('saveCustomer')->assertHasNoErrors()
            ->assertSet('formOpen', false)->assertDispatched('customer-record-saved', customerId: $this->customer->id);
        $this->assertSame('Updated Scoped Customer', $this->customer->fresh()->company_name);
        $this->assertSame('Other Customer', $this->other->fresh()->company_name);
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
        $this->assertSame(0, DB::table('customer_portal_deliveries')->count());
        Mail::assertNothingSent();
    }

    public function test_missing_or_deleted_list_customer_never_opens_a_different_customer(): void
    {
        $component = Livewire::actingAs($this->actor(['operations.manage']))->test(CustomerWorkspace::class);
        $this->customer->delete();
        $this->expectException(ModelNotFoundException::class);
        $component->call('selectCustomer', $this->customer->id);
    }

    public function test_late_modal_events_cannot_close_or_redirect_a_new_customer_context(): void
    {
        Livewire::actingAs($this->actor(['operations.manage']))->test(CustomerWorkspace::class)
            ->call('editCustomer', $this->customer->id)->assertSet('contextRevision', 1)
            ->call('editCustomer', $this->other->id)->assertSet('contextRevision', 2)
            ->dispatch('customer-form-closed', workspaceRevision: 1)->assertSet('editingCustomerId', $this->other->id)
            ->dispatch('customer-record-saved', customerId: $this->customer->id, workspaceRevision: 1)
            ->assertSet('customerId', null)->assertSet('editingCustomerId', $this->other->id)
            ->dispatch('customer-record-saved', customerId: $this->other->id, workspaceRevision: 2)
            ->assertSet('customerId', null)->assertSet('editingCustomerId', null)->assertSet('contextRevision', 3);
    }
}
