<?php

namespace Tests\Feature;

use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAttachment;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalMessage;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSetting;
use App\Models\CustomerPortalSubmission;
use App\Models\OperationInquiry;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\CustomerPortal\CustomerPortalBusinessService;
use App\Services\CustomerPortal\CustomerPortalIntakeAttachmentService;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use App\Services\CustomerPortal\CustomerPortalSeriesService;
use App\Services\CustomerPortal\CustomerPortalWorkspaceService;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerPortalWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    private Customer $outside;

    private CustomerPortalIdentity $identity;

    private CustomerPortalMembership $member;

    private CustomerPortalSetting $setting;

    private Order $order;

    private CustomerLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Gate::define('customers.portal.publish', fn ($u) => $u->isAdmin());
        Gate::define('customers.portal.manage', fn ($u) => $u->isAdmin());
        Mail::fake();
        Storage::set('local', Storage::fake('customer-portal-workflows-'.getmypid()));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic own rail', 'is_active' => true, 'notes' => 'INTERNAL CUSTOMER SECRET']);
        $this->outside = Customer::create(['company_name' => 'Synthetic outside rail', 'is_active' => true]);
        $contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic contact', 'email' => 'portal-workflow@example.test', 'roles' => ['ordering', 'acceptance'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $this->identity = CustomerPortalIdentity::create(['name' => 'Synthetic identity', 'email' => $contact->email, 'password' => 'Synthetic-test-password-7!', 'active' => true, 'revision' => 1, 'email_verified_at' => now()]);
        $this->setting = CustomerPortalSetting::create(['customer_id' => $this->customer->id, 'enabled' => true, 'revision' => 1, 'modules' => CustomerPortalSetting::MODULES, 'notifications' => [], 'updated_by' => $this->admin->id]);
        $this->member = CustomerPortalMembership::create(['identity_id' => $this->identity->id, 'customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'role' => 'coordinator', 'status' => 'active', 'revision' => 1, 'capabilities' => CustomerPortalMembership::ROLES['coordinator'], 'location_ids' => [], 'activated_at' => now(), 'updated_by' => $this->admin->id]);
        $this->location = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic yard', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->admin->id]);
        $this->order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Synthetic service', 'status' => 'confirmed', 'priority' => 'normal', 'starts_at' => '2027-01-10 08:00:00', 'ends_at' => '2027-01-10 16:00:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 1, 'notes' => 'INTERNAL ORDER SECRET', 'requirements' => ['medical' => 'INTERNAL EMPLOYEE SECRET']]);
        $this->order->forceFill(['customer_portal_location_id' => $this->location->id])->save();
    }

    private function publish(): CustomerPortalPublication
    {
        return app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'order', $this->order->id, ['location_id' => $this->location->id]);
    }

    private function denied(callable $callback, int $status = 403): void
    {
        try {
            $callback();
            $this->fail('Expected an access or stale-state rejection.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function test_publications_are_explicit_and_allowlisted(): void
    {
        $service = app(CustomerPortalWorkspaceService::class);
        $this->assertSame([], $service->workspace($this->identity, $this->customer->id, 'orders')['rows']);
        $publication = $this->publish();
        $details = $service->detail($this->identity, $this->customer->id, 'order', $publication->id);
        $encoded = json_encode($details);
        $this->assertStringNotContainsString('INTERNAL', $encoded);
        $this->assertStringNotContainsString('created_by', $encoded);
        $this->assertSame($this->order->id, $details['source_id']);
        $this->assertCount(1, $service->workspace($this->identity, $this->customer->id, 'calendar')['rows']);
        Mail::assertNothingSent();
    }

    public function test_cross_customer_details_are_rejected(): void
    {
        $publication = $this->publish();
        $this->denied(fn () => app(CustomerPortalWorkspaceService::class)->detail($this->identity, $this->outside->id, 'order', $publication->id));
    }

    public function test_disabled_settings_block_cached_identity_and_downloads(): void
    {
        $publication = $this->publish();
        $this->setting->forceFill(['enabled' => false])->save();
        $this->denied(fn () => app(CustomerPortalWorkspaceService::class)->detail($this->identity, $this->customer->id, 'order', $publication->id));
    }

    public function test_withdrawal_never_restores_previous_publication(): void
    {
        $first = $this->publish();
        $second = $this->publish();
        $this->assertSame(2, $second->revision);
        app(CustomerPortalPublicationService::class)->withdraw($this->admin, $second->id, 2, 'Synthetic withdrawal');
        $this->assertSame(0, app(CustomerPortalPublicationService::class)->visible($this->identity, $this->customer->id)->count());
        $this->assertSame('published', $first->fresh()->status);
        $this->assertCount(3, CustomerPortalPublication::all());
    }

    public function test_history_boundary_is_source_based_not_only_new_publication_date(): void
    {
        $this->publish();
        $this->member->forceFill(['history_from' => '2028-01-01'])->save();
        $this->assertSame(0, app(CustomerPortalPublicationService::class)->visible($this->identity, $this->customer->id)->count());
    }

    public function test_historical_snapshot_does_not_reappear_when_live_source_dates_change(): void
    {
        $this->travelTo(now()->setDate(2028, 1, 2));
        $this->publish();
        $this->member->forceFill(['history_from' => '2028-01-01'])->save();
        $this->order->forceFill(['starts_at' => '2028-02-01 08:00:00', 'ends_at' => '2028-02-01 16:00:00'])->save();
        $this->assertSame(0, app(CustomerPortalPublicationService::class)->visible($this->identity, $this->customer->id)->count());
        $new = $this->publish();
        $this->assertSame(1, app(CustomerPortalPublicationService::class)->visible($this->identity, $this->customer->id)->count());
        $this->assertSame('2028-02-01', $new->service_ends_at->format('Y-m-d'));
    }

    public function test_location_scope_blocks_other_and_unmapped_sources(): void
    {
        $this->publish();
        $this->member->forceFill(['location_ids' => [$this->location->id]])->save();
        $this->assertSame(1, app(CustomerPortalPublicationService::class)->visible($this->identity, $this->customer->id)->count());
        $this->order->forceFill(['customer_portal_location_id' => null])->save();
        $this->assertSame(0, app(CustomerPortalPublicationService::class)->visible($this->identity, $this->customer->id)->count());
    }

    public function test_master_data_proposals_never_mutate_live_customer_or_email(): void
    {
        $uuid = (string) Str::uuid();
        $payload = ['kind' => 'master_data', 'title' => 'Synthetic correction', 'message' => 'Please review these details.', 'proposed_fields' => ['company_name' => 'Proposed name', 'email' => 'attacker@example.test', 'is_active' => false]];
        $record = app(CustomerPortalBusinessService::class)->submitRequest($this->identity, $this->customer->id, $uuid, $payload);
        $again = app(CustomerPortalBusinessService::class)->submitRequest($this->identity, $this->customer->id, $uuid, $payload);
        $this->assertSame($record->id, $again->id);
        $this->assertSame(['company_name' => 'Proposed name'], $record->payload['proposed_fields']);
        $this->assertSame('Synthetic own rail', $this->customer->fresh()->company_name);
        $this->assertSame(1, CustomerPortalRequest::count());
        $this->assertStringNotContainsString('Please review', DB::table('customer_portal_requests')->value('payload'));
        Mail::assertNothingSent();
    }

    public function test_request_uuid_payload_collision_is_retained_not_overwritten(): void
    {
        $uuid = (string) Str::uuid();
        $payload = ['kind' => 'change', 'order_id' => $this->order->id, 'title' => 'Synthetic adjustment', 'message' => 'Please adjust the service.'];
        $service = app(CustomerPortalBusinessService::class);
        $record = $service->submitRequest($this->identity, $this->customer->id, $uuid, $payload);
        $payload['message'] = 'Changed service request.';
        $this->denied(fn () => $service->submitRequest($this->identity, $this->customer->id, $uuid, $payload), 409);
        $this->assertSame('Please adjust the service.', $record->fresh()->payload['message']);
    }

    public function test_request_response_requires_exact_revision_and_does_not_cancel_order(): void
    {
        $service = app(CustomerPortalBusinessService::class);
        $record = $service->submitRequest($this->identity, $this->customer->id, (string) Str::uuid(), ['kind' => 'complaint', 'order_id' => $this->order->id, 'title' => 'Synthetic complaint', 'message' => 'Please investigate this service.']);
        $record = $service->reviewRequest($this->admin, $record->id, 1, 'reviewing', 'We are checking the reported details.');
        $this->denied(fn () => $service->reviewRequest($this->admin, $record->id, 1, 'resolved', 'Stale resolution.'), 409);
        $this->assertSame('confirmed', $this->order->fresh()->status->value);
        $this->assertSame(2, $record->revision);
    }

    public function test_only_explicit_customer_messages_are_visible_and_repeated_submission_is_once(): void
    {
        $service = app(CustomerPortalBusinessService::class);
        $uuid = (string) Str::uuid();
        $payload = ['order_id' => $this->order->id, 'subject' => 'Synthetic question', 'body' => 'Customer question'];
        $service->message($this->identity, $this->customer->id, $uuid, $payload);
        $service->message($this->identity, $this->customer->id, $uuid, $payload);
        $service->reply($this->admin, $this->customer->id, ['order_id' => $this->order->id, 'subject' => 'Private dispatch note', 'body' => 'INTERNAL SECRET', 'visibility' => 'internal']);
        $service->reply($this->admin, $this->customer->id, ['order_id' => $this->order->id, 'subject' => 'Released answer', 'body' => 'Customer answer', 'visibility' => 'customer']);
        $rows = app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id, 'messages')['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame(3, CustomerPortalMessage::count());
        $this->assertStringNotContainsString('INTERNAL SECRET', json_encode($rows));
        $this->assertStringNotContainsString('Customer question', DB::table('customer_portal_messages')->value('body'));
        Mail::assertNothingSent();
    }

    public function test_message_without_order_does_not_leak_to_other_location_contact(): void
    {
        app(CustomerPortalBusinessService::class)->reply($this->admin, $this->customer->id, ['subject' => 'General customer message', 'body' => 'Customer-wide confidential answer', 'visibility' => 'customer']);
        $this->member->forceFill(['location_ids' => [$this->location->id]])->save();
        $this->assertSame([], app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id, 'messages')['rows']);
    }

    public function test_document_needs_explicit_review_and_bytes_remain_private(): void
    {
        $service = app(CustomerPortalPublicationService::class);
        $file = UploadedFile::fake()->create('Synthetic.pdf', 20, 'application/pdf');
        $doc = $service->quarantineDocument($this->admin, $this->customer->id, $file, ['title' => 'Synthetic release', 'kind' => 'document', 'location_id' => $this->location->id]);
        $this->assertSame('quarantined', $doc->status);
        $this->assertSame(0, $service->visible($this->identity, $this->customer->id, 'documents.view')->count());
        $released = $service->reviewDocument($this->admin, $doc->id, 1, true, 'Manual synthetic safety check completed.');
        $details = app(CustomerPortalWorkspaceService::class)->detail($this->identity, $this->customer->id, 'document', $released->id);
        $this->assertTrue($details['downloadable']);
        $this->assertStringNotContainsString('file_path', json_encode($details));
        $this->assertStringNotContainsString('Manual synthetic', json_encode($details));
        $this->assertSame('no-store, private', $service->download($this->identity, $this->customer->id, $released->id)->headers->get('Cache-Control'));
        $this->assertSame('quarantined', $doc->fresh()->status);
    }

    public function test_document_hash_change_blocks_download(): void
    {
        $service = app(CustomerPortalPublicationService::class);
        $doc = $service->quarantineDocument($this->admin, $this->customer->id, UploadedFile::fake()->create('Synthetic.pdf', 20, 'application/pdf'), ['title' => 'Synthetic release', 'kind' => 'invoice']);
        $released = $service->reviewDocument($this->admin, $doc->id, 1, true, 'Manual review completed.');
        Storage::disk('local')->put($released->file_path, 'tampered');
        $this->denied(fn () => $service->download($this->identity, $this->customer->id, $released->id), 409);
    }

    public function test_document_deduplicates_only_within_customer(): void
    {
        $service = app(CustomerPortalPublicationService::class);
        $data = ['title' => 'Synthetic document', 'kind' => 'document'];
        $a = $service->quarantineDocument($this->admin, $this->customer->id, UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'), $data);
        $b = $service->quarantineDocument($this->admin, $this->customer->id, UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'), $data);
        $c = $service->quarantineDocument($this->admin, $this->outside->id, UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'), $data);
        $this->assertSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id);
    }

    public function test_report_escapes_spreadsheet_formulas_without_personnel_or_cost_fields(): void
    {
        $this->order->forceFill(['title' => '=1+1'])->save();
        $this->publish();
        $csv = app(CustomerPortalWorkspaceService::class)->report($this->identity, $this->customer->id);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString('INTERNAL', $csv);
        $this->assertStringNotContainsString('hourly_cents', $csv);
    }

    public function test_reader_overview_does_not_require_request_or_change_rights(): void
    {
        $this->member->forceFill(['role' => 'reader', 'capabilities' => CustomerPortalMembership::ROLES['reader']])->save();
        $this->setting->forceFill(['modules' => ['orders', 'documents']])->save();
        $this->publish();
        $view = app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id);
        $this->assertSame(1, $view['totals']['orders']);
        $this->assertSame(0, $view['totals']['requests']);
    }

    public function test_modules_are_enforced_on_details_not_only_menu(): void
    {
        $proof = OperationWorkflow::create(['kind' => 'proof', 'title' => 'Synthetic proof', 'order_id' => $this->order->id, 'status' => 'reviewed', 'revision' => 1, 'created_by' => $this->admin->id, 'payload' => ['review' => ['actor_id' => $this->admin->id], 'rows' => [['activity' => 'Service', 'quantity' => 1, 'unit' => 'h']]]]);
        $publication = app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'proof', $proof->id);
        $this->setting->forceFill(['modules' => ['orders']])->save();
        $this->denied(fn () => app(CustomerPortalWorkspaceService::class)->detail($this->identity, $this->customer->id, 'proof', $publication->id));
    }

    public function test_proof_decision_uses_real_contact_and_keeps_internal_review(): void
    {
        $proof = OperationWorkflow::create(['kind' => 'proof', 'title' => 'Synthetic proof', 'order_id' => $this->order->id, 'status' => 'reviewed', 'revision' => 1, 'created_by' => $this->admin->id, 'payload' => ['review' => ['note' => 'INTERNAL REVIEW', 'actor_id' => $this->admin->id], 'rows' => [['activity' => 'Service', 'quantity' => 1, 'unit' => 'h']]]]);
        $publication = app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'proof', $proof->id);
        $result = app(CustomerPortalBusinessService::class)->decideProof($this->identity, $this->customer->id, $publication->id, 1, 'accept', 'The customer accepts this exact proof.');
        $this->assertSame('accepted', $result->status);
        $this->assertSame('INTERNAL REVIEW', $result->payload['review']['note']);
        $this->assertSame('customer_portal', $result->payload['customer_decision']['source']);
        $revision = $result->revisions()->first();
        $this->assertNull($revision->actor_id);
        $this->assertSame($this->identity->id, $revision->customer_portal_identity_id);
        $this->assertSame($this->member->id, $revision->customer_portal_membership_id);
        $this->assertStringNotContainsString('INTERNAL REVIEW', json_encode(app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id, 'proofs')));
        Mail::assertNothingSent();
    }

    public function test_stale_proof_revision_cannot_be_accepted(): void
    {
        $proof = OperationWorkflow::create(['kind' => 'proof', 'title' => 'Synthetic proof', 'order_id' => $this->order->id, 'status' => 'reviewed', 'revision' => 1, 'created_by' => $this->admin->id, 'payload' => ['review' => ['actor_id' => $this->admin->id], 'rows' => []]]);
        $publication = app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'proof', $proof->id);
        $proof->forceFill(['revision' => 2])->save();
        $this->denied(fn () => app(CustomerPortalBusinessService::class)->decideProof($this->identity, $this->customer->id, $publication->id, 1, 'accept', 'Accepted old revision.'), 409);
        $this->assertSame('reviewed', $proof->fresh()->status);
    }

    public function test_schema_guard_rejects_partial_workflow_without_affecting_access_schema(): void
    {
        Schema::drop('customer_portal_messages');
        $this->assertFalse(CustomerPortalWorkflowSchema::ready());
        $this->denied(fn () => app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id), 503);
        $this->assertTrue(CustomerPortalSchema::ready());
    }

    public function test_series_preview_preserves_explicit_night_dates_and_exceptions(): void
    {
        $rows = app(CustomerPortalSeriesService::class)->preview($this->identity, $this->customer->id, ['from_date' => '2027-01-04', 'to_date' => '2027-01-12', 'weekdays' => [1, 2], 'start_time' => '22:00', 'end_time' => '06:00', 'overnight' => true, 'timezone' => 'Europe/Berlin', 'exceptions' => ['2027-01-05']]);
        $this->assertCount(3, $rows);
        $this->assertSame('2027-01-04T22:00', $rows[0]['starts_at']);
        $this->assertSame('2027-01-05T06:00', $rows[0]['ends_at']);
        $this->assertSame(0, OperationInquiry::count());
    }

    public function test_series_rejects_dst_nonexistent_time_and_excessive_count(): void
    {
        $service = app(CustomerPortalSeriesService::class);
        $base = ['from_date' => '2027-03-28', 'to_date' => '2027-03-28', 'weekdays' => [7], 'start_time' => '02:30', 'end_time' => '06:00', 'overnight' => false, 'timezone' => 'Europe/Berlin', 'exceptions' => []];
        foreach ([$base, array_replace($base, ['from_date' => '2027-01-01', 'to_date' => '2027-02-01', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_time' => '08:00', 'end_time' => '16:00'])] as $input) {
            try {
                $service->preview($this->identity, $this->customer->id, $input);
                $this->fail('Invalid series must not create requests.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(0, OperationInquiry::count());
    }

    public function test_contact_and_location_corrections_are_checked_proposals_only(): void
    {
        $service = app(CustomerPortalBusinessService::class);
        $payload = ['kind' => 'master_data', 'target_type' => 'contact', 'target_id' => $this->member->contact_id, 'title' => 'Synthetic contact correction', 'message' => 'Please check the new contact address.', 'proposed_fields' => ['email' => 'proposed@example.test', 'name' => 'Proposed contact']];
        $record = $service->submitRequest($this->identity, $this->customer->id, (string) Str::uuid(), $payload);
        $this->assertSame('proposed@example.test', $record->payload['proposed_fields']['email']);
        $this->assertSame('portal-workflow@example.test', CustomerContact::findOrFail($this->member->contact_id)->email);
        $payload['target_id'] = 99999;
        $this->denied(fn () => $service->submitRequest($this->identity, $this->customer->id, (string) Str::uuid(), $payload));
        $this->assertSame(1, CustomerPortalRequest::count());
    }

    public function test_cancel_and_disruption_requests_never_directly_change_disposition(): void
    {
        foreach (['cancel', 'disruption', 'replacement'] as $kind) {
            $record = app(CustomerPortalBusinessService::class)->submitRequest($this->identity, $this->customer->id, (string) Str::uuid(), ['kind' => $kind, 'order_id' => $this->order->id, 'title' => 'Synthetic operational request', 'message' => 'Please evaluate this operational request.']);
            $this->assertSame('submitted', $record->status);
        }
        $this->assertSame('confirmed', $this->order->fresh()->status->value);
        $this->assertSame(0, Shift::count());
    }

    public function test_order_template_never_reuses_old_dates_or_prices_or_booking_authority(): void
    {
        $publication = $this->publish();
        $template = app(CustomerPortalWorkspaceService::class)->template($this->identity, $this->customer->id, $publication->id);
        $this->assertSame($this->order->title, $template['title']);
        $this->assertSame('', $template['starts_at']);
        $this->assertNull($template['condition_id']);
        $this->assertSame('quote', $template['intent']);
        $this->assertFalse($template['accept_conditions']);
    }

    public function test_offer_publication_does_not_bypass_source_inquiry_location(): void
    {
        $inquiry = OperationInquiry::create(['customer_id' => $this->customer->id, 'title' => 'Synthetic outside location', 'timezone' => 'Europe/Berlin', 'channel' => 'portal', 'original' => 'Internal original', 'status' => 'offered', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);
        $offer = CommercialOfferRevision::create(['subject_type' => 'OperationInquiry', 'subject_id' => $inquiry->id, 'revision' => 1, 'state_version' => 2, 'kind' => 'offer', 'status' => 'offered', 'issued_at' => now(), 'snapshot' => ['positions' => [], 'terms' => 'Synthetic terms'], 'total_cents' => 100, 'created_by' => $this->admin->id]);
        app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'offer', $offer->id, ['location_id' => $this->location->id]);
        $this->member->forceFill(['location_ids' => [$this->location->id]])->save();
        $this->assertSame([], app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id, 'offers')['rows']);
    }

    private function attachmentRequest(): CustomerPortalRequest
    {
        return app(CustomerPortalBusinessService::class)->submitRequest($this->identity, $this->customer->id, (string) Str::uuid(), ['kind' => 'change', 'order_id' => $this->order->id, 'title' => 'Synthetic attachment request', 'message' => 'Please review the attached document.']);
    }

    public function test_intake_attachments_are_quarantined_and_idempotent_before_review(): void
    {
        $request = $this->attachmentRequest();
        $service = app(CustomerPortalIntakeAttachmentService::class);
        $uuid = (string) Str::uuid();
        $attachment = $service->upload($this->identity, $this->customer->id, 'request', $request->id, $uuid, UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf'));
        $again = $service->upload($this->identity, $this->customer->id, 'request', $request->id, $uuid, UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf'));
        $this->assertSame($attachment->id, $again->id);
        $this->assertSame(1, CustomerPortalAttachment::count());
        $this->assertFalse($service->files($this->identity, $this->customer->id, 'request', $request->id)[0]['downloadable']);
        $this->denied(fn () => $service->download($this->identity, $this->customer->id, $attachment->id), 409);
        $reviewed = $service->review($this->admin, $attachment->id, 1, true, 'Manual attachment check completed.');
        $this->assertSame('reviewed', $reviewed->status);
        $this->assertTrue($service->files($this->identity, $this->customer->id, 'request', $request->id)[0]['downloadable']);
        $this->assertSame('no-store, private', $service->download($this->identity, $this->customer->id, $attachment->id)->headers->get('Cache-Control'));
        $this->member->forceFill(['status' => 'suspended'])->save();
        $this->denied(fn () => $service->download($this->identity, $this->customer->id, $attachment->id));
        Mail::assertNothingSent();
    }

    public function test_intake_attachments_reject_hash_change_and_source_or_uuid_collision(): void
    {
        $request = $this->attachmentRequest();
        $service = app(CustomerPortalIntakeAttachmentService::class);
        $uuid = (string) Str::uuid();
        $attachment = $service->upload($this->identity, $this->customer->id, 'request', $request->id, $uuid, UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf'));
        $this->denied(fn () => $service->upload($this->identity, $this->customer->id, 'request', $request->id, $uuid, UploadedFile::fake()->createWithContent('Other.pdf', "%PDF-1.4\nSynthetic different bytes\n%%EOF")), 409);
        $service->review($this->admin, $attachment->id, 1, true, 'Manual review completed.');
        Storage::disk('local')->put($attachment->file_path, 'changed bytes');
        $this->denied(fn () => $service->download($this->identity, $this->customer->id, $attachment->id), 409);
    }

    public function test_intake_attachments_never_cross_customer_boundary(): void
    {
        $request = $this->attachmentRequest();
        $this->denied(fn () => app(CustomerPortalIntakeAttachmentService::class)->upload($this->identity, $this->outside->id, 'request', $request->id, (string) Str::uuid(), UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf')));
        $this->assertSame(0, CustomerPortalAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('customer-portal/intake-quarantine'));
    }

    public function test_rollback_cleanup_does_not_delete_persisted_files(): void
    {
        $request = $this->attachmentRequest();
        $service = app(CustomerPortalIntakeAttachmentService::class);
        $attachment = $service->upload($this->identity, $this->customer->id, 'request', $request->id, (string) Str::uuid(), UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf'));
        $path = 'customer-portal/intake-quarantine/'.$this->customer->id.'/'.Str::uuid().'/'.str_repeat('a', 64);
        Storage::disk('local')->put($path, 'orphaned synthetic bytes');
        $service->discardRolledBack($this->customer->id, [$path, $attachment->file_path]);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($attachment->file_path);
    }

    public function test_attachment_decisions_require_exact_manifest_and_current_review_authority(): void
    {
        $request = $this->attachmentRequest();
        $service = app(CustomerPortalIntakeAttachmentService::class);
        $attachment = $service->upload($this->identity, $this->customer->id, 'request', $request->id, (string) Str::uuid(), UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf'));
        $manifest = [['hash' => $attachment->file_hash, 'size' => $attachment->file_size]];
        $this->assertSame(['attachments_unreviewed'], $service->decisionIssues($this->customer->id, 'request', $request->id, $manifest));
        $service->review($this->admin, $attachment->id, 1, true, 'Completed synthetic safety review.');
        $this->assertSame([], $service->decisionIssues($this->customer->id, 'request', $request->id, $manifest));
        $this->assertSame(['attachments_manifest_changed'], $service->decisionIssues($this->customer->id, 'request', $request->id, []));
        $this->admin->forceFill(['status' => false])->save();
        $this->assertSame(['attachments_review_invalid'], $service->decisionIssues($this->customer->id, 'request', $request->id, $manifest));
    }

    public function test_new_attachments_cannot_silently_change_an_accepted_submission(): void
    {
        $submission = CustomerPortalSubmission::create(['customer_id' => $this->customer->id, 'identity_id' => $this->identity->id, 'membership_id' => $this->member->id, 'uuid' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'payload' => ['positions' => []], 'status' => 'accepted']);
        $this->denied(fn () => app(CustomerPortalIntakeAttachmentService::class)->upload($this->identity, $this->customer->id, 'submission', $submission->id, (string) Str::uuid(), UploadedFile::fake()->create('Synthetic.pdf', 10, 'application/pdf')), 409);
        $this->assertSame(0, CustomerPortalAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('customer-portal/intake-quarantine'));
    }

    public function test_proof_module_can_be_viewed_without_enabling_orders_module(): void
    {
        $proof = OperationWorkflow::create(['kind' => 'proof', 'title' => 'Synthetic proof', 'order_id' => $this->order->id, 'status' => 'reviewed', 'revision' => 1, 'created_by' => $this->admin->id, 'payload' => ['review' => ['actor_id' => $this->admin->id], 'rows' => [['activity' => 'Service', 'quantity' => 1, 'unit' => 'h']]]]);
        $publication = app(CustomerPortalPublicationService::class)->publish($this->admin, $this->customer->id, 'proof', $proof->id);
        $this->setting->forceFill(['modules' => ['proofs']])->save();
        $this->assertCount(1, app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id, 'proofs')['rows']);
        $this->assertSame($proof->id, app(CustomerPortalWorkspaceService::class)->detail($this->identity, $this->customer->id, 'proof', $publication->id)['source_id']);
        $this->assertSame(1, app(CustomerPortalWorkspaceService::class)->workspace($this->identity, $this->customer->id)['totals']['proofs']);
    }

    public function test_publication_instants_are_persisted_as_utc_independently_of_app_timezone(): void
    {
        $publication = $this->publish();
        $this->assertSame('Europe/Berlin', config('app.timezone'));
        $this->assertSame('UTC', $publication->fresh()->service_starts_at->timezoneName);
        $this->assertSame('07:00', $publication->fresh()->service_starts_at->format('H:i'));
        $this->assertSame('2027-01-10 07:00:00', DB::table('customer_portal_publications')->where('id', $publication->id)->value('service_starts_at'));
        $this->assertSame('UTC', $publication->fresh()->published_at->timezoneName);
        $this->assertSame(now()->utc()->format('Y-m-d H:i'), $publication->fresh()->published_at->format('Y-m-d H:i'));
    }

    private function submissionFixture(int $positions = 1): CustomerPortalSubmission
    {
        $submission = CustomerPortalSubmission::create(['customer_id' => $this->customer->id, 'identity_id' => $this->identity->id, 'membership_id' => $this->member->id, 'uuid' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'payload' => ['reference' => 'CUSTOMER-REF', 'intent' => 'quote', 'note' => 'Customer request note'], 'status' => 'rejected', 'revision' => 2, 'decision' => ['message' => 'Die angefragte Leistung ist nicht angeboten.', 'actor_id' => 99999, 'profile_id' => 88888, 'profile_revision' => 12, 'internal_reason' => 'INTERNAL DECISION SECRET']]);
        for ($position = 0; $position < $positions; $position++) {
            $inquiry = OperationInquiry::create(['customer_id' => $this->customer->id, 'title' => 'Synthetic position '.$position, 'timezone' => 'Europe/Berlin', 'channel' => 'portal', 'original' => 'INTERNAL ORIGINAL', 'status' => 'rejected', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'customer_portal_location_id' => $this->location->id]);
            $submission->items()->create(['customer_id' => $this->customer->id, 'inquiry_id' => $inquiry->id, 'position' => $position, 'location_id' => $this->location->id, 'payload' => ['title' => $inquiry->title, 'starts_at' => '2027-01-10T08:00', 'ends_at' => '2027-01-10T16:00', 'timezone' => 'Europe/Berlin', 'location_id' => $this->location->id, 'location_name' => $this->location->name, 'role_name' => 'Tf', 'required_staff' => 1, 'internal_costs' => 'INTERNAL COST SECRET']]);
        }

        return $submission;
    }

    public function test_submission_lists_one_series_and_exposes_only_public_decision_details(): void
    {
        $submission = $this->submissionFixture(2);
        $service = app(CustomerPortalWorkspaceService::class);
        $rows = $service->workspace($this->identity, $this->customer->id, 'requests')['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('submission', $rows[0]['subject_type']);
        $this->assertSame(2, $rows[0]['position_count']);
        $this->assertSame(1, $service->workspace($this->identity, $this->customer->id)['totals']['requests']);
        $details = $service->detail($this->identity, $this->customer->id, 'submission', $submission->id);
        $this->assertSame('Die angefragte Leistung ist nicht angeboten.', $details['message']);
        $this->assertCount(2, $details['snapshot']['positions']);
        $this->assertStringNotContainsString('INTERNAL', json_encode($details));
        $this->assertStringNotContainsString('profile_id', json_encode($details));
        $this->assertStringNotContainsString('actor_id', json_encode($details));
    }

    public function test_submission_scope_hides_whole_bundle_if_one_location_is_not_allowed(): void
    {
        $submission = $this->submissionFixture(2);
        $this->member->forceFill(['location_ids' => [$this->location->id]])->save();
        $item = $submission->items()->latest('id')->first();
        OperationInquiry::findOrFail($item->inquiry_id)->forceFill(['customer_portal_location_id' => null])->save();
        $service = app(CustomerPortalWorkspaceService::class);
        $this->assertSame([], $service->workspace($this->identity, $this->customer->id, 'requests')['rows']);
        $this->assertSame(0, $service->workspace($this->identity, $this->customer->id)['totals']['requests']);
        $this->denied(fn () => $service->detail($this->identity, $this->customer->id, 'submission', $submission->id), 404);
    }

    public function test_submission_details_and_attachments_cannot_cross_customer_or_history(): void
    {
        $submission = $this->submissionFixture();
        $service = app(CustomerPortalWorkspaceService::class);
        $this->denied(fn () => $service->detail($this->identity, $this->outside->id, 'submission', $submission->id));
        $this->member->forceFill(['history_from' => now()->addYear()->toDateString()])->save();
        $this->assertSame([], $service->workspace($this->identity, $this->customer->id, 'requests')['rows']);
        $this->denied(fn () => $service->detail($this->identity, $this->customer->id, 'submission', $submission->id), 404);
    }
}
