<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerPortalIntegrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private Customer $customer;

    private CustomerPortalIdentity $identity;

    private CustomerPortalSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        config(['customer_portal.delivery_enabled' => false, 'activitylog.enabled' => false]);
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic HTTP customer', 'is_active' => true]);
        $contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic HTTP contact', 'email' => 'http-portal@example.test', 'roles' => ['ordering', 'acceptance'], 'is_active' => true, 'updated_by' => $admin->id]);
        $this->identity = CustomerPortalIdentity::create(['name' => 'Synthetic HTTP identity', 'email' => $contact->email, 'password' => 'Synthetic-Http-Password-7!', 'active' => true, 'revision' => 1, 'email_verified_at' => now()]);
        $this->setting = CustomerPortalSetting::create(['customer_id' => $this->customer->id, 'enabled' => true, 'modules' => CustomerPortalSetting::MODULES, 'revision' => 1, 'notifications' => [], 'updated_by' => $admin->id]);
        CustomerPortalMembership::create(['identity_id' => $this->identity->id, 'customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'role' => 'coordinator', 'status' => 'active', 'revision' => 1, 'capabilities' => CustomerPortalMembership::ROLES['coordinator'], 'location_ids' => [], 'activated_at' => now(), 'updated_by' => $admin->id]);
    }

    private function login(): void
    {
        $this->withSession(['customer_portal_identity_revision' => $this->identity->revision, 'customer_portal_authenticated_at' => now()->timestamp]);
        $this->actingAs($this->identity, 'customer_portal');
        Auth::shouldUse('web');
    }

    public function test_private_workspace_rejects_an_employee_session_without_customer_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]), 'web');
        $this->get('/kundenportal/c/'.$this->customer->id.'/overview')->assertRedirect('/kundenportal/anmelden');
    }

    public function test_customer_session_cannot_open_internal_operations(): void
    {
        $this->login();
        $this->get('/arbeitsplatz/customer-portal')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_customer_does_not_pass_internal_admin_gate(): void
    {
        $this->assertFalse(Gate::forUser($this->identity)->allows('operations.manage'));
        $this->assertFalse(Gate::forUser($this->identity)->allows('customers.portal.manage'));
    }

    public function test_workspace_and_offers_have_private_cache_headers_and_current_scope(): void
    {
        $this->login();
        $response = $this->get('/kundenportal/c/'.$this->customer->id.'/overview')->assertOk()->assertSee('Synthetic HTTP customer')->assertDontSee('Mitarbeiterverwaltung');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->get('/kundenportal/c/'.$this->customer->id.'/offers')->assertOk();
        Mail::assertNothingSent();
    }

    public function test_foreign_customer_id_is_not_a_data_source(): void
    {
        $outside = Customer::create(['company_name' => 'Synthetic outside secret', 'is_active' => true]);
        $this->login();
        $this->get('/kundenportal/c/'.$outside->id.'/overview')->assertForbidden()->assertDontSee('Synthetic outside secret');
        $this->get('/kundenportal/c/'.$outside->id.'/export')->assertForbidden();
    }

    public function test_disabling_customer_revokes_existing_browser_session(): void
    {
        $this->login();
        $this->setting->update(['enabled' => false]);
        $this->get('/kundenportal/c/'.$this->customer->id.'/orders')->assertRedirect('/kundenportal/anmelden');
        $this->assertGuest('customer_portal');
    }

    public function test_removed_module_has_no_direct_http_backdoor(): void
    {
        $this->login();
        $this->setting->update(['modules' => ['orders']]);
        $this->get('/kundenportal/c/'.$this->customer->id.'/reports')->assertForbidden();
        $this->get('/kundenportal/c/'.$this->customer->id.'/export')->assertForbidden();
        $this->get('/kundenportal/c/'.$this->customer->id.'/bericht')->assertForbidden();
    }

    public function test_portal_logging_is_disabled_for_secret_routes_and_persistent_middleware_registered(): void
    {
        $route = app('router')->getRoutes()->getByName('customer-portal.invitation');
        $this->assertContains(LogActivity::class, $route->excludedMiddleware());
        $this->assertStringContainsString('EnsureCustomerPortalAccess', file_get_contents(app_path('Providers/AppServiceProvider.php')));
    }
}
