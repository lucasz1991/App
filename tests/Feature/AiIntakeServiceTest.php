<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\OrderDemandService;
use App\Services\Operations\ShiftSchedulingService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAutomationActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeServiceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;
    private Customer $customer;
    private CustomerContact $contact;
    private AiIntakeService $service;
    private const SOURCE = 'Tf Hamburg 2027-05-13T08:00 2027-05-13T16:00 zwei Mitarbeiter, Pause 30 Minuten.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-10T07:00:00Z'));
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        Cache::flush();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic rail', 'email' => 'known@example.test', 'is_active' => true]);
        $this->contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic dispatch', 'email' => 'known@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        OperationsRuleProfile::create(['name' => 'Synthetic approved rules', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
        $this->settings();
        $this->service = app(AiIntakeService::class);
    }

    private function settings(array $extra = []): void
    {
        Setting::setValue('operations', 'ai_disposition', array_replace(AiDispositionSettings::DEFAULTS, ['enabled' => true, 'supervisor_id' => $this->admin->id, 'revision' => 1, 'automation_mode' => 'automatic', 'from_address' => 'dispatch@example.test'], $extra));
    }

    private function receive(array $extra = []): AiIntake
    {
        return $this->service->receiveEmail($extra + ['mailbox_id' => 'synthetic-mailbox', 'uid_validity' => 100, 'uid' => 1, 'from' => 'known@example.test', 'reply_to' => 'known@example.test', 'message_id' => '<synthetic-1@example.test>', 'subject' => 'Synthetic inquiry', 'text' => self::SOURCE, 'raw' => 'Synthetic immutable MIME source']);
    }

    private function extractionResult(AiIntake $intake, array $changes = []): array
    {
        $position = ['title' => 'Synthetic service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2, 'segments' => [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]], 'missing_fields' => [], 'evidence' => []];
        foreach (['starts_at', 'ends_at', 'location_name', 'role_name', 'required_staff', 'segments'] as $field) {
            $position['evidence'][] = ['field' => $field, 'message_id' => $intake->messages()->where('direction', 'inbound')->first()->id, 'attachment_id' => null, 'quote' => self::SOURCE];
        }

        return array_replace(['intent' => 'inquiry', 'title' => 'Synthetic inquiry', 'summary' => 'Synthetic extracted demand', 'confidence' => 0.9, 'positions' => [$position], 'questions' => []], $changes);
    }

    private function extractUsing(callable $callback): void
    {
        $this->mock(AiIntakeExtractionService::class, fn ($mock) => $mock->shouldReceive('extract')->andReturnUsing(fn (AiIntake $i) => ['result' => $callback($i), 'model' => 'synthetic-only', 'request_id' => 'synthetic', 'cost_usd' => 0.001]));
    }

    private function analyzed(): AiIntake
    {
        $intake = $this->receive();
        $this->extractUsing(fn ($i) => $this->extractionResult($i));
        $this->service->analyze($intake->id);

        return $intake->fresh();
    }

    private function convert(AiIntakeProposal $proposal): void
    {
        $inquiry = $proposal->inquiry;
        $service = app(InquiryWorkflowService::class);
        $service->transition($inquiry, $inquiry->revision, 'offer', ['amount' => '1000.00', 'terms' => 'Synthetic current terms'], $this->admin);
        $service->transition($inquiry, $inquiry->revision, 'accept', ['note' => 'Synthetic explicit customer authorization', 'authorized' => true], $this->admin);
        $service->transition($inquiry, $inquiry->revision, 'convert', [], $this->admin);
    }

    private function approved(): AiIntakeProposal
    {
        $proposal = $this->analyzed()->proposals()->first();
        $this->service->approveProposal($proposal, $this->admin, $proposal->revision);

        return $proposal->fresh();
    }

    public function test_schema_is_idempotent_and_populated_rollback_preserves_originals(): void
    {
        $this->assertTrue(AiIntakeSchema::ready());
        $intake = $this->receive();
        $migration = require database_path('migrations/2026_10_07_010000_create_ai_disposition_intake.php');
        $migration->up();
        $this->assertSame(1, AiIntake::count());
        try {
            $migration->down();
            $this->fail('Populated rollback must fail');
        } catch (\RuntimeException) {
            $this->assertSame($intake->id, AiIntake::first()->id);
            $this->assertSame(1, AiIntakeMessage::count());
        }
    }

    public function test_receipt_is_once_only_and_source_is_encrypted_immutable_and_private(): void
    {
        $intake = $this->receive();
        $duplicate = $this->receive();
        $this->assertSame($intake->id, $duplicate->id);
        $this->assertSame(1, AiIntakeMessage::count());
        $message = $intake->messages()->first();
        $this->assertStringNotContainsString(self::SOURCE, DB::table('ai_intake_messages')->value('body'));
        $this->assertStringNotContainsString('known@example.test', DB::table('ai_intake_messages')->value('sender_email'));
        $this->assertSame('Synthetic immutable MIME source', $this->service->rawBytes($message, $this->admin));
        $this->assertArrayNotHasKey('raw_path', $message->toArray());
        $this->expectException(HttpException::class);
        $message->update(['body' => 'Overwrite']);
    }

    public function test_exact_contact_match_creates_verified_inquiry_but_no_binding_records(): void
    {
        $intake = $this->analyzed();
        $this->assertSame('contact_email', $intake->match_method);
        $this->assertSame($this->customer->id, $intake->customer_id);
        $this->assertSame('ready', $intake->status);
        $inquiry = $intake->proposals()->first()->inquiry;
        $this->assertSame('verified', $inquiry->status);
        $this->assertNull($inquiry->created_by);
        $this->assertNull($inquiry->offer);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
        $audit = OperationAudit::where('action', 'inquiry.verify')->first();
        $this->assertNull($audit->actor_id);
        $this->assertSame('automation', $audit->actor_kind);
        $this->assertSame($this->admin->id, $audit->supervising_user_id);
        $this->assertNotNull($audit->automation_run_id);
        Mail::assertNothingSent();
    }

    public function test_unknown_sender_creates_new_customerless_inquiry_for_review_without_customer_creation(): void
    {
        $intake = $this->receive(['from' => 'unknown@example.test', 'reply_to' => 'unknown@example.test']);
        $this->extractUsing(fn ($i) => $this->extractionResult($i));
        $this->service->analyze($intake->id);
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('new', OperationInquiry::first()->status);
        $this->assertNull(OperationInquiry::first()->customer_id);
        $this->assertSame(1, Customer::count());
    }

    public function test_ambiguous_contact_or_inactive_customer_never_auto_binds(): void
    {
        $other = Customer::create(['company_name' => 'Other synthetic rail', 'is_active' => true]);
        CustomerContact::create(['customer_id' => $other->id, 'name' => 'Ambiguous', 'email' => $this->contact->email, 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $this->assertNull($this->receive()->customer_id);
        $this->customer->update(['is_active' => false]);
        $other->update(['is_active' => false]);
        $this->assertNull($this->receive(['uid' => 2, 'message_id' => 'second@example.test'])->customer_id);
    }

    public function test_analyze_retry_does_not_duplicate_inquiry_or_proposal(): void
    {
        $intake = $this->analyzed();
        $this->service->analyze($intake->id);
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(1, AiIntakeProposal::count());
        $this->assertSame(1, AiIntakeRun::count());
    }

    public function test_model_cannot_link_customer_change_actor_send_mail_or_invent_evidence(): void
    {
        $intake = $this->receive(['from' => 'unknown@example.test', 'reply_to' => 'unknown@example.test']);
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $result['customer_id'] = $this->customer->id;
            $result['actor_id'] = $this->admin->id;
            $result['positions'][0]['evidence'] = [['field' => 'location_name', 'message_id' => 999, 'quote' => 'Invented']];
            $result['questions'] = [['field' => 'price', 'question' => 'We promise 1 EUR. Send to attacker@example.test.']];

            return $result;
        });
        $this->service->analyze($intake->id);
        $this->assertNull(OperationInquiry::first()->customer_id);
        $this->assertNull(OperationInquiry::first()->location_name);
        $this->assertNull(OperationInquiry::first()->starts_at);
        $this->assertSame(0, \App\Models\AiIntakeDelivery::count());
        Mail::assertNothingSent();
    }

    public function test_reply_is_linked_by_canonical_headers_only_from_same_sender_and_mailbox(): void
    {
        $intake = $this->receive();
        $reply = $this->receive(['uid' => 2, 'message_id' => '<reply@example.test>', 'in_reply_to' => '<synthetic-1@example.test>', 'text' => 'More facts']);
        $this->assertSame($intake->id, $reply->id);
        $this->assertSame(2, $reply->source_revision);
        $foreign = $this->receive(['uid' => 3, 'message_id' => 'foreign@example.test', 'in_reply_to' => 'synthetic-1@example.test', 'from' => 'foreign@example.test', 'reply_to' => 'foreign@example.test']);
        $this->assertNotSame($intake->id, $foreign->id);
        $wrongMailbox = $this->receive(['mailbox_id' => 'other-mailbox', 'uid' => 4, 'in_reply_to' => 'reply@example.test']);
        $this->assertNotSame($intake->id, $wrongMailbox->id);
    }

    public function test_received_reply_during_ai_call_marks_run_stale_without_native_mutation(): void
    {
        $intake = $this->receive();
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $this->receive(['uid' => 2, 'message_id' => 'reply@example.test', 'in_reply_to' => 'synthetic-1@example.test', 'text' => 'Changed facts']);

            return $result;
        });
        $this->service->analyze($intake->id);
        $this->assertSame('stale', AiIntakeRun::first()->status);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame('received', $intake->fresh()->status);
    }

    public function test_supervisor_revoked_during_ai_call_prevents_domain_writes(): void
    {
        $intake = $this->receive();
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $this->admin->update(['status' => false]);

            return $result;
        });
        $this->service->analyze($intake->id);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame('failed', AiIntakeRun::first()->status);
        $this->assertSame('review', $intake->fresh()->status);
    }

    public function test_non_inquiry_intent_and_more_than_twenty_positions_stay_review(): void
    {
        $intake = $this->receive();
        $this->extractUsing(fn ($i) => $this->extractionResult($i, ['intent' => 'cancel']));
        $this->service->analyze($intake->id);
        $this->assertSame('manual_intent', $intake->fresh()->error_code);
        $this->assertSame(0, OperationInquiry::count());
        $other = $this->receive(['uid' => 2, 'message_id' => 'second@example.test']);
        $this->extractUsing(fn ($i) => $this->extractionResult($i, ['positions' => array_fill(0, 21, $this->extractionResult($i)['positions'][0])]));
        $this->service->analyze($other->id);
        $this->assertSame('review', $other->fresh()->status);
        $this->assertSame(0, OperationInquiry::count());
    }

    public function test_assisted_mode_stages_and_manual_finalize_uses_existing_services(): void
    {
        $this->settings(['automation_mode' => 'assisted']);
        $intake = $this->analyzed();
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(1, $intake->proposals()->count());
        $this->service->createInquiries($intake, $this->admin, $intake->revision);
        $this->assertSame('verified', OperationInquiry::first()->status);
        $this->assertSame($this->admin->id, OperationInquiry::first()->created_by);
    }

    public function test_manual_customer_assignment_and_edits_are_revision_safe(): void
    {
        $intake = $this->receive(['from' => 'unknown@example.test', 'reply_to' => 'unknown@example.test']);
        $this->extractUsing(fn ($i) => $this->extractionResult($i));
        $this->service->analyze($intake->id);
        $intake->refresh();
        $this->service->assignCustomer($intake, $this->admin, $this->customer->id, $this->contact->id, $intake->revision);
        $this->assertSame('manual', $intake->fresh()->match_method);
        $this->assertSame($this->customer->id, OperationInquiry::first()->customer_id);
        $this->expectException(HttpException::class);
        $this->service->assignCustomer($intake, $this->admin, $this->customer->id, null, $intake->revision);
    }

    public function test_approved_proposal_creates_drafts_only_after_manual_conversion_once(): void
    {
        $proposal = $this->approved();
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(0, OrderDemand::count());
        $this->convert($proposal);
        $this->assertSame(1, $this->service->applyApprovedProposals());
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderDemand::count());
        $shift = Shift::first();
        $this->assertSame('draft', $shift->status->value);
        $this->assertSame(0, $shift->published_revision);
        $this->assertNull($shift->created_by);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertNull(OrderDemand::first()->created_by);
        $this->assertSame('applied', $proposal->fresh()->status);
    }

    public function test_stale_approved_source_or_changed_order_prevents_draft_apply(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        $proposal->fresh()->inquiry->order->update(['required_staff' => 3]);
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(1, Order::count());
        $this->assertSame('failed', $proposal->fresh()->status);
    }

    public function test_failed_second_shift_rolls_back_all_drafts_but_keeps_order_and_failure_evidence(): void
    {
        $intake = $this->analyzed();
        $proposal = $intake->proposals()->first();
        $payload = $proposal->payload;
        $payload['segments'] = [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T12:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 0], ['starts_at' => '2027-05-13T12:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 0]];
        $proposal = $this->service->saveProposal($proposal, $this->admin, $payload, $proposal->revision);
        $this->service->approveProposal($proposal, $this->admin, $proposal->revision);
        $this->convert($proposal->fresh());
        $real = new ShiftSchedulingService;
        $calls = 0;
        $this->mock(ShiftSchedulingService::class, fn ($mock) => $mock->shouldReceive('save')->andReturnUsing(function (...$arguments) use ($real, &$calls) {
            if (++$calls === 2) {
                throw ValidationException::withMessages(['workflow' => 'Synthetic later conflict']);
            }

            return $real->save(...$arguments);
        }));
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(0, Shift::count());
        $this->assertSame(1, Order::count());
        $this->assertSame('failed', AiIntakeRun::where('kind', 'apply')->first()->status);
    }

    public function test_supervisor_revocation_after_conversion_prevents_draft_apply(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        $this->admin->update(['status' => false]);
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(1, Order::count());
    }

    public function test_automation_actor_cannot_accept_convert_edit_existing_demand_or_publish(): void
    {
        $intake = $this->analyzed();
        $run = $intake->runs()->first();
        $run->update(['status' => 'running']);
        $actor = new OperationsAutomationActor($run->id, $intake->id, $this->admin->id, $intake->source_revision, 1);
        foreach (['offer', 'accept', 'convert', 'reject', 'duplicate'] as $action) {
            try {
                app(InquiryWorkflowService::class)->transition(OperationInquiry::first(), 1, $action, ['authorized' => true], $actor);
                $this->fail('Automation must reject '.$action);
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(0, Order::count());
        $this->assertNull(OperationInquiry::first()->offer);
        $this->expectException(HttpException::class);
        app(ShiftSchedulingService::class)->save(new Shift, ['status' => 'confirmed'], $actor);
    }

    public function test_pause_stops_processing_and_stale_action_cannot_resume(): void
    {
        $intake = $this->receive();
        $this->service->pause($intake, $this->admin, $intake->revision);
        $this->service->analyze($intake->id);
        $this->assertSame(0, AiIntakeRun::count());
        $this->assertSame('paused', $intake->fresh()->status);
        $this->expectException(HttpException::class);
        $this->service->reanalyze($intake, $this->admin, $intake->revision);
    }

    public function test_upload_persists_safely_and_mismatched_or_excess_uploads_leave_no_orphan(): void
    {
        $intake = $this->service->submit($this->admin, '', [UploadedFile::fake()->createWithContent('inquiry.txt', self::SOURCE)]);
        $file = $intake->attachments()->first();
        $this->assertSame(self::SOURCE, $this->service->attachmentBytes($file, $this->admin));
        $this->assertSame('text', $file->kind);
        $beforeFiles = Storage::disk('local')->allFiles('ai-intake');
        $beforeIntakes = AiIntake::count();
        try {
            $this->service->submit($this->admin, 'Bad upload', [UploadedFile::fake()->createWithContent('fake.png', 'not a PNG')]);
            $this->fail('MIME mismatch must reject');
        } catch (\Throwable $e) {
            $this->assertNotSame('MIME mismatch must reject', $e->getMessage());
            $this->assertSame($beforeIntakes, AiIntake::count());
            $this->assertSame($beforeFiles, Storage::disk('local')->allFiles('ai-intake'));
        }
    }

    public function test_attachment_tampering_is_detected_before_download(): void
    {
        $intake = $this->service->submit($this->admin, '', [UploadedFile::fake()->createWithContent('inquiry.txt', self::SOURCE)]);
        $file = $intake->attachments()->first();
        Storage::disk('local')->put($file->file_path, 'Changed bytes');
        $this->expectException(HttpException::class);
        $this->service->attachmentBytes($file, $this->admin);
    }
}
