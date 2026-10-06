<?php

namespace Tests\Feature;

use App\Livewire\Operations\CustomerCommunications;
use App\Livewire\Operations\CustomerHistory;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerInteraction;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalMessage;
use App\Models\CustomerPortalSubmission;
use App\Models\CustomerPortalSubmissionItem;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerProfileCommunicationTest extends TestCase
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
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php', '2026_10_06_210000_create_customer_interactions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->withoutVite();
        Mail::fake();
        Bus::fake();
        config(['customer_portal.delivery_enabled' => false]);
        foreach (['operations.manage', 'operations.inquiries.manage', 'customers.portal.manage', 'customers.portal.publish'] as $ability) {
            Gate::define($ability, fn ($user) => in_array($ability, $this->permissions[$user->id] ?? [], true));
        }
        $this->approver = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Communication Customer', 'is_active' => true]);
        $this->other = Customer::create(['company_name' => 'Other Private Customer', 'is_active' => true]);
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

    private function manual(Customer $customer, string $subject, string $body = 'Private communication body'): CustomerInteraction
    {
        return CustomerInteraction::create(['customer_id' => $customer->id, 'channel' => 'phone', 'direction' => 'inbound', 'occurred_at' => '2026-09-15 08:00:00', 'timezone' => 'Europe/Berlin', 'subject' => $subject, 'body' => $body, 'created_by' => $this->approver->id]);
    }

    private function message(Customer $customer, string $subject, string $body): CustomerPortalMessage
    {
        return CustomerPortalMessage::create(['customer_id' => $customer->id, 'actor_id' => $this->approver->id, 'client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', $body), 'visibility' => 'customer', 'subject' => $subject, 'body' => $body]);
    }

    private function inquiry(Customer $customer): OperationInquiry
    {
        return OperationInquiry::create(['customer_id' => $customer->id, 'channel' => 'manual', 'title' => 'Scoped Inquiry', 'original' => 'Reviewed request', 'created_by' => $this->approver->id, 'updated_by' => $this->approver->id]);
    }

    private function order(Customer $customer): Order
    {
        return Order::create(['customer_id' => $customer->id, 'title' => 'Scoped Order', 'starts_at' => '2026-09-16 08:00:00', 'ends_at' => '2026-09-16 16:00:00', 'timezone' => 'Europe/Berlin', 'created_by' => $this->approver->id]);
    }

    public function test_manual_list_is_customer_scoped_and_bodies_are_only_loaded_in_details(): void
    {
        $actor = $this->actor(['operations.manage']);
        $own = $this->manual($this->customer, 'Own phone contact');
        $this->manual($this->other, 'Other confidential contact');
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->assertSee('Own phone contact')->assertDontSee('Other confidential contact')->assertDontSee('Private communication body')
            ->call('openDetails', $own->id)->assertSee('Private communication body')->assertSee('Protokolleintrag');
    }

    public function test_manual_record_creation_is_encrypted_audited_utc_and_does_not_send(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->call('create')->set('form.subject', 'Telephone agreement')->set('form.body', 'Strictly private discussion')
            ->set('form.channel', 'phone')->set('form.direction', 'inbound')->set('form.occurred_at', '2026-09-15T10:00')->set('form.timezone', 'Europe/Berlin')
            ->call('save')->assertHasNoErrors()->assertSet('formOpen', false)->assertSee('Telephone agreement');
        $record = CustomerInteraction::firstOrFail();
        $this->assertSame($this->customer->id, $record->customer_id);
        $this->assertSame('2026-09-15 08:00:00', $record->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('Strictly private discussion', $record->body);
        $this->assertStringNotContainsString('Strictly private', DB::table('customer_interactions')->value('body'));
        $audit = OperationAudit::firstOrFail();
        $this->assertSame('customer.interaction.recorded', $audit->action);
        $this->assertSame(['customer_id' => $this->customer->id, 'channel' => 'phone', 'direction' => 'inbound'], $audit->data);
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('customer_portal_invitations')->count());
        $this->assertSame(0, DB::table('customer_portal_deliveries')->count());
    }

    public function test_unknown_channels_and_future_or_ambiguous_times_are_rejected(): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->call('create')->set('form.subject', 'Invalid entry')->set('form.body', 'Invalid entry content')->set('form.channel', 'smtp')
            ->call('save')->assertHasErrors(['form.channel'])
            ->set('form.channel', 'note')->set('form.occurred_at', '2026-10-25T02:30')->call('save')->assertHasErrors(['form.occurred_at'])
            ->set('form.occurred_at', now('Europe/Berlin')->addYear()->format('Y-m-d\TH:i'))->call('save')->assertHasErrors(['form.occurred_at']);
        $this->assertSame(0, CustomerInteraction::count());
    }

    public function test_customer_context_cannot_be_changed_by_a_client(): void
    {
        $actor = $this->actor(['operations.manage']);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])->set('customerId', $this->other->id);
    }

    public function test_foreign_customer_record_cannot_be_opened(): void
    {
        $actor = $this->actor(['operations.manage']);
        $foreign = $this->manual($this->other, 'Foreign contact');
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])->call('openDetails', $foreign->id);
    }

    public function test_foreign_contact_cannot_be_saved_in_manual_log(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $contact = CustomerContact::create(['customer_id' => $this->other->id, 'name' => 'Foreign contact', 'roles' => ['dispatch'], 'is_active' => true, 'updated_by' => $this->approver->id]);
        $component = Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->call('create')->set('form.subject', 'Cross customer')->set('form.body', 'Invalid linkage')->set('form.occurred_at', '2026-09-15T10:00')->set('form.contact_id', $contact->id);
        $this->expectException(ModelNotFoundException::class);
        $component->call('save');
    }

    public function test_inquiry_only_role_cannot_load_or_link_order_data(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $order = $this->order($this->customer);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->call('create')->assertDontSee($order->order_number)->set('form.subject', 'Denied link')->set('form.body', 'Denied linkage')
            ->set('form.occurred_at', '2026-09-15T10:00')->set('form.order_id', $order->id)->call('save')->assertForbidden();
        $this->assertSame(0, CustomerInteraction::count());
    }

    public function test_mismatched_inquiry_order_is_rejected_atomically(): void
    {
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage']);
        $first = $this->order($this->customer);
        $second = $this->order($this->customer);
        $inquiry = $this->inquiry($this->customer);
        $inquiry->update(['order_id' => $first->id]);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->call('create')->set('form.subject', 'Mismatch')->set('form.body', 'Mismatched references')
            ->set('form.occurred_at', '2026-09-15T10:00')->set('form.order_id', $second->id)->set('form.inquiry_id', $inquiry->id)->call('save')->assertStatus(422);
        $this->assertSame(0, CustomerInteraction::count());
        $this->assertSame(0, OperationAudit::count());
    }

    public function test_portal_only_actor_sees_only_scoped_real_messages_and_cannot_log(): void
    {
        $actor = $this->actor(['customers.portal.publish']);
        $this->grant($actor, ['customers.portal.publish']);
        $own = $this->message($this->customer, 'Customer portal question', 'Portal message text');
        $this->message($this->other, 'Other portal question', 'OTHER PORTAL SECRET');
        CustomerPortalMessage::create(['customer_id' => $this->customer->id, 'actor_id' => $this->approver->id, 'client_uuid' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'private'), 'visibility' => 'internal', 'subject' => 'INTERNAL PORTAL NOTE', 'body' => 'INTERNAL PORTAL BODY']);
        $this->manual($this->customer, 'PRIVATE CRM CONTACT', 'PRIVATE CRM BODY');
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->assertSet('view', 'portal')->assertSee('Customer portal question')->assertDontSee('Other portal question')->assertDontSee('PRIVATE CRM CONTACT')->assertDontSee('INTERNAL PORTAL NOTE')->assertDontSee('Kontakt protokollieren')
            ->call('openDetails', $own->id)->assertSee('Portal message text')->assertDontSee('PRIVATE CRM BODY')->call('create')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_revoked_portal_grant_and_disabled_actor_are_checked_on_actions(): void
    {
        $actor = $this->actor(['customers.portal.publish']);
        $this->grant($actor, ['customers.portal.publish']);
        $message = $this->message($this->customer, 'Granted message', 'Private portal message');
        $component = Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id]);
        DB::table('customer_portal_manager_grants')->update(['active' => false]);
        $component->call('openDetails', $message->id)->assertForbidden();
        $manual = $this->actor(['operations.manage']);
        $component = Livewire::actingAs($manual)->test(CustomerCommunications::class, ['customerId' => $this->customer->id]);
        $manual->update(['status' => false]);
        $component->call('create')->assertForbidden();
    }

    public function test_missing_log_migration_does_not_block_portal_messages_or_crm_surface(): void
    {
        Schema::drop('customer_interactions');
        $manual = $this->actor(['operations.manage']);
        Livewire::actingAs($manual)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->assertSee('Datenbankaktualisierung')->assertDontSee('Kontakt protokollieren');
        $portal = $this->actor(['customers.portal.publish']);
        $this->grant($portal, ['customers.portal.publish']);
        $this->message($this->customer, 'Still available', 'Portal message');
        Livewire::actingAs($portal)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])->assertSee('Still available');
    }

    public function test_manual_log_works_without_optional_contacts_and_portal_tables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('customer_interactions');
        Schema::drop('customer_contacts');
        Schema::enableForeignKeyConstraints();
        (require database_path('migrations/2026_10_06_210000_create_customer_interactions.php'))->up();
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage']);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->call('create')->assertDontSee('Ansprechpartner')->set('form.subject', 'Basic CRM log')->set('form.body', 'No optional contact module needed')
            ->set('form.occurred_at', '2026-09-15T10:00')->call('save')->assertHasNoErrors();
        $this->assertSame(1, CustomerInteraction::count());
    }

    public function test_named_pagination_and_subject_channel_filters_work(): void
    {
        $actor = $this->actor(['operations.manage']);
        for ($index = 1; $index <= 18; $index++) {
            $this->manual($this->customer, sprintf('Phone contact %02d', $index));
        }
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])
            ->assertSee('Phone contact 18')->assertDontSee('Phone contact 01')
            ->call('setPage', 2, 'customerCommunicationPage')->assertSee('Phone contact 01')
            ->set('search', '18')->assertSet('paginators.customerCommunicationPage', 1)->assertSee('Phone contact 18')->assertDontSee('Phone contact 17')
            ->set('channelFilter', 'email')->assertSee('Noch keine Kommunikation hinterlegt.');
    }

    public function test_history_only_loads_allowlisted_authorized_customer_lineage(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $own = $this->inquiry($this->customer);
        $foreign = $this->inquiry($this->other);
        foreach ([[$own, 'inquiry.followup.created'], [$foreign, 'inquiry.verify'], [$own, 'personnel_task.created']] as [$subject, $action]) {
            OperationAudit::create(['subject_type' => 'OperationInquiry', 'subject_id' => $subject->id, 'actor_id' => $this->approver->id, 'action' => $action, 'data' => ['private' => 'HISTORY SECRET', 'total_cents' => 999999], 'created_at' => now()]);
        }
        $order = $this->order($this->customer);
        OrderStatusHistory::create(['order_id' => $order->id, 'to_status' => 'confirmed', 'changed_by' => $this->approver->id, 'note' => 'ORDER SECRET']);
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertSee('Wiedervorlage angelegt')->assertSee('Wiedervorlage · Aufgabe')->assertDontSee('Bedarf bestätigt')
            ->assertDontSee('personnel_task')->assertDontSee('HISTORY SECRET')->assertDontSee('999999')->assertDontSee('ORDER SECRET')->assertDontSee('Auftragsstatus: Bestätigt');
        $manager = $this->actor(['operations.manage']);
        Livewire::actingAs($manager)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertSee('Auftragsstatus: Bestätigt')->assertDontSee('ORDER SECRET')->assertDontSee('Wiedervorlage angelegt');
    }

    public function test_portal_history_excludes_capacity_personnel_secrets_and_other_customers(): void
    {
        $actor = $this->actor(['customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        foreach ([[$this->customer, 'setting_disabled'], [$this->customer, 'capacity.approved'], [$this->other, 'setting_enabled']] as [$customer, $action]) {
            CustomerPortalAudit::create(['customer_id' => $customer->id, 'actor_id' => $this->approver->id, 'action' => $action, 'details' => ['token' => 'SECRET PORTAL TOKEN', 'user_id' => 7654321]]);
        }
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertSee('Portal deaktiviert')->assertDontSee('Portal aktiviert')->assertDontSee('capacity')->assertDontSee('7654321')->assertDontSee('SECRET PORTAL TOKEN')
            ->assertDontSee('Kunde angelegt')->set('source', 'operations')->assertForbidden();
    }

    public function test_migration_is_idempotent_and_refuses_populated_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_06_210000_create_customer_interactions.php');
        $migration->up();
        foreach (Schema::getIndexes('customer_interactions') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']));
        }
        $this->manual($this->customer, 'Preserved record');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_record_model_is_append_only(): void
    {
        $record = $this->manual($this->customer, 'Immutable contact');
        try {
            $record->update(['subject' => 'Rewritten contact']);
            $this->fail('An existing communication record must not be rewritten.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('Immutable contact', $record->fresh()->subject);
        try {
            $record->fresh()->delete();
            $this->fail('An existing communication record must not be deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, CustomerInteraction::count());
    }

    public function test_history_pagination_is_named_and_customer_scoped(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $own = $this->inquiry($this->customer);
        for ($index = 0; $index < 24; $index++) {
            OperationAudit::create(['subject_type' => 'OperationInquiry', 'subject_id' => $own->id, 'actor_id' => $this->approver->id, 'action' => $index === 0 ? 'inquiry.duplicate' : 'inquiry.verify', 'data' => [], 'created_at' => now()->subMinutes(30 - $index)]);
        }
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertDontSee('Dublette zugeordnet')->call('setPage', 2, 'customerHistoryPage')->assertSee('Dublette zugeordnet')
            ->set('source', 'operations')->assertSet('paginators.customerHistoryPage', 1);
    }

    public function test_missing_rights_cannot_read_communication_or_history(): void
    {
        $actor = $this->actor([]);
        Livewire::actingAs($actor)->test(CustomerCommunications::class, ['customerId' => $this->customer->id])->assertForbidden();
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])->assertForbidden();
    }

    public function test_master_audits_require_exact_customer_subject_and_master_permissions(): void
    {
        $inquiry = $this->inquiry($this->customer);
        foreach ([['Customer', $this->customer->id, 'customer.created'], ['Customer', $this->other->id, 'customer.updated'], ['OperationInquiry', $inquiry->id, 'customer.updated']] as [$type, $id, $action]) {
            OperationAudit::create(['subject_type' => $type, 'subject_id' => $id, 'actor_id' => $this->approver->id, 'action' => $action, 'data' => ['body' => 'PRIVATE MASTER AUDIT', 'email' => 'secret@example.test'], 'created_at' => now()]);
        }
        $master = $this->actor(['operations.manage']);
        Livewire::actingAs($master)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertSee('Kundenstammdaten angelegt')->assertDontSee('Kundenstammdaten geändert')->assertDontSee('PRIVATE MASTER AUDIT')->assertDontSee('secret@example.test');
        $inquiries = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($inquiries)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertDontSee('Kundenstammdaten angelegt')->assertDontSee('Kundenstammdaten geändert')->assertDontSee('PRIVATE MASTER AUDIT');
    }

    public function test_history_survives_absent_optional_contacts_and_uses_current_inquiry_revision(): void
    {
        $actor = $this->actor(['operations.inquiries.manage']);
        $inquiry = $this->inquiry($this->customer);
        $inquiry->update(['revision' => 4]);
        OperationAudit::create(['subject_type' => 'OperationInquiry', 'subject_id' => $inquiry->id, 'actor_id' => $this->approver->id, 'action' => 'inquiry.verify', 'revision' => 1, 'data' => [], 'created_at' => now()]);
        Schema::disableForeignKeyConstraints();
        Schema::drop('customer_contacts');
        Schema::enableForeignKeyConstraints();
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertSee('Bedarf bestätigt')->assertViewHas('events', function ($events) use ($inquiry) {
                parse_str(parse_url($events->first()->href, PHP_URL_QUERY), $query);

                return ($query['inquiry'] ?? null) === (string) $inquiry->id && ($query['revision'] ?? null) === '4' && ($query['detail'] ?? null) === 'history';
            });
    }

    public function test_portal_inquiry_history_links_only_to_scoped_portal_intake(): void
    {
        $inquiry = $this->inquiry($this->customer);
        $inquiry->update(['channel' => 'portal']);
        $contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Portal Contact', 'roles' => ['dispatch'], 'is_active' => true, 'updated_by' => $this->approver->id]);
        $identity = CustomerPortalIdentity::create(['name' => 'Portal Fixture', 'email' => 'portal-fixture@example.test', 'password' => 'not-used-fixture', 'active' => false]);
        $membership = CustomerPortalMembership::create(['customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'identity_id' => $identity->id, 'status' => 'pending', 'role' => 'reader', 'capabilities' => [], 'location_ids' => [], 'updated_by' => $this->approver->id]);
        $submission = CustomerPortalSubmission::create(['customer_id' => $this->customer->id, 'identity_id' => $identity->id, 'membership_id' => $membership->id, 'uuid' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'fixture'), 'payload' => [], 'decision' => [], 'revision' => 7]);
        CustomerPortalSubmissionItem::create(['customer_id' => $this->customer->id, 'submission_id' => $submission->id, 'inquiry_id' => $inquiry->id, 'position' => 0, 'payload' => []]);
        OperationAudit::create(['subject_type' => 'OperationInquiry', 'subject_id' => $inquiry->id, 'actor_id' => $this->approver->id, 'action' => 'inquiry.verify', 'data' => [], 'created_at' => now()]);
        $inquiries = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($inquiries)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertSee('Bedarf bestätigt')->assertViewHas('events', fn ($events) => $events->first()->href === null);
        $manager = $this->actor(['operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($manager, ['customers.portal.manage']);
        Livewire::actingAs($manager)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertViewHas('events', function ($events) use ($submission) {
                parse_str(parse_url($events->first()->href, PHP_URL_QUERY), $query);

                return ($query['section'] ?? null) === 'portal' && ($query['source'] ?? null) === 'submission' && ($query['record'] ?? null) === (string) $submission->id && ($query['revision'] ?? null) === '7' && ! isset($query['inquiry']);
            });
        Mail::assertNothingSent();
    }

    private function historyAuditAt(OperationInquiry $inquiry, CarbonImmutable $instant): void
    {
        OperationAudit::create(['subject_type' => 'OperationInquiry', 'subject_id' => $inquiry->id, 'actor_id' => $this->approver->id, 'action' => 'inquiry.verify', 'data' => [], 'created_at' => $instant->utc()]);
    }

    private function portalAuditAt(CarbonImmutable $instant): void
    {
        CustomerPortalAudit::create(['customer_id' => $this->customer->id, 'actor_id' => $this->approver->id, 'action' => 'setting_disabled', 'details' => [], 'created_at' => $instant->setTimezone(config('app.timezone')), 'updated_at' => $instant->setTimezone(config('app.timezone'))]);
    }

    public function test_history_sorts_all_sources_by_utc_in_summer_and_winter(): void
    {
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $inquiry = $this->inquiry($this->customer);
        $order = $this->order($this->customer);
        $summer = CarbonImmutable::parse('2026-07-15 10:00:00', 'UTC');
        $winter = CarbonImmutable::parse('2026-11-15 10:00:00', 'UTC');
        $this->customer->forceFill(['created_at' => $summer->setTimezone(config('app.timezone')), 'updated_at' => $winter->setTimezone(config('app.timezone'))])->saveQuietly();
        foreach ([$summer, $winter] as $instant) {
            // Legacy model intentionally excludes timestamps from mass assignment.
            OrderStatusHistory::forceCreate(['order_id' => $order->id, 'to_status' => 'confirmed', 'changed_by' => $this->approver->id, 'created_at' => $instant->addMinutes(10)->setTimezone(config('app.timezone')), 'updated_at' => $instant->addMinutes(10)->setTimezone(config('app.timezone'))]);
            $this->portalAuditAt($instant->addMinutes(20));
            $this->historyAuditAt($inquiry, $instant->addMinutes(30));
        }
        $expected = [];
        foreach ([$winter, $summer] as $instant) {
            foreach (['operations' => 30, 'portal' => 20, 'orders' => 10, 'metadata' => 0] as $source => $minutes) {
                $expected[] = [$source, $instant->addMinutes($minutes)->format('Y-m-d H:i:s')];
            }
        }
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertViewHas('events', function ($events) use ($expected) {
                $actual = $events->getCollection()->map(fn ($event) => [$event->source, $event->occurred_at->format('Y-m-d H:i:s')])->all();
                $this->assertSame($expected, $actual);

                return true;
            });
    }

    public static function chronologyTransitions(): array
    {
        return [
            'spring forward' => ['2026-03-29 00:30:00', '2026-03-29 01:30:00', '2026-03-29 01:00:00'],
            'fall back outside ambiguous hour' => ['2026-10-24 23:30:00', '2026-10-25 02:30:00', '2026-10-25 00:30:00'],
        ];
    }

    #[DataProvider('chronologyTransitions')]
    public function test_history_normalizes_actual_offsets_across_clock_changes(string $before, string $after, string $audit): void
    {
        $actor = $this->actor(['operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $inquiry = $this->inquiry($this->customer);
        $this->portalAuditAt(CarbonImmutable::parse($before, 'UTC'));
        $this->historyAuditAt($inquiry, CarbonImmutable::parse($audit, 'UTC'));
        $this->portalAuditAt(CarbonImmutable::parse($after, 'UTC'));
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertViewHas('events', function ($events) use ($before, $after, $audit) {
                $actual = $events->getCollection()->map(fn ($event) => [$event->source, $event->occurred_at->format('Y-m-d H:i:s')])->all();
                $this->assertSame([['portal', $after], ['operations', $audit], ['portal', $before]], $actual);

                return true;
            });
    }

    public function test_history_mixed_source_pagination_keeps_normalized_chronology(): void
    {
        $actor = $this->actor(['operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $inquiry = $this->inquiry($this->customer);
        $base = CarbonImmutable::parse('2026-07-15 10:00:00', 'UTC');
        for ($index = 0; $index < 11; $index++) {
            $this->historyAuditAt($inquiry, $base->addMinutes($index * 2));
            $this->portalAuditAt($base->addMinutes($index * 2 + 1));
        }
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertViewHas('events', function ($events) use ($base) {
                $this->assertSame(22, $events->total());
                $this->assertSame(20, $events->count());
                $this->assertSame('portal', $events->first()->source);
                $this->assertSame($base->addMinutes(21)->format('Y-m-d H:i:s'), $events->first()->occurred_at->format('Y-m-d H:i:s'));
                $this->assertSame('operations', $events->last()->source);
                $this->assertSame($base->addMinutes(2)->format('Y-m-d H:i:s'), $events->last()->occurred_at->format('Y-m-d H:i:s'));

                return true;
            })
            ->call('setPage', 2, 'customerHistoryPage')->assertViewHas('events', function ($events) use ($base) {
                $this->assertSame([['portal', $base->addMinute()->format('Y-m-d H:i:s')], ['operations', $base->format('Y-m-d H:i:s')]], $events->getCollection()->map(fn ($event) => [$event->source, $event->occurred_at->format('Y-m-d H:i:s')])->all());

                return true;
            });
    }

    public function test_legacy_repeated_hour_sort_matches_its_display_parser(): void
    {
        $actor = $this->actor(['operations.inquiries.manage', 'customers.portal.manage']);
        $this->grant($actor, ['customers.portal.manage']);
        $inquiry = $this->inquiry($this->customer);
        // The old local column cannot distinguish both folds; test its existing parser
        // interpretation rather than inventing the originally recorded offset.
        $interpreted = CarbonImmutable::parse('2026-10-25 02:30:00', config('app.timezone'))->utc();
        $this->portalAuditAt($interpreted);
        $this->historyAuditAt($inquiry, $interpreted->addMinutes(15));
        Livewire::actingAs($actor)->test(CustomerHistory::class, ['customerId' => $this->customer->id])
            ->assertViewHas('events', function ($events) use ($interpreted) {
                $this->assertSame(['operations', 'portal'], $events->getCollection()->pluck('source')->all());
                foreach ($events as $event) {
                    $this->assertSame($event->occurred_at->format('Y-m-d H:i:s'), $event->chronological_at);
                }
                $this->assertSame($interpreted->format('Y-m-d H:i:s'), $events->last()->occurred_at->format('Y-m-d H:i:s'));

                return true;
            });
    }
}
