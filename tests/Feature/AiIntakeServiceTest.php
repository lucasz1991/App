<?php

namespace Tests\Feature;

use App\Jobs\ApplyApprovedAiIntakes;
use App\Jobs\ProcessAiIntake;
use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeDelivery;
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
use App\Models\QualificationType;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Ai\AssistantSpeechRouter;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Operations\AiDispositionClient;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\OrderDemandService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\UnifiedOperationsInboxService;
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
        // Concurrent suites must not erase each other's immutable source archives.
        Storage::set('local', Storage::fake('ai-intake-core-'.getmypid()));
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
        $position = ['title' => 'Synthetic service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2, 'segments' => [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]], 'qualification_requirements' => [], 'missing_fields' => [], 'evidence' => []];
        foreach (['starts_at', 'ends_at', 'location_name', 'role_name', 'required_staff', 'segments'] as $field) {
            $position['evidence'][] = ['field' => $field, 'message_id' => $intake->messages()->where('direction', 'inbound')->first()->id, 'attachment_id' => null, 'quote' => self::SOURCE];
        }

        return array_replace(['intent' => 'inquiry', 'classification' => 'new_inquiry', 'extraction_complete' => true, 'overflow' => false, 'title' => 'Synthetic inquiry', 'summary' => 'Synthetic extracted demand', 'confidence' => 0.9, 'positions' => [$position], 'questions' => []], $changes);
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
        $this->assertSame(0, AiIntakeDelivery::count());
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
        $this->assertNotContains('customer_id', $intake->fresh()->missing_fields);
        $this->assertContains('native_review', $intake->fresh()->missing_fields);
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
        $this->assertSame('review', $proposal->intake->fresh()->status);
        $this->assertSame('confirmed', Order::first()->status->value);
        $this->expectException(HttpException::class);
        $this->service->retryApprovedProposal($proposal->fresh(), $this->admin, $proposal->fresh()->revision);
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
        $this->assertSame('confirmed', Order::first()->status->value);
        $this->assertSame('failed', AiIntakeRun::where('kind', 'apply')->first()->status);
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('proposal_application_review', $intake->fresh()->error_code);
        $this->assertNotNull(app(UnifiedOperationsInboxService::class)->items($this->admin)->firstWhere('id', 'ai-intake-'.$intake->id));
        $this->app->instance(ShiftSchedulingService::class, $real);
        Queue::fake();
        $failed = $proposal->fresh();
        $this->service->retryApprovedProposal($failed, $this->admin, $failed->revision);
        $this->assertSame('approved', $failed->fresh()->status);
        $this->assertSame('ready', $intake->fresh()->status);
        $this->assertSame($this->admin->id, OperationAudit::where('action', 'ai.proposal.retry_approved')->sole()->actor_id);
        Queue::assertPushed(ApplyApprovedAiIntakes::class, fn ($job) => $job->intakeId === $intake->id);
        $this->assertSame(1, $this->service->applyApprovedProposals());
        $this->assertSame(2, OrderDemand::count());
        $this->assertSame(2, Shift::count());
        $this->assertSame(1, Order::count());
        $this->assertSame('confirmed', Order::first()->status->value);
    }

    public function test_application_failure_does_not_overwrite_pause_or_an_approved_proposal(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        AiIntakeRun::created(function (AiIntakeRun $run) use ($proposal): void {
            if ($run->kind === 'apply') {
                $current = $proposal->intake->fresh();
                $this->service->pause($current, $this->admin, $current->revision);
            }
        });
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame('paused', $proposal->intake->fresh()->status);
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame('confirmed', Order::first()->status->value);
    }

    public function test_existing_personal_order_planning_is_preserved_and_blocks_automatic_apply_or_retry(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        $order = $proposal->fresh()->inquiry->order;
        $demand = app(OrderDemandService::class)->save($order->id, null, null, $proposal->payload['demand'], $this->admin);
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(1, OrderDemand::count());
        $this->assertSame($this->admin->id, $demand->fresh()->created_by);
        $this->assertSame(0, Shift::count());
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertSame('review', $proposal->intake->fresh()->status);
        $this->expectException(HttpException::class);
        $failed = $proposal->fresh();
        $this->service->retryApprovedProposal($failed, $this->admin, $failed->revision);
    }

    public function test_application_failure_does_not_overwrite_a_new_reply_or_its_stale_proposal(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        AiIntakeRun::created(function (AiIntakeRun $run): void {
            if ($run->kind === 'apply') {
                $this->receive(['uid' => 2, 'message_id' => 'later-reply@example.test', 'in_reply_to' => 'synthetic-1@example.test', 'text' => 'A new reply requires review.']);
            }
        });
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame('received', $proposal->intake->fresh()->status);
        $this->assertSame(2, $proposal->intake->fresh()->source_revision);
        $this->assertSame('stale', $proposal->fresh()->status);
        $this->assertSame('source_changed', $proposal->fresh()->error_code);
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame('confirmed', Order::first()->status->value);
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
        $current = $intake->fresh();
        $this->service->assignCustomer($current, $this->admin, $this->customer->id, $this->contact->id, $current->revision);
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

    public function test_manual_source_uses_explicit_active_customer_metadata_and_disabled_queue_preserves_source(): void
    {
        $this->settings(['enabled' => false]);
        $intake = $this->service->submit($this->admin, self::SOURCE, [], ['customer_id' => $this->customer->id, 'contact_email' => 'known@example.test', 'timezone' => 'Europe/London']);
        $this->assertSame('Manueller Eingang', $intake->title);
        $this->assertSame($this->customer->id, $intake->customer_id);
        $this->assertSame('manual', $intake->match_method);
        $this->assertSame('review', $intake->status);
        $this->assertSame('automation_disabled', $intake->error_code);
        $this->assertSame('Europe/London', $intake->messages()->first()->metadata['timezone']);
        Queue::assertNothingPushed();
    }

    public function test_stricter_attachment_configuration_is_enforced_and_no_partial_manual_intake_remains(): void
    {
        $this->settings(['max_attachment_count' => 1, 'max_total_kilobytes' => 1]);
        try {
            $this->service->submit($this->admin, self::SOURCE, [UploadedFile::fake()->createWithContent('one.txt', 'first'), UploadedFile::fake()->createWithContent('two.txt', 'second')]);
            $this->fail('Configured count must reject');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            $this->service->submit($this->admin, self::SOURCE, [UploadedFile::fake()->createWithContent('large.txt', str_repeat('x', 1025))]);
            $this->fail('Configured aggregate bytes must reject');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, AiIntake::count());
        $this->assertSame([], Storage::disk('local')->allFiles('ai-intake'));
    }

    public function test_oversized_and_malformed_email_are_retained_review_receipts_without_poisoning_later_mail(): void
    {
        $long = str_repeat('x', 20001);
        $large = $this->receive(['text' => $long]);
        $this->assertSame('review', $large->status);
        $this->assertSame('mailbox_text_limit', $large->error_code);
        $this->assertSame($long, $large->messages()->first()->body);
        $bad = $this->receive(['uid' => 2, 'message_id' => 'bad@example.test', 'attachments' => [UploadedFile::fake()->createWithContent('fake.png', 'not a PNG')]]);
        $this->assertSame('attachments_review', $bad->error_code);
        $this->assertNotNull($bad->messages()->first()->raw_path);
        $good = $this->receive(['uid' => 3, 'message_id' => 'good@example.test']);
        $this->assertSame('received', $good->status);
        $this->assertSame(3, AiIntake::count());
        $this->assertSame(2, count(Storage::disk('local')->allFiles('ai-intake')) - 1);
        Queue::assertPushed(ProcessAiIntake::class, 1);
    }

    public function test_incomplete_archive_marker_is_preserved_without_provider_processing(): void
    {
        $intake = $this->receive(['source_error' => 'mailbox_message_size', 'raw_archive_complete' => false, 'declared_size' => 40 * 1024 * 1024, 'raw' => 'Bounded original headers only']);
        $this->service->analyze($intake->id);
        $this->assertSame('review', $intake->status);
        $this->assertFalse($intake->messages()->first()->metadata['raw_archive_complete']);
        $this->assertSame(40 * 1024 * 1024, $intake->messages()->first()->metadata['declared_size']);
        $this->assertSame(0, AiIntakeRun::count());
        Queue::assertNothingPushed();
    }

    public function test_spoken_upload_uses_shared_router_and_structured_client_with_persisted_transcript(): void
    {
        $samples = str_repeat("\0", 800);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $intake = $this->service->submit($this->admin, '', [UploadedFile::fake()->createWithContent('speech.wav', $wav)], ['customer_id' => $this->customer->id, 'kind' => 'audio']);
        $file = $intake->attachments()->first();
        $this->mock(AssistantSpeechRouter::class, fn ($m) => $m->shouldReceive('transcribe')->once()->withArgs(fn ($audio, $language) => $audio instanceof UploadedFile && $language === 'de')->andReturn(['text' => self::SOURCE, 'provider' => 'local', 'request_id' => 'synthetic-local', 'fallback' => false]));
        $result = $this->extractionResult($intake);
        foreach ($result['positions'][0]['evidence'] as &$evidence) {
            $evidence['message_id'] = null;
            $evidence['attachment_id'] = $file->id;
        }
        unset($evidence);
        $this->mock(AiDispositionClient::class, fn ($m) => $m->shouldReceive('structured')->once()->withArgs(fn ($task, $messages, $schema, $profile, $plugins) => $task === 'intake' && $profile === OpenRouterModelProfile::Data && str_contains($messages[1]['content'][0]['text'], self::SOURCE) && $plugins === [])->andReturn(new OpenRouterChatResponse(json_encode($result), model: 'synthetic-audio-extraction')));
        $this->service->analyze($intake->id);
        $this->assertSame(self::SOURCE, $file->fresh()->extracted_text);
        $this->assertStringNotContainsString(self::SOURCE, DB::table('ai_intake_attachments')->value('extracted_text'));
        $this->assertSame('verified', OperationInquiry::first()->status);
        $this->assertSame('synthetic-audio-extraction', AiIntakeRun::first()->model);
    }

    public function test_pdf_csv_and_image_keep_originals_and_use_shared_multimodal_profiles(): void
    {
        $intake = $this->service->submit($this->admin, self::SOURCE, [UploadedFile::fake()->createWithContent('request.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"), UploadedFile::fake()->createWithContent('table.csv', "role;location\nTf;Hamburg\n"), UploadedFile::fake()->image('request.png', 24, 24)], ['customer_id' => $this->customer->id]);
        $result = $this->extractionResult($intake);
        $this->mock(AiDispositionClient::class, fn ($m) => $m->shouldReceive('structured')->once()->withArgs(function ($task, $messages, $schema, $profile, $plugins) {
            $types = array_column($messages[1]['content'], 'type');

            return $profile === OpenRouterModelProfile::ImageUnderstanding && in_array('file', $types, true) && in_array('image_url', $types, true) && $plugins[0]['id'] === 'file-parser';
        })->andReturn(new OpenRouterChatResponse(json_encode($result))));
        $this->service->analyze($intake->id);
        $this->assertSame(3, AiIntakeAttachment::count());
        $this->assertSame(3, $intake->attachments()->where('status', 'extracted')->count());
        $this->assertSame('verified', OperationInquiry::first()->status);
        $this->assertStringContainsString('Tf | Hamburg', $intake->attachments()->where('kind', 'text')->first()->extracted_text);
    }

    public function test_text_and_csv_parser_caps_never_silently_drop_tail_rows_into_native_request(): void
    {
        $intake = $this->service->submit($this->admin, '', [UploadedFile::fake()->createWithContent('long.txt', str_repeat('x', 21000))], ['customer_id' => $this->customer->id]);
        $this->service->analyze($intake->id);
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame(0, OperationInquiry::count());
        $csv = $this->service->submit($this->admin, '', [UploadedFile::fake()->createWithContent('many.csv', "role;place\n".str_repeat("Tf;Hamburg\n", 501))], ['customer_id' => $this->customer->id]);
        $this->service->analyze($csv->id);
        $this->assertSame('review', $csv->fresh()->status);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(2, AiIntakeRun::where('status', 'failed')->count());
    }

    public function test_multi_position_reordered_reply_never_rewrites_native_positions_by_array_index(): void
    {
        $intake = $this->receive();
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $result['positions'][] = $result['positions'][0];
            $result['positions'][1]['title'] = 'Second synthetic service';

            return $result;
        });
        $this->service->analyze($intake->id);
        $first = OperationInquiry::orderBy('id')->first();
        $second = OperationInquiry::orderByDesc('id')->first();
        $reply = $this->receive(['uid' => 2, 'message_id' => 'reply@example.test', 'in_reply_to' => 'synthetic-1@example.test', 'text' => 'Please clarify both positions']);
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $result['positions'][0]['title'] = 'Second synthetic service';
            $result['positions'][] = $this->extractionResult($i)['positions'][0];

            return $result;
        });
        $this->service->analyze($reply->id);
        $this->assertSame($first->title, $first->fresh()->title);
        $this->assertSame($second->title, $second->fresh()->title);
        $this->assertSame(1, $first->fresh()->revision);
        $this->assertSame(1, $second->fresh()->revision);
        $this->assertSame(2, OperationInquiry::count());
        $this->assertSame('review', $reply->fresh()->status);
        $this->assertSame(2, $reply->proposals()->where('source_revision', 2)->where('error_code', 'reply_position_review')->count());
    }

    public function test_customer_reassignment_does_not_invalidate_an_existing_offer_or_customer_acceptance(): void
    {
        $intake = $this->analyzed();
        $inquiry = $intake->proposals()->first()->inquiry;
        app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'offer', ['amount' => '1000', 'terms' => 'Synthetic current terms'], $this->admin);
        app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'accept', ['note' => 'Synthetic express authorized acceptance', 'authorized' => true], $this->admin);
        $accepted = $inquiry->fresh();
        try {
            $this->service->assignCustomer($intake, $this->admin, $this->customer->id, $this->contact->id, $intake->revision);
            $this->fail('Commercial reassignment must use native workflow');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('accepted', $inquiry->fresh()->status);
            $this->assertSame($accepted->offer, $inquiry->fresh()->offer);
            $this->assertSame($accepted->accepted_revision, $inquiry->fresh()->accepted_revision);
        }
    }

    public function test_changed_order_location_blocks_approved_draft_generation(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        $proposal->fresh()->inquiry->order->update(['location_name' => 'Unapproved other yard']);
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(0, Shift::count());
        $this->assertSame('failed', $proposal->fresh()->status);
    }

    public function test_losing_concurrent_application_marks_run_finished_without_counting_application(): void
    {
        $proposal = $this->approved();
        $this->convert($proposal);
        $listener = function (AiIntakeRun $run) use ($proposal): void {
            if ($run->kind === 'apply') {
                DB::table('ai_intake_proposals')->where('id', $proposal->id)->update(['status' => 'applied', 'applied_at' => now()]);
            }
        };
        AiIntakeRun::created($listener);
        $this->assertSame(0, $this->service->applyApprovedProposals());
        $run = AiIntakeRun::where('kind', 'apply')->first();
        $this->assertSame('stale', $run->status);
        $this->assertSame('already_applied', $run->error_code);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(0, Shift::count());
    }

    public function test_discontinuous_multiday_services_create_only_explicit_daily_demands_and_drafts(): void
    {
        $proposal = $this->analyzed()->proposals()->first();
        $payload = $proposal->payload;
        $payload['demand']['ends_at'] = '2027-05-14T16:00';
        $payload['segments'][] = ['starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30];
        $proposal = $this->service->saveProposal($proposal, $this->admin, $payload, $proposal->revision);
        $this->service->approveProposal($proposal, $this->admin, $proposal->revision);
        $this->convert($proposal->fresh());
        $this->assertSame(1, $this->service->applyApprovedProposals());
        $this->assertSame(2, OrderDemand::count());
        $this->assertSame(2, Shift::count());
        $this->assertCount(2, $proposal->fresh()->demand_ids);
        foreach (OrderDemand::all() as $demand) {
            $this->assertSame(8.0, $demand->starts_at->diffInHours($demand->ends_at));
            $this->assertSame(0, app(OrderDemandService::class)->coverage($demand)['open']);
        }
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_ambiguous_reply_needs_explicit_unique_mapping_and_cannot_be_saved_against_guessed_inquiry(): void
    {
        $intake = $this->receive();
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $result['positions'][] = $result['positions'][0];
            $result['positions'][1]['title'] = 'Second synthetic service';

            return $result;
        });
        $this->service->analyze($intake->id);
        $previous = OperationInquiry::orderBy('id')->get();
        $reply = $this->receive(['uid' => 2, 'message_id' => 'reply@example.test', 'in_reply_to' => 'synthetic-1@example.test']);
        $this->service->analyze($reply->id);
        $proposals = $reply->proposals()->where('source_revision', 2)->orderBy('position_index')->get();
        $this->assertNull($proposals[0]->inquiry_id);
        $this->assertSame($previous->pluck('id')->all(), $proposals[0]->payload['candidate_inquiry_ids']);
        try {
            $this->service->saveProposal($proposals[0], $this->admin, $proposals[0]->payload, $proposals[0]->revision);
            $this->fail('Unmapped edit must reject');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $mapped = $this->service->mapProposalInquiry($proposals[0], $this->admin, $previous[0]->id, $proposals[0]->revision);
        $this->assertSame($previous[0]->id, $mapped->inquiry_id);
        $this->assertSame($this->admin->id, $mapped->payload['mapping']['reviewed_by']);
        try {
            $this->service->mapProposalInquiry($proposals[1], $this->admin, $previous[0]->id, $proposals[1]->revision);
            $this->fail('Duplicate mapping must reject');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->service->mapProposalInquiry($proposals[1], $this->admin, $previous[1]->id, $proposals[1]->revision);
        $this->assertSame(2, OperationInquiry::count());
    }

    public function test_explicit_qualification_names_map_only_to_active_exact_catalogue_entries(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $intake = $this->receive();
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $result['positions'][0]['qualification_requirements'] = ['Tf'];
            $result['positions'][0]['evidence'][] = ['field' => 'qualifications', 'message_id' => $i->messages()->first()->id, 'attachment_id' => null, 'quote' => self::SOURCE];

            return $result;
        });
        $this->service->analyze($intake->id);
        $proposal = $intake->proposals()->first();
        $this->assertSame([$type->id], $proposal->payload['demand']['qualification_ids']);
        $this->assertSame(['Tf'], $proposal->payload['raw_requirements']);
        $this->assertSame([], $proposal->payload['unmapped_requirements']);
        $this->assertSame('verified', $proposal->inquiry->status);
    }

    public function test_unmapped_requirements_stay_visible_until_manual_review_and_active_selection(): void
    {
        $type = QualificationType::create(['name' => 'Reviewed active requirement', 'is_active' => true]);
        $intake = $this->receive(['text' => self::SOURCE.' Streckenkenntnis X erforderlich.']);
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $result['positions'][0]['qualification_requirements'] = ['Streckenkenntnis X'];
            $result['positions'][0]['evidence'][] = ['field' => 'qualifications', 'message_id' => $i->messages()->first()->id, 'attachment_id' => null, 'quote' => 'Streckenkenntnis X erforderlich.'];

            return $result;
        });
        $this->service->analyze($intake->id);
        $proposal = $intake->proposals()->first();
        $this->assertSame('new', $proposal->inquiry->status);
        $this->assertSame(['Streckenkenntnis X'], $proposal->payload['unmapped_requirements']);
        $payload = $proposal->payload;
        $payload['demand']['qualification_ids'] = [$type->id];
        $saved = $this->service->saveProposal($proposal, $this->admin, $payload, $proposal->revision);
        $this->assertSame(['Streckenkenntnis X'], $saved->payload['unmapped_requirements']);
        $this->assertSame('new', $saved->inquiry->status);
        $payload['requirements_reviewed'] = true;
        $saved = $this->service->saveProposal($saved, $this->admin, $payload, $saved->revision);
        $this->assertSame([], $saved->payload['unmapped_requirements']);
        $this->assertSame('verified', $saved->inquiry->status);
        $this->assertSame([], $intake->fresh()->missing_fields);
        $this->assertSame('ready', $intake->fresh()->status);
        $this->service->approveProposal($saved, $this->admin, $saved->revision);
        $this->assertSame('approved', $saved->fresh()->status);
    }

    public function test_reported_incomplete_or_overflow_extraction_never_creates_a_partial_native_request(): void
    {
        $intake = $this->receive();
        $this->extractUsing(fn ($i) => $this->extractionResult($i, ['extraction_complete' => false, 'overflow' => true]));
        $this->service->analyze($intake->id);
        $this->assertSame('incomplete_extraction_review', $intake->fresh()->error_code);
        $this->assertTrue($intake->fresh()->analysis['overflow']);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeProposal::count());
    }

    public function test_automatic_mail_is_classified_without_ai_jobs_inquiries_or_replies(): void
    {
        $intake = $this->receive(['auto_submitted' => 'auto-replied']);
        $this->service->analyze($intake->id);
        $this->assertSame('auto_reply', $intake->analysis['classification']);
        $this->assertSame('mail_headers', $intake->analysis['classification_source']);
        $this->assertSame(0, AiIntakeRun::count());
        $this->assertSame(0, OperationInquiry::count());
        Queue::assertNothingPushed();
    }

    public function test_expired_worker_run_returns_to_review_without_provider_retry_or_overwriting_new_source(): void
    {
        $intake = $this->receive();
        $intake->update(['status' => 'analyzing']);
        $run = $intake->runs()->create(['kind' => 'analysis', 'status' => 'running', 'source_revision' => 1, 'settings_revision' => 1, 'supervising_user_id' => $this->admin->id, 'input_hash' => hash('sha256', 'synthetic'), 'started_at' => now()->subMinutes(16)]);
        Queue::fake();
        $this->assertSame(1, $this->service->expireStaleAnalysisRuns());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('worker_run_outcome_unknown', $intake->fresh()->error_code);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->service->expireStaleAnalysisRuns());
    }

    public function test_unknown_customer_draft_preserves_only_evidenced_values_without_creating_or_assigning_customer(): void
    {
        $intake = $this->receive(['from' => 'unknown@example.test', 'reply_to' => 'unknown@example.test', 'text' => self::SOURCE."\nFirma: Neue Bahn GmbH. Kontakt: Erika Beispiel."]);
        $this->extractUsing(function ($i) {
            $result = $this->extractionResult($i);
            $id = $i->messages()->first()->id;
            $result['customer_draft'] = ['company_name' => 'Neue Bahn GmbH', 'contact_name' => 'Erfundene Person', 'contact_email' => 'attacker@example.test', 'contact_phone' => null, 'evidence' => [['field' => 'company_name', 'message_id' => $id, 'attachment_id' => null, 'quote' => 'Firma: Neue Bahn GmbH.'], ['field' => 'contact_name', 'message_id' => $id, 'attachment_id' => null, 'quote' => 'Kontakt: Erika Beispiel.']]];

            return $result;
        });
        $this->service->analyze($intake->id);
        $draft = $intake->fresh()->analysis['customer_draft'];
        $this->assertSame('Neue Bahn GmbH', $draft['company_name']);
        $this->assertNull($draft['contact_name']);
        $this->assertNull($draft['contact_email']);
        $this->assertNotEmpty($draft['evidence']['company_name']);
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame(1, Customer::count());
    }
}
