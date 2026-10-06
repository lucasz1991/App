<?php

namespace Tests\Feature;

use App\Livewire\Operations\CustomerDocuments;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPortalAttachment;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalSetting;
use App\Models\CustomerPortalSubmission;
use App\Models\CustomerPortalSubmissionItem;
use App\Models\InquiryFollowUp;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\CustomerProfileData;
use App\Services\Operations\CustomerWorkflowService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerProfileDataTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        config(['customer_portal.delivery_enabled' => false]);
        Mail::fake();
        Bus::fake();
        $this->withoutVite();
        foreach (['operations.manage', 'operations.inquiries.manage', 'customers.portal.manage', 'customers.portal.publish', 'customers.portal.automation'] as $ability) {
            Gate::define($ability, fn ($actor) => in_array($ability, $this->permissions[$actor->id] ?? [], true));
        }
        $this->approver = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic dossier', 'email' => 'private-crm@example.test', 'notes' => 'PRIVATE CRM NOTE', 'is_active' => true]);
        $this->other = Customer::create(['company_name' => 'Other synthetic customer', 'email' => 'other-private@example.test', 'is_active' => true]);
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

    private function data(User $actor): array
    {
        return app(CustomerProfileData::class)->summary($actor, $this->customer->id);
    }

    private function order(array $extra = []): Order
    {
        return Order::create($extra + ['customer_id' => $this->customer->id, 'title' => 'Synthetic order', 'status' => 'confirmed', 'starts_at' => CarbonImmutable::parse('2027-05-12 06:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2027-05-12 14:00:00', 'UTC'), 'timezone' => 'Europe/Berlin']);
    }

    private function inquiry(array $extra = []): OperationInquiry
    {
        return OperationInquiry::create($extra + ['customer_id' => $this->customer->id, 'channel' => 'email', 'title' => 'Synthetic inquiry', 'original' => 'PRIVATE ORIGINAL', 'created_by' => $this->approver->id, 'updated_by' => $this->approver->id]);
    }

    private function member(bool $enabled = false): CustomerPortalMembership
    {
        $contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic portal contact', 'email' => 'portal-contact@example.test', 'roles' => ['dispatch'], 'is_active' => true, 'updated_by' => $this->approver->id]);
        $identity = CustomerPortalIdentity::create(['name' => 'Synthetic identity', 'email' => $contact->email, 'password' => 'Synthetic-only-7!', 'active' => true, 'email_verified_at' => now()]);
        CustomerPortalSetting::create(['customer_id' => $this->customer->id, 'enabled' => $enabled, 'modules' => ['orders', 'documents'], 'notifications' => [], 'automation_mode' => 'manual', 'updated_by' => $this->approver->id]);

        return CustomerPortalMembership::create(['customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'identity_id' => $identity->id, 'role' => 'reader', 'status' => 'active', 'activated_at' => now(), 'capabilities' => ['orders.view', 'documents.view'], 'location_ids' => [], 'updated_by' => $this->approver->id]);
    }

    public function test_crm_projection_uses_actual_order_states_and_limited_safe_records(): void
    {
        $actor = $this->actor(['operations.manage']);
        $first = $this->order();
        $this->order(['status' => 'completed']);
        $deleted = $this->order();
        $deleted->delete();
        $this->order(['customer_id' => $this->other->id]);
        $data = $this->data($actor);
        $metrics = collect($data['metrics'])->keyBy('key');
        $this->assertSame(1, $metrics['orders_open']['value']);
        $this->assertSame(2, $metrics['orders_total']['value']);
        $this->assertSame('PRIVATE CRM NOTE', $data['customer']->notes);
        $this->assertTrue($data['canEdit']);
        $this->assertNull($data['portal']);
        $this->assertCount(2, $data['recentOrders']);
        $row = $data['recentOrders']->firstWhere('id', $first->id);
        $this->assertSame('Bestätigt', $row['status_label']);
        $this->assertSame('12.05.2027 08:00 – 16:00', $row['time_label']);
        $this->assertStringContainsString('order='.$first->id, $row['detail_url']);
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('description', $row);
        $this->assertArrayNotHasKey('inquiries_open', $metrics->all());
    }

    public function test_inquiry_projection_excludes_completed_rejected_and_duplicate_work_from_open_count(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $active = $this->inquiry(['status' => 'accepted']);
        $this->inquiry(['status' => 'rejected']);
        $this->inquiry(['status' => 'duplicate', 'duplicate_of_id' => $active->id]);
        $this->inquiry(['status' => 'converted', 'order_id' => $this->order()->id]);
        $this->inquiry(['customer_id' => $this->other->id]);
        $data = $this->data($actor);
        $this->assertSame(['id', 'company_name'], array_keys($data['customer']->getAttributes()));
        $this->assertFalse($data['canEdit']);
        $this->assertSame(1, collect($data['metrics'])->firstWhere('key', 'inquiries_open')['value']);
        $this->assertCount(4, $data['recentInquiries']);
        $this->assertTrue($data['recentOrders']->isEmpty());
        $row = $data['recentInquiries']->firstWhere('id', $active->id);
        $this->assertStringContainsString('inquiry='.$active->id, $row['detail_url']);
        $this->assertStringContainsString('revision=1', $row['detail_url']);
        $this->assertArrayNotHasKey('original', $row);
        $this->assertArrayNotHasKey('contact_email', $row);
    }

    public function test_latest_offer_counts_keep_subject_permissions_and_exclude_converted_order_copies(): void
    {
        $order = $this->order();
        $inquiry = $this->inquiry();
        $copied = $this->order();
        foreach ([['Order', $order->id, 1, 'offered', []], ['Order', $order->id, 2, 'accepted', []], ['OperationInquiry', $inquiry->id, 1, 'offered', []], ['Order', $copied->id, 1, 'draft', ['origin_inquiry_id' => $inquiry->id]], ['Order', $this->order()->id, 1, 'draft', []]] as [$type, $id, $revision, $status, $snapshot]) {
            CommercialOfferRevision::create(['subject_type' => $type, 'subject_id' => $id, 'revision' => $revision, 'kind' => 'offer', 'status' => $status, 'snapshot' => $snapshot, 'total_cents' => 888888, 'created_by' => $this->approver->id]);
        }
        $this->assertSame(1, collect($this->data($this->actor(['operations.manage']))['metrics'])->firstWhere('key', 'offers_open')['value']);
        $this->assertSame(1, collect($this->data($this->actor(['operations.inquiries.manage']))['metrics'])->firstWhere('key', 'offers_open')['value']);
        $combined = $this->data($this->actor(['operations.manage', 'operations.inquiries.manage']));
        $this->assertSame(2, collect($combined['metrics'])->firstWhere('key', 'offers_open')['value']);
        $this->assertStringNotContainsString('888888', json_encode($combined));
    }

    public function test_follow_ups_are_due_open_customer_scoped_and_do_not_include_private_notes(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $inquiry = $this->inquiry();
        $other = $this->inquiry(['customer_id' => $this->other->id]);
        foreach ([[$inquiry->id, 'open', now()->subHour()], [$inquiry->id, 'open', now()->addDay()], [$inquiry->id, 'completed', now()->subDay()], [$other->id, 'open', now()->subHour()]] as [$id, $status, $due]) {
            // Match the UTC Carbon passed by CustomerWorkflowService, not an app-zone raw fixture.
            InquiryFollowUp::create(['operation_inquiry_id' => $id, 'title' => 'Synthetic callback', 'note' => 'PRIVATE FOLLOWUP NOTE', 'kind' => 'call', 'status' => $status, 'due_at' => CarbonImmutable::instance($due)->utc(), 'timezone' => 'Europe/Berlin', 'assignee_id' => $actor->id, 'updated_by' => $this->approver->id]);
        }
        $tasks = $this->data($actor)['followUps'];
        $this->assertCount(1, $tasks);
        $this->assertSame($actor->name, $tasks->first()['assignee']);
        $this->assertSame($inquiry->id, $tasks->first()['inquiry_id']);
        $this->assertSame('10.05.2027 08:00', $tasks->first()['due_label']);
        $this->assertStringContainsString('inquiry='.$inquiry->id, $tasks->first()['detail_url']);
        $this->assertArrayNotHasKey('note', $tasks->first());
        $this->assertTrue($this->data($this->actor(['operations.manage']))['followUps']->isEmpty());
    }

    public function test_portal_only_projection_is_explicit_and_scoped_without_crm_or_identity_secrets(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $this->member();
        $data = $this->data($actor);
        $this->assertSame(['id', 'company_name'], array_keys($data['customer']->getAttributes()));
        $this->assertFalse($data['portal']['enabled']);
        $this->assertSame(0, $data['portal']['usable_memberships']);
        $this->assertSame(['active' => 1], $data['portal']['memberships']);
        $this->assertSame(['manage'], $data['portal']['capabilities']);
        $this->assertSame(['history'], array_keys(app(CustomerProfileData::class)->availableViews($actor, $this->customer->id)));
        $encoded = json_encode($data);
        foreach (['private-crm', 'PRIVATE CRM', 'password', 'token_hash', 'two_factor', 'portal-contact@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
        $this->assertSame(0, DB::table('customer_portal_deliveries')->count());
    }

    public function test_usable_logins_require_enabled_customer_contact_and_matching_verified_identity(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $member = $this->member(true);
        $this->assertSame(1, $this->data($actor)['portal']['usable_memberships']);
        $member->contact()->update(['is_active' => false]);
        $this->assertSame(0, $this->data($actor)['portal']['usable_memberships']);
        $member->contact()->update(['is_active' => true]);
        $member->identity()->update(['email_verified_at' => null]);
        $this->assertSame(0, $this->data($actor)['portal']['usable_memberships']);
        $member->identity()->update(['email_verified_at' => now(), 'email' => 'mismatch@example.test']);
        $this->assertSame(0, $this->data($actor)['portal']['usable_memberships']);
        $member->identity()->update(['email' => 'portal-contact@example.test']);
        $this->customer->update(['is_active' => false]);
        $this->assertSame(0, $this->data($actor)['portal']['usable_memberships']);
    }

    public function test_document_metadata_requires_publish_grant_and_counts_only_latest_published_revision(): void
    {
        $actor = $this->actor(['customers.portal.manage', 'customers.portal.publish']);
        $this->grant($actor, ['customers.portal.manage']);
        $this->assertArrayNotHasKey('documents', app(CustomerProfileData::class)->availableViews($actor, $this->customer->id));
        DB::table('customer_portal_manager_grants')->where('user_id', $actor->id)->update(['abilities' => json_encode(['customers.portal.manage', 'customers.portal.publish'])]);
        foreach ([[1, 1, 'published'], [1, 2, 'withdrawn'], [2, 1, 'published'], [3, 1, 'quarantined']] as [$id, $revision, $status]) {
            CustomerPortalPublication::create(['customer_id' => $this->customer->id, 'subject_type' => 'document', 'subject_id' => $id, 'revision' => $revision, 'status' => $status, 'title' => 'Synthetic document', 'payload' => ['secret' => 'PRIVATE DOCUMENT CONTENT'], 'reviewed_at' => $status === 'published' ? now() : null]);
        }
        $data = $this->data($actor);
        $this->assertSame(1, collect($data['metrics'])->firstWhere('key', 'documents')['value']);
        $this->assertArrayHasKey('documents', app(CustomerProfileData::class)->availableViews($actor, $this->customer->id));
        $this->assertStringNotContainsString('PRIVATE DOCUMENT CONTENT', json_encode($data));
        $this->expectException(ModelNotFoundException::class);
        app(CustomerProfileData::class)->availableViews($actor, $this->other->id);
    }

    public function test_portal_inquiry_link_keeps_the_existing_submission_decision_path(): void
    {
        $actor = $this->actor(['operations.inquiries.manage', 'customers.portal.manage']);
        $member = $this->member();
        $inquiry = $this->inquiry(['channel' => 'portal']);
        $submission = CustomerPortalSubmission::create(['customer_id' => $this->customer->id, 'membership_id' => $member->id, 'identity_id' => $member->identity_id, 'uuid' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'payload' => ['positions' => []]]);
        CustomerPortalSubmissionItem::create(['submission_id' => $submission->id, 'customer_id' => $this->customer->id, 'inquiry_id' => $inquiry->id, 'position' => 1, 'payload' => []]);
        $this->assertNull($this->data($actor)['recentInquiries']->first()['detail_url']);
        $this->grant($actor, ['customers.portal.manage']);
        $url = $this->data($actor)['recentInquiries']->first()['detail_url'];
        $this->assertStringContainsString('section=portal', $url);
        $this->assertStringContainsString('source=submission', $url);
        $this->assertStringContainsString('record='.$submission->id, $url);
        $this->assertStringNotContainsString('inquiry=', $url);
    }

    public function test_missing_optional_schemas_keep_crm_order_inquiry_and_internal_communication_available(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach (['commercial_offer_revisions', 'customer_contacts', 'customer_conditions', 'customer_locations', 'inquiry_follow_ups', 'customer_portal_settings'] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage', 'customers.portal.manage', 'customers.portal.publish']);
        $views = app(CustomerProfileData::class)->availableViews($actor, $this->customer->id);
        $this->assertArrayHasKey('orders', $views);
        $this->assertArrayHasKey('inquiries', $views);
        $this->assertArrayHasKey('communication', $views);
        $this->assertArrayNotHasKey('offers', $views);
        $this->assertArrayNotHasKey('documents', $views);
        $data = $this->data($actor);
        $this->assertNull($data['portal']);
        $this->assertTrue($data['canEdit']);
        $this->assertTrue($data['followUps']->isEmpty());
    }

    public function test_portal_grant_and_actor_status_are_rechecked_on_each_projection(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $this->data($actor);
        DB::table('customer_portal_manager_grants')->where('user_id', $actor->id)->update(['active' => false]);
        try {
            $this->data($actor);
            $this->fail('Revoked customer scope must not remain usable.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Customer::class, $exception->getModel());
        }
        $actor = $this->actor(['operations.manage']);
        $actor->forceFill(['status' => false])->save();
        $this->expectException(HttpException::class);
        app(CustomerProfileData::class)->availableViews($actor);
    }

    public function test_portal_only_cannot_query_an_ungranted_customer(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $this->expectException(ModelNotFoundException::class);
        app(CustomerProfileData::class)->summary($actor, $this->other->id);
    }

    public function test_recent_projection_is_limited_to_five_and_reading_does_not_create_missing_settings(): void
    {
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        for ($index = 0; $index < 7; $index++) {
            $this->order();
            $this->inquiry();
        }
        $data = $this->data($actor);
        $this->assertCount(5, $data['recentOrders']);
        $this->assertCount(5, $data['recentInquiries']);
        $this->assertFalse($data['portal']['enabled']);
        $this->assertSame(0, DB::table('customer_portal_settings')->count());
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
        $this->assertSame(0, DB::table('customer_portal_deliveries')->count());
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_documents_list_projects_only_scoped_metadata_with_typed_original_download_routes(): void
    {
        $actor = $this->actor(['customers.portal.manage', 'customers.portal.publish']);
        $this->grant($actor, ['customers.portal.manage', 'customers.portal.publish']);
        $member = $this->member();
        $publication = CustomerPortalPublication::create(['customer_id' => $this->customer->id, 'subject_type' => 'document', 'subject_id' => 1, 'title' => 'Synthetic published document', 'status' => 'published', 'payload' => ['note' => 'PRIVATE DOCUMENT PAYLOAD'], 'file_path' => 'customer-portal/quarantine/private-path', 'file_hash' => str_repeat('a', 64), 'file_mime' => 'application/pdf', 'file_size' => 2048]);
        CustomerPortalPublication::create(['customer_id' => $this->customer->id, 'subject_type' => 'order', 'subject_id' => 1, 'title' => 'Hidden operational snapshot', 'payload' => []]);
        CustomerPortalPublication::create(['customer_id' => $this->other->id, 'subject_type' => 'document', 'subject_id' => 1, 'title' => 'OTHER CUSTOMER DOCUMENT', 'payload' => []]);
        $attachment = CustomerPortalAttachment::create(['customer_id' => $this->customer->id, 'identity_id' => $member->identity_id, 'membership_id' => $member->id, 'source_type' => 'submission', 'source_id' => 12, 'client_uuid' => (string) Str::uuid(), 'file_name' => 'Synthetic incoming attachment', 'file_mime' => 'image/png', 'file_size' => 1024, 'file_path' => 'customer-portal/intake-quarantine/PRIVATE_PATH', 'file_hash' => str_repeat('b', 64), 'status' => 'quarantined']);
        $component = Livewire::actingAs($actor)->test(CustomerDocuments::class, ['customerId' => $this->customer->id])
            ->assertSee('Synthetic published document')->assertSee('Synthetic incoming attachment')->assertSee('Prüfung offen')
            ->assertSee('10.05.2027 09:00')
            ->assertDontSee('Hidden operational snapshot')->assertDontSee('OTHER CUSTOMER DOCUMENT')->assertDontSee('PRIVATE DOCUMENT PAYLOAD')->assertDontSee('PRIVATE_PATH');
        $items = $component->viewData('items')->getCollection();
        $this->assertCount(2, $items);
        $this->assertCount(2, $items->pluck('id')->unique());
        $this->assertSame(route('customer-portal.manager.document', ['id' => $publication->id]), $items->firstWhere('source', 'publication')->download_url);
        $this->assertSame(route('customer-portal.manager.attachment', ['id' => $attachment->id]), $items->firstWhere('source', 'attachment')->download_url);
        foreach ($items as $item) {
            foreach (['payload', 'file_path', 'file_hash', 'review_note', 'identity_id', 'membership_id'] as $privateField) {
                $this->assertFalse(property_exists($item, $privateField));
            }
        }
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
        $this->assertSame(0, DB::table('customer_portal_deliveries')->count());
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_documents_search_status_and_named_pagination_remain_inside_customer_context(): void
    {
        $actor = $this->actor(['customers.portal.manage', 'customers.portal.publish']);
        $this->grant($actor, ['customers.portal.manage', 'customers.portal.publish']);
        for ($index = 1; $index <= 17; $index++) {
            CustomerPortalPublication::create(['customer_id' => $this->customer->id, 'subject_type' => 'document', 'subject_id' => $index, 'title' => 'Synthetic report '.$index, 'status' => $index === 1 ? 'withdrawn' : 'published', 'payload' => []]);
        }
        $component = Livewire::actingAs($actor)->test(CustomerDocuments::class, ['customerId' => $this->customer->id]);
        $this->assertSame(17, $component->viewData('items')->total());
        $this->assertCount(15, $component->viewData('items')->items());
        $component->call('nextPage', 'customerDocumentsPage')->assertSet('paginators.customerDocumentsPage', 2);
        $this->assertCount(2, $component->viewData('items')->items());
        $component->set('status', 'withdrawn')->assertSet('paginators.customerDocumentsPage', 1);
        $this->assertSame(1, $component->viewData('items')->total());
        $component->call('resetFilters')->set('search', 'nothing matches');
        $this->assertSame(0, $component->viewData('items')->total());
        $component->call('resetFilters')->set('kind', 'invoice');
        $this->assertSame(0, $component->viewData('items')->total());
        $component->set('search', str_repeat('a', 101))->assertStatus(422);
    }

    public function test_documents_access_requires_both_fresh_manage_and_publish_customer_grants(): void
    {
        $actor = $this->actor(['customers.portal.manage', 'customers.portal.publish']);
        $this->grant($actor, ['customers.portal.manage']);
        Livewire::actingAs($actor)->test(CustomerDocuments::class, ['customerId' => $this->customer->id])->assertForbidden();
        DB::table('customer_portal_manager_grants')->where('user_id', $actor->id)->update(['abilities' => json_encode(['customers.portal.manage', 'customers.portal.publish'])]);
        $component = Livewire::actingAs($actor)->test(CustomerDocuments::class, ['customerId' => $this->customer->id]);
        DB::table('customer_portal_manager_grants')->where('user_id', $actor->id)->update(['active' => false]);
        $component->set('search', 'document')->assertForbidden();
        Livewire::actingAs($actor)->test(CustomerDocuments::class, ['customerId' => $this->other->id])->assertForbidden();
    }

    public function test_follow_up_projection_matches_the_real_utc_workflow_in_summer_winter_and_across_midnight(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        foreach ([
            ['2027-05-10 07:00:00', '2027-05-10T08:00', '2027-05-10T10:00', '10.05.2027 08:00', '2027-05-10 06:00:00'],
            ['2027-05-10 22:30:00', '2027-05-11T00:00', '2027-05-11T01:00', '11.05.2027 00:00', '2027-05-10 22:00:00'],
            ['2027-01-10 23:30:00', '2027-01-11T00:00', '2027-01-11T01:00', '11.01.2027 00:00', '2027-01-10 23:00:00'],
        ] as [$clock, $past, $future, $label, $stored]) {
            $this->travelTo(CarbonImmutable::parse($clock, 'UTC'));
            $customer = Customer::create(['company_name' => 'Synthetic timezone '.$clock]);
            $inquiry = $this->inquiry(['customer_id' => $customer->id]);
            $service = app(CustomerWorkflowService::class);
            $due = $service->followUp($inquiry, ['title' => 'Due callback', 'kind' => 'call', 'due_at' => $past, 'timezone' => 'Europe/Berlin', 'assignee_id' => $actor->id], $actor);
            $service->followUp($inquiry, ['title' => 'Future callback', 'kind' => 'call', 'due_at' => $future, 'timezone' => 'Europe/Berlin'], $actor);
            $this->assertSame($stored, $due->getRawOriginal('due_at'));
            $tasks = app(CustomerProfileData::class)->summary($actor, $customer->id)['followUps'];
            $this->assertCount(1, $tasks);
            $this->assertSame($due->id, $tasks->first()['id']);
            $this->assertSame($label, $tasks->first()['due_label']);
            $this->assertSame('UTC', $tasks->first()['due_at']->timezoneName);
        }
    }

    public function test_base_only_portal_metadata_does_not_offer_an_unavailable_portal_metric_target(): void
    {
        $actor = $this->actor(['customers.portal.manage', 'customers.portal.publish']);
        $this->grant($actor, ['customers.portal.manage', 'customers.portal.publish']);
        $this->member();
        Schema::disableForeignKeyConstraints();
        try {
            Schema::drop('customer_portal_automation_profiles');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $this->assertTrue(CustomerPortalSchema::ready());
        $this->assertTrue(CustomerPortalWorkflowSchema::ready());
        $this->assertFalse(CustomerPortalIntakeSchema::ready());
        $data = $this->data($actor);
        $this->assertFalse($data['portal']['enabled']);
        $this->assertSame(0, $data['portal']['usable_memberships']);
        $this->assertFalse(collect($data['metrics'])->contains('view', 'portal'));
        $this->assertArrayHasKey('documents', app(CustomerProfileData::class)->availableViews($actor, $this->customer->id));
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
