<?php

namespace Tests\Feature;

use App\Livewire\CustomerPortal\Workspace;
use App\Livewire\Operations\CustomerPortalManagement;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAttachment;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalInvitation;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalMessage;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSetting;
use App\Models\CustomerPortalSubmission;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerPortalUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    private CustomerContact $contact;

    private CustomerPortalIdentity $identity;

    private CustomerPortalMembership $membership;

    private CustomerPortalSetting $setting;

    private CustomerPortalPublication $publication;

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
        foreach (['manage', 'publish', 'automation'] as $ability) {
            Gate::define('customers.portal.'.$ability, fn ($u) => $u instanceof User && $u->isAdmin());
        }
        Gate::define('operations.inquiries.manage', fn ($u) => $u instanceof User && $u->isAdmin());
        if (! Route::has('customer-portal.logout')) {
            Route::post('/test-portal-logout', fn () => response(''))->name('customer-portal.logout');
        }
        if (! Route::has('customer-portal.workspace')) {
            Route::get('/test-portal/{customer}/{section?}', fn () => response(''))->name('customer-portal.workspace');
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic UI Rail', 'is_active' => true, 'notes' => 'PRIVATE CUSTOMER NOTE']);
        $this->contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic UI Contact', 'email' => 'portal-ui@example.test', 'roles' => ['ordering', 'acceptance'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $this->identity = CustomerPortalIdentity::create(['name' => 'Synthetic UI Identity', 'email' => $this->contact->email, 'password' => 'Synthetic-ui-password-123!', 'active' => true, 'email_verified_at' => now(), 'revision' => 1]);
        $this->setting = CustomerPortalSetting::create(['customer_id' => $this->customer->id, 'enabled' => true, 'revision' => 1, 'modules' => CustomerPortalSetting::MODULES, 'notifications' => [], 'automation_mode' => 'manual', 'updated_by' => $this->admin->id]);
        $this->membership = CustomerPortalMembership::create(['identity_id' => $this->identity->id, 'customer_id' => $this->customer->id, 'contact_id' => $this->contact->id, 'role' => 'coordinator', 'status' => 'active', 'revision' => 1, 'capabilities' => CustomerPortalMembership::ROLES['coordinator'], 'location_ids' => [], 'activated_at' => now(), 'updated_by' => $this->admin->id]);
        $location = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic UI Yard', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->admin->id]);
        $order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Synthetic UI Duty', 'status' => 'confirmed', 'priority' => 'normal', 'starts_at' => '2027-05-14 20:00:00', 'ends_at' => '2027-05-15 04:00:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'notes' => 'PRIVATE ORDER NOTE']);
        $order->forceFill(['customer_portal_location_id' => $location->id])->save();
        $this->publication = app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'order', $order->id, ['location_id' => $location->id]);
    }

    private function portal(string $tab = 'orders')
    {
        return Livewire::actingAs($this->identity, 'customer_portal')->test(Workspace::class, ['customer' => $this->customer->id, 'section' => $tab]);
    }

    public function test_portal_renders_standard_private_rows_without_internal_shell_or_employee_data(): void
    {
        $this->portal()->assertSee('Synthetic UI Duty')->assertSee('data-rt-premium-table', false)->assertDontSee('PRIVATE')->assertDontSee('live-location')->assertDontSee('operations.capture');
        Mail::assertNothingSent();
    }

    public function test_navigation_groups_keep_profile_last_and_mobile_content_inert_contract(): void
    {
        $this->portal('overview')
            ->assertSee('rtPortalNavigation', false)
            ->assertSee('portal-mobile-menu', false)
            ->assertSee('aria-controls="portal-navigation"', false)
            ->assertSee('x-bind:inert="mobile && open"', false)
            ->assertSeeInOrder(['Arbeitsplatz', 'Leistungen', 'Austausch', 'Mein Profil'])
            ->assertSee('Ihre nächsten Einsätze')
            ->assertDontSee('PRIVATE');
        Mail::assertNothingSent();
    }

    public function test_portal_record_status_remains_textual_and_detail_action_has_specific_label(): void
    {
        $this->portal('orders')
            ->assertSee('data-state="confirmed"', false)
            ->assertSee('Bestätigt')
            ->assertSee('aria-label="Öffnen: Synthetic UI Duty"', false);
    }

    public function test_portal_request_form_groups_required_fields_and_preserves_optional_fields(): void
    {
        $this->portal()->call('create')
            ->assertSee('rt-customer-portal__form-section', false)
            ->assertSee('rt-customer-portal__optional', false)
            ->assertSee('form.planned_break_minutes', false)
            ->assertSee('form.train_reference', false)
            ->assertSee('form.cost_center', false)
            ->assertSee('data-rt-date-time-field', false)
            ->assertSee('data-rt-number-input', false);
        Mail::assertNothingSent();
    }

    public function test_login_has_own_portal_shell_without_staff_brand_renderer_and_keeps_credentials_contract(): void
    {
        $this->withoutVite();
        $this->get('/kundenportal/anmelden')
            ->assertOk()
            ->assertSee('rt-customer-portal__auth-shell', false)
            ->assertSee('name="email"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertDontSee('rt-brand-stage', false)
            ->assertDontSee('rt-auth.css', false);
        Mail::assertNothingSent();
    }

    public function test_service_validation_opens_optional_fields_without_saving_an_invalid_request(): void
    {
        $component = $this->portal()->call('create')
            ->set('form.title', 'Synthetic optional-field validation')
            ->set('form.role_name', 'Rangierbegleitung')
            ->set('form.starts_at', '2027-05-20T06:00')
            ->set('form.ends_at', '2027-05-20T14:00')
            ->set('form.location_name', 'Synthetic UI Yard')
            ->set('form.reference', str_repeat('R', 101))
            ->call('save')->assertHasErrors('reference')->assertSet('formOpen', true);
        $document = new \DOMDocument;
        $prior = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//details[contains(@class,"rt-customer-portal__optional") and @open]')->length);
        $this->assertSame(0, CustomerPortalSubmission::count());
        Mail::assertNothingSent();
    }

    public function test_every_enabled_section_and_calendar_variant_renders(): void
    {
        $component = $this->portal('overview');
        foreach (array_keys(Workspace::TABS) as $tab) {
            $component->call('setTab', $tab)->assertSet('tab', $tab)->assertStatus(200);
        }
        $component->call('setTab', 'calendar')->set('anchorDate', '2027-05-14');
        foreach (['day', 'week', 'month', 'list'] as $view) {
            $component->call('switchView', $view)->assertSee('data-multi-toggle', false)->assertStatus(200);
        }
        $component->call('nextPeriod')->call('previousPeriod')->assertStatus(200);
    }

    public function test_request_modal_uses_shared_date_number_and_modal_components(): void
    {
        $component = $this->portal()->call('create')->assertSet('form.intent', 'quote')->assertSet('form.accept_conditions', false);
        $component->assertSee('data-rt-number-input', false)->assertSee('data-rt-date-time-field', false)->assertDontSee('wire:submit="save" wire:submit', false);
        $component->call('addPosition')->assertCount('form.positions', 1)->call('removePosition', 0)->assertCount('form.positions', 0);
    }

    public function test_customer_and_record_identity_cannot_be_overwritten_from_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->portal()->set('customerId', $this->customer->id + 1);
    }

    public function test_portal_cannot_switch_to_unassigned_customer(): void
    {
        $outside = Customer::create(['company_name' => 'Outside UI Customer', 'is_active' => true]);
        $this->portal()->call('switchCustomer', $outside->id)->assertForbidden();
    }

    public function test_customer_or_contact_pause_revokes_open_component_on_next_action(): void
    {
        $component = $this->portal();
        $this->contact->update(['is_active' => false]);
        $component->call('create')->assertForbidden();
    }

    public function test_removed_module_is_not_available_in_navigation_or_actions(): void
    {
        $this->setting->update(['modules' => ['orders', 'documents']]);
        $component = $this->portal()->assertDontSee('Leistung anfragen')->assertDontSee('wire:click="setTab(\'reports\')"', false);
        $component->call('create')->assertForbidden();
    }

    public function test_reader_navigation_hides_unavailable_request_message_and_report_areas(): void
    {
        $this->membership->update(['role' => 'reader', 'capabilities' => CustomerPortalMembership::ROLES['reader']]);
        $component = $this->portal()->assertDontSee('wire:click="setTab(\'requests\')"', false)->assertDontSee('wire:click="setTab(\'messages\')"', false)->assertDontSee('wire:click="setTab(\'reports\')"', false)->assertDontSee('Leistung anfragen');
        $component->call('setTab', 'requests')->assertForbidden();
    }

    public function test_foreign_or_withdrawn_publication_is_not_a_detail_source(): void
    {
        $component = $this->portal();
        app(CustomerPortalPublicationService::class)->withdraw($this->admin, $this->publication->id, $this->publication->revision, 'Synthetic withdrawal');
        $this->expectException(ModelNotFoundException::class);
        $component->call('openDetails', 'order', $this->publication->id);
    }

    public function test_management_customer_access_is_scoped_and_requires_internal_identity(): void
    {
        Auth::guard('web')->logout();
        $this->portal()->assertStatus(200);
        Livewire::test(CustomerPortalManagement::class)->assertForbidden();
    }

    public function test_management_forms_are_closed_by_default_and_contact_confirmation_is_explicit(): void
    {
        $component = Livewire::actingAs($this->admin, 'web')->test(CustomerPortalManagement::class)->assertSet('formOpen', false)->call('edit', 'contact', $this->membership->id)->assertSet('form.confirmed', false);
        $component->call('save')->assertHasErrors('form.confirmed');
        $this->assertSame(1, $this->membership->fresh()->revision);
        Mail::assertNothingSent();
    }

    public function test_management_all_tabs_render_standard_lists_without_real_mail_activation(): void
    {
        $component = Livewire::actingAs($this->admin, 'web')->test(CustomerPortalManagement::class)->assertSee('$wire.selectCustomer(Number($event.target.value))', false);
        foreach (array_keys(CustomerPortalManagement::TABS) as $tab) {
            $component->call('setTab', $tab)->assertSet('tab', $tab)->assertSee('data-rt-premium-table', false)->assertStatus(200);
        }
        Mail::assertNothingSent();
    }

    public function test_management_selected_customer_is_locked(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin, 'web')->test(CustomerPortalManagement::class)->set('customerId', 900);
    }

    public function test_quote_form_submits_once_without_booking_terms_or_real_mail(): void
    {
        $this->portal()->call('create')->set('form.title', 'Synthetic portal request')->set('form.role_name', 'Rangierbegleitung')->set('form.starts_at', '2027-05-20T06:00')->set('form.ends_at', '2027-05-20T14:00')->set('form.location_name', 'Synthetic UI Yard')->call('save')->assertHasNoErrors()->assertSet('formOpen', false);
        $submission = CustomerPortalSubmission::where('customer_id', $this->customer->id)->sole();
        $this->assertSame('quote', $submission->payload['intent']);
        $this->assertFalse($submission->payload['accept_conditions']);
        $this->assertSame(1, $submission->items()->count());
        Mail::assertNothingSent();
    }

    public function test_template_requires_new_dates_and_does_not_reuse_commercial_approval(): void
    {
        $this->portal()->call('templateFromOrder', $this->publication->id)->assertSet('form.title', 'Synthetic UI Duty')->assertSet('form.starts_at', '')->assertSet('form.ends_at', '')->assertSet('form.intent', 'quote')->assertSet('form.accept_conditions', false)->assertSet('terms', []);
    }

    public function test_reopening_a_closed_form_does_not_reuse_previous_uploads_or_booking_data(): void
    {
        $component = $this->portal()->call('create')->set('form.accept_conditions', true)->set('form.starts_at', '2027-05-20T06:00')->set('formOpen', false)->call('create');
        $component->assertSet('form.accept_conditions', false)->assertSet('form.starts_at', '')->assertSet('terms', [])->assertSet('seriesPreview', [])->assertSet('attachments', []);
    }

    public function test_message_and_change_are_scoped_entries_not_direct_order_edits(): void
    {
        $this->portal('messages')->call('create', 'message')->set('form.subject', 'Synthetic customer message')->set('form.body', 'Synthetic question about the published work.')->call('save')->assertHasNoErrors();
        $this->assertSame($this->customer->id, CustomerPortalMessage::where('subject', 'Synthetic customer message')->sole()->customer_id);
        $this->portal()->call('openDetails', 'order', $this->publication->id)->call('requestForOrder', 'cancel')->set('form.message', 'Synthetic cancellation request for review.')->call('save')->assertHasNoErrors();
        $request = CustomerPortalRequest::where('customer_id', $this->customer->id)->sole();
        $this->assertSame('cancel', $request->kind);
        $this->assertSame('confirmed', Order::findOrFail($this->publication->subject_id)->status->value);
        Mail::assertNothingSent();
    }

    public function test_contact_edit_preserves_existing_history_boundary(): void
    {
        $this->membership->update(['history_from' => '2027-01-01']);
        Livewire::actingAs($this->admin, 'web')->test(CustomerPortalManagement::class)->call('edit', 'contact', $this->membership->id)->assertSet('form.history_from', '2027-01-01')->set('form.confirmed', true)->call('save')->assertHasNoErrors();
        $this->assertSame('2027-01-01', $this->membership->fresh()->history_from->toDateString());
    }

    public function test_activation_needs_named_contact_and_queues_only_that_contact(): void
    {
        $this->setting->update(['enabled' => false]);
        $second = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Explicit Synthetic Recipient', 'email' => 'selected-portal@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $component = Livewire::actingAs($this->admin, 'web')->test(CustomerPortalManagement::class)->call('edit', 'access')->set('form.enabled', true)->set('form.confirmed', true);
        $component->call('save')->assertHasErrors('form.contact_id');
        $this->assertFalse($this->setting->fresh()->enabled);
        $component->set('form.contact_id', $second->id)->call('save')->assertHasNoErrors();
        $this->assertTrue($this->setting->fresh()->enabled);
        $invitation = CustomerPortalInvitation::where('customer_id', $this->customer->id)->sole();
        $this->assertSame($second->id, CustomerPortalMembership::findOrFail($invitation->membership_id)->contact_id);
        Mail::assertNothingSent();
    }

    public function test_series_preview_and_submit_keep_all_dates_in_one_request(): void
    {
        $component = $this->portal()->call('create')->set('form.title', 'Synthetic series')->set('form.role_name', 'Rangierbegleitung')->set('form.location_name', 'Synthetic UI Yard')->set('form.series_enabled', true)->set('form.recurrence.from_date', '2027-05-17')->set('form.recurrence.to_date', '2027-05-18')->set('form.recurrence.weekdays', [1, 2])->call('previewSeries')->assertCount('seriesPreview', 2);
        $component->call('save')->assertHasNoErrors()->assertSet('formOpen', false);
        $this->assertSame(2, CustomerPortalSubmission::where('customer_id', $this->customer->id)->sole()->items()->count());
    }

    public function test_incoming_file_needs_explicit_manager_review_and_public_detail_is_safe(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('synthetic-details.pdf', "%PDF-1.4\nSynthetic private test attachment\n%%EOF");
        $this->portal()->call('create')->set('form.title', 'Synthetic upload request')->set('form.role_name', 'Rangierbegleitung')->set('form.starts_at', '2027-05-20T06:00')->set('form.ends_at', '2027-05-20T14:00')->set('form.location_name', 'Synthetic UI Yard')->set('attachments', [$file])->call('save')->assertHasNoErrors();
        $submission = CustomerPortalSubmission::where('customer_id', $this->customer->id)->sole();
        $attachment = CustomerPortalAttachment::where('customer_id', $this->customer->id)->sole();
        $this->assertSame('quarantined', $attachment->status);
        $this->portal('requests')->call('openDetails', 'submission', $submission->id)->assertSee('synthetic-details.pdf')->assertDontSee('intake-quarantine')->assertDontSee($attachment->file_hash);
        $review = Livewire::actingAs($this->admin, 'web')->test(CustomerPortalManagement::class)->call('edit', 'review-attachment', $attachment->id)->call('save')->assertHasErrors('form.confirmed');
        $this->assertSame('quarantined', $attachment->fresh()->status);
        $review->set('form.confirmed', true)->set('form.note', 'Synthetic file content reviewed.')->call('save')->assertHasNoErrors();
        $this->assertSame('reviewed', $attachment->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_inbox_deep_link_selects_scoped_customer_and_requested_tab(): void
    {
        $other = Customer::create(['company_name' => 'Synthetic second management customer', 'is_active' => true]);
        Livewire::actingAs($this->admin, 'web')->withQueryParams(['customer' => (string) $other->id])
            ->test(CustomerPortalManagement::class, ['tab' => 'requests'])->assertSet('customerId', $other->id)->assertSet('tab', 'requests');
    }
}
