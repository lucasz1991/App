<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\UnifiedInbox;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DispositionMailboxReview;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\AiDispositionClient;
use App\Services\Operations\AiDispositionSmtpTransport;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeImapClient;
use App\Services\Operations\AiIntakeMailService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\DispositionMailboxService;
use App\Services\Operations\DispositionMailboxWorkflowService;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\UnifiedInboxProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispositionMailboxDismissalTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private MockInterface $imap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_09_090000_create_staffing_automation_runs.php', '2026_10_09_100000_add_ai_customer_communication_controls.php', '2026_10_11_150000_create_disposition_mailbox_reviews.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->admin);
        $this->withoutVite();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('disposition-mailbox-dismissal-'.getmypid()));
        Storage::disk('local')->makeDirectory('compiled-views');
        config(['view.compiled' => Storage::disk('local')->path('compiled-views')]);
        AiDispositionSettings::save([
            'enabled' => false, 'supervisor_id' => null, 'imap_host' => 'imap.example.test',
            'imap_username' => 'dispatch@example.test', 'imap_password' => 'synthetic-dismissal-secret',
            'imap_folder' => 'INBOX', 'from_address' => 'dispatch@example.test',
        ], $this->admin);
        $this->imap = $this->mock(AiIntakeImapClient::class);
        $this->imap->shouldReceive('readMessage')->andReturnUsing(fn ($settings, $folder, $uid, $validity) => $this->email(['folder' => $folder, 'uid' => $uid, 'uid_validity' => $validity]));
        $this->mock(AiDispositionSmtpTransport::class, fn (MockInterface $mock) => $mock->shouldNotReceive('send'));
    }

    private function email(array $changes = []): array
    {
        return array_replace([
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'folder' => 'INBOX', 'uid_validity' => 401, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test', 'to' => 'dispatch@example.test',
            'message_id' => '<dismissal-32@example.test>', 'subject' => 'Synthetic welcome mail',
            'date' => '2026-10-11T12:00:00Z', 'seen' => false, 'text' => 'Welcome. Your mailbox is ready. No staffing inquiry.',
            'raw' => "From: customer@example.test\r\nSubject: Synthetic welcome mail\r\n\r\nImmutable welcome message.",
            'attachments' => [],
        ], $changes);
    }

    private function capture(array $changes = []): AiIntake
    {
        return app(AiIntakeService::class)->receiveEmail($this->email($changes), $this->admin);
    }

    private function classify(AiIntake $intake, string $classification = 'not_inquiry', int $uid = 32): array
    {
        $review = DispositionMailboxReview::where('uid', $uid)->firstOrFail();

        return app(DispositionMailboxService::class)->classify($this->admin, 'INBOX', $uid, 401, $classification, $review->revision, $intake->fresh()->revision);
    }

    private function noAutomaticEffects(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    private function enableAutomation(): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id, 'automation_mode' => 'automatic',
                'customer_receipt_mode' => 'automatic', 'customer_clarification_mode' => 'automatic']
        ));
    }

    private function proposal(AiIntake $intake, array $changes = []): AiIntakeProposal
    {
        return AiIntakeProposal::create(array_replace([
            'intake_id' => $intake->id, 'source_revision' => $intake->source_revision, 'position_index' => 0,
            'status' => 'proposed', 'revision' => 1,
            'payload' => ['demand' => ['title' => 'Synthetic proposal', 'starts_at' => null, 'ends_at' => null,
                'timezone' => 'Europe/Berlin', 'location_name' => null, 'role_name' => null, 'required_staff' => null,
                'qualification_ids' => []], 'segments' => [], 'missing_fields' => ['starts_at', 'ends_at'], 'evidence' => []],
        ], $changes));
    }

    private function delivery(AiIntake $intake, string $status): AiIntakeDelivery
    {
        $settings = AiDispositionSettings::all(true);

        return AiIntakeDelivery::create([
            'intake_id' => $intake->id, 'message_id' => $intake->latest_inbound_message_id,
            'mailbox_id' => AiDispositionSettings::mailboxId($settings), 'recipient_email' => 'customer@example.test',
            'subject' => 'Synthetic clarification', 'body' => 'Please clarify the starting time.', 'status' => $status,
            'dedup_key' => hash('sha256', $intake->id.'-'.$status.'-'.AiIntakeDelivery::count()),
            'settings_revision' => $settings['revision'], 'intake_revision' => $intake->revision,
            'source_revision' => $intake->source_revision, 'question_round' => 0,
        ]);
    }

    private function verifiedCustomer(AiIntake $intake): Customer
    {
        $customer = Customer::create(['company_name' => 'Synthetic verified customer', 'email' => 'customer@example.test', 'is_active' => true]);
        $contact = CustomerContact::create(['customer_id' => $customer->id, 'name' => 'Synthetic verified contact',
            'email' => 'customer@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $intake->update(['customer_id' => $customer->id, 'customer_contact_id' => $contact->id, 'match_method' => 'manual']);

        return $customer;
    }

    private function plainIntake(string $status, string $title = 'Synthetic intake', ?int $customerId = null): AiIntake
    {
        return AiIntake::create(['source_type' => 'manual', 'status' => $status, 'title' => $title,
            'customer_id' => $customerId, 'revision' => 1, 'source_revision' => 1, 'created_by' => $this->admin->id,
            'paused_at' => $status === 'paused' ? now()->utc() : null]);
    }

    private function rejected(array $statuses, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the dismissed-mail action to be rejected.');
        } catch (HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), $statuses);
        } catch (AuthorizationException) {
            $this->assertContains(403, $statuses);
        }
    }

    public function test_confirmed_non_inquiry_is_terminal_with_a_revisioned_human_audit_and_immutable_original(): void
    {
        $intake = $this->capture();
        $revision = $intake->revision;
        $sourceRevision = $intake->source_revision;
        $source = AiIntakeMessage::firstOrFail();
        $originalSource = $source->getRawOriginal();
        $reviewRevision = DispositionMailboxReview::firstOrFail()->revision;

        $result = $this->classify($intake);

        $closed = $intake->fresh();
        $this->assertSame('not_inquiry', $closed->status);
        $this->assertSame('mailbox_not_inquiry', $closed->error_code);
        $this->assertSame('not_inquiry', $result['intake_status']);
        $this->assertSame('not_inquiry', $result['classification']);
        $this->assertSame($revision + 1, $closed->revision);
        $this->assertSame($sourceRevision, $closed->source_revision);
        $this->assertSame($source->id, $closed->latest_inbound_message_id);
        $review = DispositionMailboxReview::firstOrFail();
        $this->assertSame($reviewRevision + 1, $review->revision);
        $this->assertSame($this->admin->id, $review->reviewed_by);
        $audit = OperationAudit::where('action', 'mailbox.classify')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('human', $audit->actor_kind);
        $this->assertSame($review->revision, (int) $audit->revision);
        $this->assertSame(['from' => 'unreviewed', 'to' => 'not_inquiry'], $audit->data);
        $terminalAudit = OperationAudit::where('subject_type', 'AiIntake')->where('subject_id', $intake->id)
            ->where('action', 'ai.intake.not_inquiry')->firstOrFail();
        $this->assertSame($this->admin->id, $terminalAudit->actor_id);
        $this->assertSame('human', $terminalAudit->actor_kind);
        $this->assertSame($closed->revision, (int) $terminalAudit->revision);
        $this->assertSame(['message_id' => $source->id, 'source_revision' => $sourceRevision, 'from' => 'review', 'to' => 'not_inquiry'], $terminalAudit->data);
        $this->assertSame($originalSource, $source->fresh()->getRawOriginal());
        $this->assertSame($this->email()['raw'], app(AiIntakeService::class)->rawBytes($source, $this->admin));
        $this->assertSame(0, AiIntakeProposal::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    public function test_a_previously_paused_non_inquiry_can_finish_without_rewriting_its_source(): void
    {
        $intake = $this->capture();
        app(AiIntakeService::class)->pause($intake, $this->admin, $intake->revision);
        $paused = $intake->fresh();
        $source = AiIntakeMessage::firstOrFail()->getRawOriginal();

        $this->classify($paused);

        $closed = $paused->fresh();
        $this->assertSame('not_inquiry', $closed->status);
        $this->assertSame($paused->revision + 1, $closed->revision);
        $this->assertSame($paused->source_revision, $closed->source_revision);
        $this->assertSame('paused', OperationAudit::where('action', 'ai.intake.not_inquiry')->firstOrFail()->data['from']);
        $this->assertSame($source, AiIntakeMessage::firstOrFail()->getRawOriginal());
        $this->noAutomaticEffects();
    }

    public function test_unchanged_human_classification_and_same_uid_capture_are_idempotent(): void
    {
        $intake = $this->capture();
        $this->classify($intake);
        $closed = $intake->fresh()->getRawOriginal();
        $review = DispositionMailboxReview::firstOrFail()->getRawOriginal();
        $source = AiIntakeMessage::firstOrFail()->getRawOriginal();
        $audits = OperationAudit::count();

        $this->classify($intake);
        $manualDuplicate = $this->capture();
        $this->enableAutomation();
        $automaticDuplicate = app(AiIntakeService::class)->receiveEmail($this->email());

        $this->assertSame($intake->id, $manualDuplicate->id);
        $this->assertSame($intake->id, $automaticDuplicate->id);
        $this->assertSame($closed, $intake->fresh()->getRawOriginal());
        $this->assertSame($review, DispositionMailboxReview::firstOrFail()->getRawOriginal());
        $this->assertSame($source, AiIntakeMessage::firstOrFail()->getRawOriginal());
        $this->assertSame($audits, OperationAudit::count());
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->noAutomaticEffects();
    }

    public function test_dismissal_retires_only_unconsumed_proposals_and_unsent_deliveries_and_finishes_running_analysis(): void
    {
        $intake = $this->capture();
        $proposal = $this->proposal($intake);
        $proposalPayload = $proposal->payload;
        $draft = $this->delivery($intake, 'draft');
        $pending = $this->delivery($intake, 'pending');
        $sent = $this->delivery($intake, 'sent');
        $unknown = $this->delivery($intake, 'unknown');
        $sending = $this->delivery($intake, 'sending');
        $run = AiIntakeRun::create(['intake_id' => $intake->id, 'kind' => 'mailbox_test', 'status' => 'running',
            'source_revision' => $intake->source_revision, 'settings_revision' => AiDispositionSettings::all(true)['revision'],
            'input_hash' => str_repeat('a', 64), 'supervising_user_id' => $this->admin->id, 'started_at' => now()->utc()]);

        $this->classify($intake);

        $this->assertSame('not_inquiry', $intake->fresh()->status);
        $this->assertSame('stale', $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->inquiry_id);
        $this->assertSame($proposalPayload, $proposal->fresh()->payload);
        $this->assertSame('canceled', $draft->fresh()->status);
        $this->assertSame('canceled', $pending->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertSame('unknown', $unknown->fresh()->status);
        $this->assertSame('sending', $sending->fresh()->status);
        $this->assertNotSame('running', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_terminal_source_cannot_be_reanalyzed_reassigned_or_reimported_by_native_actions(): void
    {
        $intake = $this->capture();
        $customer = $this->verifiedCustomer($intake);
        $intake->update(['analysis' => ['intent' => 'inquiry', 'classification' => 'new_inquiry', 'positions' => []]]);
        $proposal = $this->proposal($intake);
        $this->classify($intake);
        $closed = $intake->fresh();
        $original = $closed->getRawOriginal();
        $service = app(AiIntakeService::class);
        $this->mock(AiIntakeExtractionService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('extract'));
        $actions = [
            fn () => $service->analyzeManually($closed, $this->admin, $closed->revision, AiDispositionSettings::all(true)['revision']),
            fn () => $service->reanalyze($closed, $this->admin, $closed->revision),
            fn () => $service->pause($closed, $this->admin, $closed->revision),
            fn () => $service->assignCustomer($closed, $this->admin, $customer->id, $closed->customer_contact_id, $closed->revision),
            fn () => $service->createInquiries($closed, $this->admin, $closed->revision),
            fn () => $service->saveProposal($proposal->fresh(), $this->admin, $proposal->payload, $proposal->fresh()->revision),
        ];
        foreach ($actions as $action) {
            $this->rejected([409, 422], $action);
            $this->assertSame($original, $closed->fresh()->getRawOriginal());
        }
        $this->enableAutomation();
        $this->mock(AiDispositionClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldNotReceive('structured');
        });
        $service->analyze($closed->id);
        $this->assertSame($original, $closed->fresh()->getRawOriginal());
        $this->assertSame(0, AiIntakeRun::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    public function test_terminal_intake_cannot_enqueue_or_prepare_mail_and_a_preexisting_queue_delivery_cannot_send(): void
    {
        $intake = $this->capture();
        $this->verifiedCustomer($intake);
        $intake->update(['analysis' => ['intent' => 'inquiry'], 'missing_fields' => ['starts_at']]);
        $this->proposal($intake);
        $this->classify($intake);
        $closed = $intake->fresh();
        $this->enableAutomation();
        $mail = app(AiIntakeMailService::class);
        $this->assertNull($mail->enqueueReceipt($closed));
        $this->assertNull($mail->enqueueClarification($closed, [['field' => 'starts_at', 'position' => 1]]));
        $this->rejected([409, 422], fn () => $mail->prepareManualClarification($closed, $this->admin, $closed->revision, AiDispositionSettings::all(true)['revision']));
        $pending = $this->delivery($closed, 'pending');

        $this->assertSame('canceled', $mail->deliver($pending->id));
        $this->assertSame(0, $pending->fresh()->attempts);
        $this->assertSame(0, $closed->fresh()->question_round);
        $this->assertSame('not_inquiry', $closed->fresh()->status);
        $this->noAutomaticEffects();
    }

    public function test_only_fresh_authorized_classification_can_change_or_reopen_terminal_state(): void
    {
        $intake = $this->capture();
        $reviewRevision = DispositionMailboxReview::firstOrFail()->revision;
        $intakeRevision = $intake->revision;
        $this->classify($intake);
        $closed = $intake->fresh()->getRawOriginal();
        $review = DispositionMailboxReview::firstOrFail()->getRawOriginal();
        $auditCount = OperationAudit::count();
        $mailbox = app(DispositionMailboxService::class);
        $this->rejected([409], fn () => $mailbox->classify($this->admin, 'INBOX', 32, 401, 'inquiry', $reviewRevision, $intake->fresh()->revision));
        $this->rejected([409], fn () => $mailbox->classify($this->admin, 'INBOX', 32, 401, 'inquiry', DispositionMailboxReview::firstOrFail()->revision, $intakeRevision));
        User::whereKey($this->admin->id)->update(['status' => false]);
        $this->rejected([403], fn () => $this->classify($intake, 'inquiry'));

        $this->assertSame($closed, $intake->fresh()->getRawOriginal());
        $this->assertSame($review, DispositionMailboxReview::firstOrFail()->getRawOriginal());
        $this->assertSame($auditCount, OperationAudit::count());
        $this->noAutomaticEffects();
    }

    public function test_explicit_inquiry_or_unreviewed_reset_reopens_only_for_personal_review_without_automatic_work(): void
    {
        foreach (['inquiry' => 32, 'unreviewed' => 33] as $classification => $uid) {
            $intake = $this->capture(['uid' => $uid, 'message_id' => '<reset-'.$uid.'@example.test>']);
            $proposal = $this->proposal($intake);
            $this->classify($intake, 'not_inquiry', $uid);
            $closed = $intake->fresh();
            $source = $closed->messages()->firstOrFail()->getRawOriginal();
            $this->enableAutomation();

            $result = $this->classify($closed, $classification, $uid);

            $reopened = $intake->fresh();
            $this->assertSame('review', $reopened->status);
            $this->assertSame('mailbox_test_review', $reopened->error_code);
            $this->assertSame($closed->revision + 1, $reopened->revision);
            $this->assertSame($closed->source_revision, $reopened->source_revision);
            $this->assertNull($reopened->paused_at);
            $this->assertNull($reopened->processed_source_revision);
            $this->assertSame($classification, $result['classification']);
            $this->assertSame('stale', $proposal->fresh()->status);
            $this->assertSame($source, $reopened->messages()->firstOrFail()->getRawOriginal());
            $audit = OperationAudit::where('subject_type', 'AiIntake')->where('subject_id', $reopened->id)->where('action', 'ai.intake.reopened')->firstOrFail();
            $this->assertSame($this->admin->id, $audit->actor_id);
            $this->assertSame('not_inquiry', $audit->data['from']);
            $this->assertSame('review', $audit->data['to']);
            $this->assertSame($reopened->source_revision, $audit->data['source_revision']);
            $this->noAutomaticEffects();
        }
    }

    public function test_old_uid_classification_cannot_close_or_reopen_the_latest_source_of_a_conversation(): void
    {
        $intake = $this->capture();
        $next = $this->capture(['uid' => 33, 'message_id' => '<latest-33@example.test>', 'in_reply_to' => '<dismissal-32@example.test>',
            'subject' => 'Synthetic newer reply', 'text' => 'This is a newer message requiring its own review.']);
        $this->assertSame($intake->id, $next->id);
        $current = $next->fresh()->getRawOriginal();
        $this->classify($next, 'not_inquiry', 32);
        $this->assertSame($current, $next->fresh()->getRawOriginal());
        $this->assertSame('not_inquiry', DispositionMailboxReview::where('uid', 32)->firstOrFail()->classification);
        $this->assertSame('unreviewed', DispositionMailboxReview::where('uid', 33)->firstOrFail()->classification);
        $this->classify($next, 'inquiry', 32);
        $this->assertSame($current, $next->fresh()->getRawOriginal());
        $this->assertSame(2, $next->messages()->count());
        $this->noAutomaticEffects();
    }

    public function test_unreviewed_reset_still_requires_confirmed_classification_before_guided_customer_or_inquiry_actions(): void
    {
        $intake = $this->capture();
        $customer = $this->verifiedCustomer($intake);
        $intake->update(['analysis' => ['intent' => 'inquiry', 'classification' => 'new_inquiry', 'positions' => []]]);
        $this->classify($intake);
        $this->classify($intake, 'unreviewed');
        $reopened = $intake->fresh();
        $review = DispositionMailboxReview::firstOrFail();
        $workflow = app(DispositionMailboxWorkflowService::class);
        $context = $workflow->refresh($this->admin, [
            'intake_id' => $reopened->id, 'intake_revision' => $reopened->revision, 'source_revision' => $reopened->source_revision,
            'settings_revision' => AiDispositionSettings::all(true)['revision'], 'review_revision' => $review->revision,
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'folder' => 'INBOX', 'uid' => 32, 'uid_validity' => 401,
        ]);
        $this->assertSame('unreviewed', $context['classification']);
        $original = $reopened->getRawOriginal();
        $actions = [
            fn () => $workflow->assignCustomer($this->admin, $context, $customer->id, $reopened->customer_contact_id),
            fn () => $workflow->createInquiries($this->admin, $context),
            fn () => $workflow->prepareClarification($this->admin, $context),
        ];
        foreach ($actions as $action) {
            $this->rejected([422], $action);
            $this->assertSame($original, $reopened->fresh()->getRawOriginal());
        }
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    public function test_a_failed_classification_audit_rolls_back_terminal_state_and_all_retirement_changes(): void
    {
        $intake = $this->capture();
        $proposal = $this->proposal($intake);
        $draft = $this->delivery($intake, 'draft');
        $originalIntake = $intake->fresh()->getRawOriginal();
        $originalReview = DispositionMailboxReview::firstOrFail()->getRawOriginal();
        $originalProposal = $proposal->fresh()->getRawOriginal();
        $originalDraft = $draft->fresh()->getRawOriginal();
        $originalSource = AiIntakeMessage::firstOrFail()->getRawOriginal();
        $auditCount = OperationAudit::count();
        $audit = app(OperationsAuditService::class);
        $this->mock(OperationsAuditService::class, function (MockInterface $mock) use ($audit): void {
            $mock->shouldReceive('record')->andReturnUsing(function ($subject, $actor, string $action, array $data = []) use ($audit): void {
                if ($action === 'mailbox.classify') {
                    throw new RuntimeException('Synthetic classification audit failure.');
                }
                $audit->record($subject, $actor, $action, $data);
            });
        });

        try {
            $this->classify($intake);
            $this->fail('Audit failure must abort the whole dismissal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic classification audit failure.', $exception->getMessage());
        }
        $this->assertSame($originalIntake, $intake->fresh()->getRawOriginal());
        $this->assertSame($originalReview, DispositionMailboxReview::firstOrFail()->getRawOriginal());
        $this->assertSame($originalProposal, $proposal->fresh()->getRawOriginal());
        $this->assertSame($originalDraft, $draft->fresh()->getRawOriginal());
        $this->assertSame($originalSource, AiIntakeMessage::firstOrFail()->getRawOriginal());
        $this->assertSame($auditCount, OperationAudit::count());
        $this->assertSame($this->email()['raw'], app(AiIntakeService::class)->rawBytes(AiIntakeMessage::firstOrFail(), $this->admin));
        $this->noAutomaticEffects();
    }

    public function test_a_genuinely_new_uid_reply_keeps_the_closed_original_and_creates_a_new_review_intake(): void
    {
        $intake = $this->capture();
        $this->classify($intake);
        $closed = $intake->fresh()->getRawOriginal();
        $source = $intake->messages()->firstOrFail()->getRawOriginal();

        $new = $this->capture(['uid' => 33, 'message_id' => '<fresh-33@example.test>', 'in_reply_to' => '<dismissal-32@example.test>',
            'subject' => 'Synthetic genuinely new inquiry', 'text' => 'A new personal review is needed for this new message.']);

        $this->assertNotSame($intake->id, $new->id);
        $this->assertSame('review', $new->status);
        $this->assertSame(1, $new->source_revision);
        $this->assertSame($closed, $intake->fresh()->getRawOriginal());
        $this->assertSame($source, $intake->messages()->firstOrFail()->getRawOriginal());
        $this->assertSame('not_inquiry', DispositionMailboxReview::where('uid', 32)->firstOrFail()->classification);
        $this->assertSame('unreviewed', DispositionMailboxReview::where('uid', 33)->firstOrFail()->classification);
        $this->assertSame(2, AiIntake::count());
        $this->assertSame(2, AiIntakeMessage::count());
        $this->noAutomaticEffects();
    }

    public function test_relabeling_a_completed_or_native_linked_intake_preserves_its_business_workflow(): void
    {
        foreach (['review' => 32, 'completed' => 33] as $status => $uid) {
            $intake = $this->capture(['uid' => $uid, 'message_id' => '<native-'.$uid.'@example.test>']);
            $intake->update(['status' => $status]);
            $inquiry = OperationInquiry::create(['channel' => 'email', 'title' => 'Native protected inquiry '.$uid, 'original' => 'Native immutable source',
                'status' => 'offered', 'timezone' => 'Europe/Berlin', 'revision' => 3, 'created_by' => $this->admin->id]);
            $proposal = $this->proposal($intake, ['inquiry_id' => $inquiry->id, 'status' => $status === 'completed' ? 'applied' : 'proposed']);
            $pending = $this->delivery($intake, 'pending');
            $originalIntake = $intake->fresh()->getRawOriginal();
            $originalInquiry = $inquiry->fresh()->getRawOriginal();
            $originalProposal = $proposal->fresh()->getRawOriginal();
            $originalDelivery = $pending->fresh()->getRawOriginal();

            $result = $this->classify($intake, 'not_inquiry', $uid);

            $this->assertSame($status, $result['intake_status']);
            $this->assertSame($originalIntake, $intake->fresh()->getRawOriginal());
            $this->assertSame($originalInquiry, $inquiry->fresh()->getRawOriginal());
            $this->assertSame($originalProposal, $proposal->fresh()->getRawOriginal());
            $this->assertSame($originalDelivery, $pending->fresh()->getRawOriginal());
            $this->assertSame('not_inquiry', DispositionMailboxReview::where('uid', $uid)->firstOrFail()->classification);
        }
        $this->assertSame(0, OperationAudit::where('action', 'ai.intake.not_inquiry')->count());
        $this->noAutomaticEffects();
    }

    public function test_terminal_mail_stays_in_the_mailbox_and_is_available_with_its_honest_native_detail_label(): void
    {
        $intake = $this->capture();
        $this->classify($intake);
        $email = $this->email();
        $this->imap->shouldReceive('snapshot')->andReturn(['uid_validity' => 401, 'uid_next' => 33]);
        $this->imap->shouldReceive('browse')->andReturn(['folder' => 'INBOX', 'uid_validity' => 401, 'total' => 1,
            'page' => 1, 'per_page' => 20, 'messages' => [array_intersect_key($email, array_flip(['uid', 'from', 'to', 'date', 'seen', 'subject']))]]);
        $page = app(DispositionMailboxService::class)->inbox($this->admin);
        $this->assertSame(1, $page['total']);
        $this->assertSame(32, $page['messages'][0]['uid']);
        $this->assertFalse($page['messages'][0]['seen']);
        $this->assertSame('not_inquiry', $page['messages'][0]['classification']);
        $this->assertSame('not_inquiry', $page['messages'][0]['intake_status']);
        $this->assertSame('Keine Anfrage', AiIntakeInbox::LABELS['not_inquiry']);
        Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $intake->id, 'modalOnly' => true])
            ->assertSet('selectedId', $intake->id)->assertSee('Keine Anfrage')->assertSee($email['text']);
        $this->assertSame($email['raw'], app(AiIntakeService::class)->rawBytes(AiIntakeMessage::firstOrFail(), $this->admin));
        $this->noAutomaticEffects();
    }

    public function test_unified_projection_excludes_terminal_mail_from_every_bucket_and_retains_ordinary_pauses(): void
    {
        $intake = $this->capture();
        $this->classify($intake);
        $paused = $this->plainIntake('paused', 'Ordinarily paused intake');
        $review = $this->plainIntake('review');
        $waiting = $this->plainIntake('waiting_customer');
        $ready = $this->plainIntake('ready');
        $done = $this->plainIntake('completed');
        $rows = UnifiedInboxProjection::query($this->admin)->get();
        $this->assertNotContains($intake->id, $rows->where('kind', 'ai')->pluck('id')->all());
        $this->assertSame('review', $rows->firstWhere('id', $paused->id)->bucket);
        $this->assertEqualsCanonicalizing([$paused->id, $review->id, $waiting->id, $ready->id, $done->id], $rows->where('kind', 'ai')->pluck('id')->all());
        $counts = $rows->countBy('bucket')->all();
        $this->assertSame(['review' => 2, 'waiting' => 1, 'ready' => 1, 'done' => 1], $counts);
        Livewire::test(UnifiedInbox::class)->assertViewHas('rows', fn ($page) => $page->total() === 5)
            ->assertViewHas('counts', fn ($counts) => (int) $counts->get('review') === 2 && $counts->sum() === 5)
            ->assertSee('Ordinarily paused intake')->assertDontSee('Synthetic welcome mail');
        $this->noAutomaticEffects();
    }

    public function test_excluded_ai_row_does_not_hide_a_native_inquiry_in_legacy_linked_data(): void
    {
        $intake = $this->plainIntake('not_inquiry');
        $inquiry = OperationInquiry::create(['channel' => 'email', 'title' => 'Visible native inquiry', 'original' => 'Existing native source',
            'status' => 'new', 'timezone' => 'Europe/Berlin', 'created_by' => $this->admin->id]);
        $this->proposal($intake, ['inquiry_id' => $inquiry->id]);

        $rows = UnifiedInboxProjection::query($this->admin)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('inquiry', $rows->first()->kind);
        $this->assertSame($inquiry->id, $rows->first()->id);
        $this->assertSame('review', $rows->first()->bucket);
        $this->noAutomaticEffects();
    }

    public function test_mailbox_update_listener_refreshes_counts_resets_pagination_and_preserves_native_detail_and_paused_navigation(): void
    {
        $intake = $this->capture();
        $paused = $this->plainIntake('paused', 'Still visible pause');
        for ($index = 1; $index <= 15; $index++) {
            $this->plainIntake('review', 'Synthetic active row '.$index);
        }
        $component = Livewire::test(UnifiedInbox::class)->call('select', 'ai', $intake->id)
            ->assertDispatched('open-ai-intake-panel', intakeId: $intake->id, create: false, customerId: null)
            ->assertSet('detail', null)->call('setPage', 2, 'inboxPage')
            ->assertSet('paginators.inboxPage', 2)->assertViewHas('rows', fn ($page) => $page->total() === 17);
        $this->classify($intake);

        $component->dispatch('disposition-mailbox-updated')->assertSet('detail', null)->assertSet('paginators.inboxPage', 1)
            ->assertViewHas('rows', fn ($page) => $page->total() === 16)
            ->assertViewHas('counts', fn ($counts) => (int) $counts->get('review') === 16 && $counts->sum() === 16);
        $component->call('select', 'ai', $paused->id)
            ->assertDispatched('open-ai-intake-panel', intakeId: $paused->id, create: false, customerId: null)
            ->dispatch('disposition-mailbox-updated')->assertSet('detail', null);
        $native = OperationInquiry::create(['channel' => 'manual', 'title' => 'Native detail stays selected',
            'original' => 'Synthetic native original', 'status' => 'new', 'timezone' => 'Europe/Berlin', 'created_by' => $this->admin->id]);
        $component->call('select', 'inquiry', $native->id)->assertSet('detail.id', $native->id)
            ->dispatch('disposition-mailbox-updated')->assertSet('detail.id', $native->id)->assertSet('detail.kind', 'inquiry');
        $this->noAutomaticEffects();
    }
}
