<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\DispositionMailbox;
use App\Livewire\Operations\UnifiedInbox;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DispositionMailboxReview;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeImapClient;
use App\Services\Operations\AiIntakeMailboxService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\DispositionMailboxService;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\AiDispositionSettings;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispositionMailboxTest extends TestCase
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
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('disposition-mailbox-'.getmypid()));
        $this->imap = $this->mock(AiIntakeImapClient::class);
    }

    private function configure(array $changes = []): array
    {
        AiDispositionSettings::save(array_replace([
            'enabled' => false, 'supervisor_id' => null,
            'imap_host' => 'imap.example.test', 'imap_username' => 'dispatch@example.test',
            'imap_password' => 'synthetic-mailbox-secret', 'imap_folder' => 'INBOX',
            'from_address' => 'dispatch@example.test',
        ], $changes), $this->admin);

        return AiDispositionSettings::all(true);
    }

    private function email(array $changes = []): array
    {
        $settings = AiDispositionSettings::all(true);

        return array_replace([
            'mailbox_id' => AiDispositionSettings::mailboxId($settings),
            'folder' => 'INBOX', 'uid_validity' => 401, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test',
            'to' => 'dispatch@example.test', 'message_id' => '<fixture-32@example.test>',
            'subject' => 'Synthetic staffing inquiry', 'date' => '2026-10-11T10:00:00Z',
            'text' => 'Two train drivers in Hamburg on 2026-10-19, 08:00–16:00.',
            'raw' => "From: customer@example.test\r\nSubject: Synthetic staffing inquiry\r\n\r\nImmutable MIME source.",
            'seen' => false, 'attachments' => [],
        ], $changes);
    }

    private function noSideEffects(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        $this->assertSame(0, OperationInquiry::count());
    }

    public function test_manual_email_capture_preserves_original_and_is_idempotent_when_automation_is_disabled(): void
    {
        $this->configure();
        $email = $this->email();
        $service = app(AiIntakeService::class);
        $intake = $service->receiveEmail($email, $this->admin);
        $duplicate = $service->receiveEmail($email, $this->admin);

        $this->assertSame($intake->id, $duplicate->id);
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame('email', $intake->source_type);
        $this->assertSame('review', $intake->status);
        $this->assertSame('manual_mailbox_review', $intake->error_code);
        $this->assertSame($this->admin->id, $intake->supervising_user_id);
        $stored = $intake->messages()->firstOrFail();
        $this->assertSame($email['text'], $stored->body);
        $this->assertSame(hash('sha256', $email['raw']), $stored->raw_hash);
        $this->assertSame($email['raw'], app(AiIntakeAttachmentService::class)->bytes($intake, $stored->raw_path, $stored->raw_hash));
        $this->assertSame(401, $stored->metadata['uid_validity']);
        $this->assertSame(32, $stored->metadata['uid']);
        $this->noSideEffects();
    }

    public function test_automatic_email_capture_still_requires_automation_and_supervision(): void
    {
        $this->configure();
        try {
            app(AiIntakeService::class)->receiveEmail($this->email());
            $this->fail('The unattended intake must remain disabled.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, AiIntake::count());
        $this->noSideEffects();
    }

    public function test_manual_email_capture_rechecks_actor_activation_before_existing_receipt_lookup(): void
    {
        $this->configure();
        $service = app(AiIntakeService::class);
        $intake = $service->receiveEmail($this->email(), $this->admin);
        User::whereKey($this->admin->id)->update(['status' => false]);
        try {
            $service->receiveEmail($this->email(), $this->admin);
            $this->fail('An inactive actor must not read an existing intake through import.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($intake->id, AiIntake::firstOrFail()->id);
        $this->assertSame(1, AiIntakeMessage::count());
        $this->noSideEffects();
    }

    public function test_configuration_exposes_a_mailbox_button_while_automation_remains_disabled(): void
    {
        $this->configure();
        $this->assertTrue(app(DispositionMailboxService::class)->available($this->admin));
        Livewire::test(UnifiedInbox::class)
            ->assertSeeLivewire(DispositionMailbox::class)
            ->assertSee('openMailbox', false)
            ->assertDontSee('synthetic-mailbox-secret');
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->noSideEffects();
    }

    public function test_unconfigured_mailbox_does_not_mount_or_expose_its_toolbar_action(): void
    {
        $this->assertFalse(app(DispositionMailboxService::class)->available($this->admin));
        Livewire::test(UnifiedInbox::class)
            ->assertDontSeeLivewire(DispositionMailbox::class)
            ->assertDontSee('openMailbox', false);
        Livewire::test(DispositionMailbox::class)->call('open')->assertNotFound();
        $this->noSideEffects();
    }

    private function serverPage(array $messages, int $page = 1, int $total = 3, int $validity = 401, string $folder = 'INBOX'): array
    {
        return ['folder' => $folder, 'uid_validity' => $validity, 'total' => $total,
            'page' => $page, 'per_page' => 20, 'messages' => array_map(fn ($message) => array_intersect_key($message, array_flip(['uid', 'from', 'to', 'subject', 'date', 'seen'])), $messages)];
    }

    private function delivery(AiIntake $intake, string $status = 'draft', array $changes = []): AiIntakeDelivery
    {
        $settings = AiDispositionSettings::all(true);

        return AiIntakeDelivery::create(array_replace([
            'intake_id' => $intake->id, 'message_id' => $intake->messages()->first()?->id,
            'mailbox_id' => AiDispositionSettings::mailboxId($settings),
            'recipient_email' => 'customer@example.test', 'subject' => 'Synthetic customer clarification',
            'body' => 'Please clarify the starting time.', 'status' => $status,
            'dedup_key' => hash('sha256', $intake->id.'-'.$status.'-'.AiIntakeDelivery::count()),
            'settings_revision' => $settings['revision'], 'intake_revision' => $intake->revision,
            'source_revision' => $intake->source_revision, 'question_round' => 0,
        ], $changes));
    }

    private function expectHttpStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the mailbox action to be rejected with HTTP '.$status.'.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $status);
        }
    }

    public function test_full_paginated_inbox_includes_unrelated_mail_and_links_only_the_current_uid_identity(): void
    {
        $this->configure();
        $intakeService = app(AiIntakeService::class);
        $inquiry = $intakeService->receiveEmail($this->email(), $this->admin);
        $inquiry->update(['analysis' => ['intent' => 'inquiry', 'classification' => 'new_inquiry']]);
        $unrelated = $intakeService->receiveEmail($this->email(['uid' => 31, 'message_id' => '<unrelated@example.test>']), $this->admin);
        $unrelated->update(['analysis' => ['intent' => 'other', 'classification' => 'unrelated']]);
        $intakeService->receiveEmail($this->email(['uid' => 30, 'uid_validity' => 400, 'message_id' => '<older-uid@example.test>']), $this->admin);
        $messages = [$this->email(), $this->email(['uid' => 31, 'subject' => 'Newsletter']), $this->email(['uid' => 30, 'subject' => 'Current UID from another generation'])];
        $this->imap->shouldReceive('browse')->once()->withArgs(fn ($settings, $folder, $page, $perPage, $search) => $folder === 'INBOX' && $page === 2 && $perPage === 20 && $search === 'rail')->andReturn($this->serverPage($messages, 2, 63));

        $result = app(DispositionMailboxService::class)->inbox($this->admin, 2, 'rail');
        $this->assertSame(63, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(20, $result['per_page']);
        $this->assertCount(3, $result['messages']);
        $this->assertSame(['inquiry', 'not_inquiry', 'unreviewed'], array_column($result['messages'], 'classification'));
        $this->assertSame([$inquiry->id, $unrelated->id, null], array_column($result['messages'], 'intake_id'));
        foreach ($result['messages'] as $message) {
            $this->assertArrayNotHasKey('raw', $message);
            $this->assertArrayNotHasKey('text', $message);
        }
        $this->noSideEffects();
    }

    public function test_manual_classification_overrides_ai_without_changing_original_or_creating_an_inquiry(): void
    {
        $this->configure();
        $email = $this->email();
        $intake = app(AiIntakeService::class)->receiveEmail($email, $this->admin);
        $intake->update(['analysis' => ['intent' => 'other', 'classification' => 'unrelated']]);
        $original = $intake->messages()->firstOrFail()->getRawOriginal();
        $this->imap->shouldReceive('readMessage')->once()->andReturn($email);
        $result = app(DispositionMailboxService::class)->classify($this->admin, 'INBOX', 32, 401, 'inquiry');

        $this->assertSame('inquiry', $result['classification']);
        $this->assertSame('manual', $result['classification_source']);
        $this->assertSame($intake->id, $result['intake_id']);
        $this->assertSame(1, DispositionMailboxReview::count());
        $review = DispositionMailboxReview::firstOrFail();
        $this->assertSame($this->admin->id, $review->reviewed_by);
        $this->assertSame(401, $review->uid_validity);
        $this->assertSame(32, $review->uid);
        $this->assertSame(hash('sha256', 'INBOX'), $review->folder_hash);
        $this->assertSame($original, $intake->messages()->firstOrFail()->getRawOriginal());
        $this->assertSame('review', $intake->fresh()->status);
        $this->noSideEffects();
    }

    public function test_not_inquiry_pauses_linked_processing_and_cancels_only_unsent_customer_deliveries(): void
    {
        $this->configure();
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);
        $draft = $this->delivery($intake, 'draft');
        $pending = $this->delivery($intake, 'pending');
        $sent = $this->delivery($intake, 'sent');
        $unknown = $this->delivery($intake, 'unknown');
        $original = $intake->messages()->firstOrFail()->getRawOriginal();
        $this->imap->shouldReceive('readMessage')->once()->andReturn($this->email());

        $result = app(DispositionMailboxService::class)->classify($this->admin, 'INBOX', 32, 401, 'not_inquiry');
        $this->assertSame('not_inquiry', $result['classification']);
        $this->assertSame('paused', $intake->fresh()->status);
        $this->assertSame('canceled', $draft->fresh()->status);
        $this->assertSame('canceled', $pending->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertSame('unknown', $unknown->fresh()->status);
        $this->assertSame($original, $intake->messages()->firstOrFail()->getRawOriginal());
        $this->noSideEffects();
    }

    public function test_relabeling_completed_intake_preserves_completed_status_and_native_business_record(): void
    {
        $this->configure();
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);
        $intake->update(['status' => 'completed']);
        $inquiry = OperationInquiry::create(['channel' => 'email', 'title' => 'Native approved inquiry', 'original' => 'Native original', 'status' => 'new', 'timezone' => 'Europe/Berlin', 'revision' => 1, 'created_by' => $this->admin->id]);
        $proposal = AiIntakeProposal::create(['intake_id' => $intake->id, 'source_revision' => 1, 'position_index' => 0, 'status' => 'applied', 'inquiry_id' => $inquiry->id, 'payload' => ['demand' => [], 'segments' => []]]);
        $before = $inquiry->fresh()->getRawOriginal();
        $this->imap->shouldReceive('readMessage')->once()->andReturn($this->email());

        $result = app(DispositionMailboxService::class)->classify($this->admin, 'INBOX', 32, 401, 'not_inquiry');
        $this->assertSame('not_inquiry', $result['classification']);
        $this->assertSame('completed', $result['intake_status']);
        $this->assertSame('completed', $intake->fresh()->status);
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertSame('applied', $proposal->fresh()->status);
        $this->assertSame($inquiry->id, $proposal->fresh()->inquiry_id);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_import_links_a_manually_reviewed_inquiry_and_repeated_import_keeps_one_original(): void
    {
        $this->configure();
        $this->imap->shouldReceive('fetch')->twice()->withArgs(fn ($settings, $uid, $validity) => $uid === 32 && $validity === 401)->andReturn($this->email());
        $service = app(DispositionMailboxService::class);
        $first = $service->importInquiry($this->admin, 'INBOX', 32, 401);
        $second = $service->importInquiry($this->admin, 'INBOX', 32, 401);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(1, DispositionMailboxReview::count());
        $this->assertSame('inquiry', DispositionMailboxReview::firstOrFail()->classification);
        $this->assertSame($first->id, DispositionMailboxReview::firstOrFail()->intake_id);
        $this->assertSame('review', $first->status);
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->noSideEffects();
    }

    public function test_stale_uidvalidity_and_foreign_folder_cannot_classify_or_import_mail(): void
    {
        $this->configure();
        $service = app(DispositionMailboxService::class);
        $this->imap->shouldReceive('readMessage')->once()->andThrow(new RuntimeException('mailbox_uidvalidity_changed'));
        try {
            $service->classify($this->admin, 'INBOX', 32, 400, 'inquiry');
            $this->fail('A different UIDVALIDITY must not be classified.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mailbox_uidvalidity_changed', $exception->getMessage());
        }
        $this->expectHttpStatus(422, fn () => $service->classify($this->admin, 'Archive', 32, 401, 'inquiry'));
        $this->expectHttpStatus(422, fn () => $service->importInquiry($this->admin, 'Archive', 32, 401));
        $this->imap->shouldReceive('sentFolder')->once()->andReturn('Sent');
        $this->expectHttpStatus(404, fn () => $service->detail($this->admin, 'OtherMailbox', 32, 401));
        $this->assertSame(0, DispositionMailboxReview::count());
        $this->assertSame(0, AiIntake::count());
        $this->noSideEffects();
    }

    public function test_fetched_message_must_match_the_requested_mailbox_uid_and_validity_before_import(): void
    {
        $this->configure();
        foreach ([['mailbox_id' => str_repeat('a', 64)], ['uid' => 999], ['uid_validity' => 999]] as $differentIdentity) {
            $this->imap->shouldReceive('fetch')->once()->andReturn($this->email($differentIdentity));
            $this->expectHttpStatus(409, fn () => app(DispositionMailboxService::class)->importInquiry($this->admin, 'INBOX', 32, 401));
        }
        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, DispositionMailboxReview::count());
        $this->noSideEffects();
    }

    public function test_settings_change_during_mail_server_read_is_rejected_without_persisting_review(): void
    {
        $this->configure();
        $this->imap->shouldReceive('readMessage')->once()->andReturnUsing(function (): array {
            $this->configure(['imap_username' => 'changed@example.test']);

            return $this->email();
        });
        $this->expectHttpStatus(409, fn () => app(DispositionMailboxService::class)->classify($this->admin, 'INBOX', 32, 401, 'inquiry'));
        $this->assertSame(0, DispositionMailboxReview::count());
        $this->noSideEffects();
    }

    public function test_sent_mail_is_listed_from_the_server_advertised_folder_and_absence_is_explicit(): void
    {
        $this->configure();
        $service = app(DispositionMailboxService::class);
        $this->imap->shouldReceive('sentFolder')->once()->andReturn('Sent Items');
        $this->imap->shouldReceive('browse')->once()->withArgs(fn ($settings, $folder, $page, $size, $search) => $folder === 'Sent Items' && $page === 3 && $size === 20 && $search === 'response')->andReturn($this->serverPage([$this->email()], 3, 51, 987, 'Sent Items'));
        $result = $service->sent($this->admin, 3, 'response');
        $this->assertTrue($result['available']);
        $this->assertSame('Sent Items', $result['folder']);
        $this->assertSame(51, $result['total']);
        $this->assertArrayNotHasKey('classification', $result['messages'][0]);
        $this->imap->shouldReceive('sentFolder')->once()->andReturn(null);
        $empty = $service->sent($this->admin);
        $this->assertFalse($empty['available']);
        $this->assertSame([], $empty['messages']);
        $this->noSideEffects();
    }

    public function test_outbox_stays_scoped_to_the_current_mailbox_and_preserves_unknown_send_status(): void
    {
        $this->configure();
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);
        $own = $this->delivery($intake, 'unknown');
        $foreign = $this->delivery($intake, 'draft', ['mailbox_id' => str_repeat('b', 64)]);
        $service = app(DispositionMailboxService::class);

        $outbox = $service->deliveries($this->admin);
        $this->assertSame(1, $outbox->total());
        $this->assertSame([$own->id], $outbox->pluck('id')->all());
        $detail = $service->deliveryDetail($this->admin, $own->id);
        $this->assertSame('unknown', $detail['status']);
        $this->assertSame('Versandausgang prüfen', $detail['status_label']);
        $this->assertSame($own->body, $detail['text']);
        $this->expectException(ModelNotFoundException::class);
        $service->deliveryDetail($this->admin, $foreign->id);
    }

    public function test_connection_failure_is_redacted_and_does_not_expose_credentials(): void
    {
        $this->configure();
        $this->imap->shouldReceive('snapshot')->once()->andThrow(new RuntimeException('synthetic-mailbox-secret at imap.example.test'));
        $connection = app(DispositionMailboxService::class)->connection($this->admin);

        $this->assertFalse($connection['connected']);
        $this->assertStringNotContainsString('synthetic-mailbox-secret', json_encode($connection));
        $this->assertStringNotContainsString('imap.example.test', $connection['error']);
        $this->noSideEffects();
    }

    public function test_service_rechecks_fresh_permissions_and_inactive_actors_for_every_mailbox_action(): void
    {
        $this->configure();
        $service = app(DispositionMailboxService::class);
        $unauthorized = User::factory()->create(['role' => 'guest', 'status' => true]);
        $actions = [
            fn ($actor) => $service->connection($actor),
            fn ($actor) => $service->inbox($actor),
            fn ($actor) => $service->sent($actor),
            fn ($actor) => $service->deliveries($actor),
            fn ($actor) => $service->detail($actor, 'INBOX', 32, 401),
            fn ($actor) => $service->classify($actor, 'INBOX', 32, 401, 'inquiry'),
            fn ($actor) => $service->importInquiry($actor, 'INBOX', 32, 401),
            fn ($actor) => $service->deliveryDetail($actor, 1),
            fn ($actor) => $service->linkedIntake($actor, 1),
        ];
        $this->assertFalse($service->available($unauthorized));
        foreach ($actions as $action) {
            $this->expectHttpStatus(403, fn () => $action($unauthorized));
        }
        User::whereKey($this->admin->id)->update(['status' => false]);
        $this->assertFalse($service->available($this->admin));
        foreach ($actions as $action) {
            $this->expectHttpStatus(403, fn () => $action($this->admin));
        }
        User::whereKey($this->admin->id)->update(['status' => true, 'role' => 'guest']);
        $this->assertFalse($service->available($this->admin));
        foreach ($actions as $action) {
            $this->expectHttpStatus(403, fn () => $action($this->admin));
        }
        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, DispositionMailboxReview::count());
        $this->noSideEffects();
    }

    public function test_customer_embedded_inbox_never_exposes_the_shared_disposition_mailbox(): void
    {
        $this->configure();
        $customer = Customer::create(['company_name' => 'Synthetic customer', 'is_active' => true]);
        Livewire::test(UnifiedInbox::class, ['customerId' => $customer->id])
            ->assertDontSeeLivewire(DispositionMailbox::class)
            ->assertDontSee('openMailbox', false)
            ->call('openMailbox')->assertForbidden();
        $this->noSideEffects();
    }

    public function test_mailbox_component_mount_and_every_action_recheck_current_authorization(): void
    {
        $this->configure();
        $unauthorized = User::factory()->create(['role' => 'guest', 'status' => true]);
        Livewire::actingAs($unauthorized)->test(DispositionMailbox::class)->assertForbidden();
        $actions = [
            ['open'], ['close'], ['setTab', 'inbox'], ['refresh'], ['previousPage'], ['nextPage'],
            ['selectMessage', 32], ['selectDelivery', 1], ['clearSelection'],
            ['classifyMessage', 'inquiry'], ['importSelectedInquiry'], ['openLinkedIntake', 1], ['updatedSearch'],
        ];
        foreach ($actions as $arguments) {
            $this->admin->update(['status' => true]);
            $component = Livewire::actingAs($this->admin)->test(DispositionMailbox::class);
            $this->admin->update(['status' => false]);
            ($arguments[0] === 'updatedSearch' ? $component->set('search', 'new search') : $component->call(...$arguments))->assertForbidden();
        }
        $this->noSideEffects();
    }

    public function test_mailbox_component_rejects_client_injected_locked_message_identity(): void
    {
        $this->configure();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(DispositionMailbox::class)
            ->set('selectedMessage', ['folder' => 'OtherMailbox', 'uid' => 32, 'uid_validity' => 401]);
    }

    private function expectConnectedMailbox(array $messages, int $total = 3): void
    {
        $this->imap->shouldReceive('snapshot')->andReturn(['uid_validity' => 401, 'uid_next' => 33]);
        $this->imap->shouldReceive('browse')->andReturn($this->serverPage($messages, 1, $total));
    }

    public function test_mailbox_detail_escapes_received_content_and_classification_uses_current_selection(): void
    {
        $this->configure();
        $email = $this->email(['text' => '<script>alert("fixture")</script>', 'subject' => '<img src=x onerror=alert(1)>']);
        $this->expectConnectedMailbox([$email]);
        $this->imap->shouldReceive('readMessage')->twice()->andReturn($email);

        Livewire::test(DispositionMailbox::class)
            ->call('open')->assertSet('showMailbox', true)->assertSee('Postfach verbunden')
            ->call('selectMessage', 32)->assertSet('selectedMessage.uid_validity', 401)
            ->assertSee('&lt;script&gt;', false)->assertDontSeeHtml('<script>alert("fixture")</script>')
            ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
            ->call('classifyMessage', 'not_inquiry')->assertHasNoErrors()
            ->assertSet('selectedMessage.classification', 'not_inquiry')
            ->assertDispatched('disposition-mailbox-updated');
        $this->assertSame('not_inquiry', DispositionMailboxReview::firstOrFail()->classification);
        $this->assertSame(0, AiIntake::count());
        $this->noSideEffects();
    }

    public function test_selecting_an_arbitrary_uid_outside_the_current_mailbox_page_is_rejected(): void
    {
        $this->configure();
        $this->expectConnectedMailbox([$this->email()]);
        Livewire::test(DispositionMailbox::class)->call('open')->call('selectMessage', 9999)->assertNotFound();
        $this->noSideEffects();
    }

    public function test_changed_postbox_context_blocks_classification_import_details_and_native_intake_navigation(): void
    {
        $this->configure();
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);
        $initialReviews = DispositionMailboxReview::orderBy('id')->get()->map(fn ($review) => $review->getRawOriginal())->all();
        $reads = 0;
        $this->expectConnectedMailbox([$this->email()], 25);
        $this->imap->shouldReceive('readMessage')->andReturnUsing(function () use (&$reads): array {
            $reads++;

            return $this->email();
        });
        $actions = [
            ['classifyMessage', 'inquiry'], ['importSelectedInquiry'], ['selectMessage', 32],
            ['setTab', 'outbox'], ['nextPage'], ['openLinkedIntake', $intake->id], ['updatedSearch'],
        ];
        foreach ($actions as $arguments) {
            $this->configure();
            $component = Livewire::test(DispositionMailbox::class)->call('open')->call('selectMessage', 32);
            $beforeReads = $reads;
            $this->configure(['imap_username' => 'different-dispatch@example.test']);
            ($arguments[0] === 'updatedSearch' ? $component->set('search', 'new search') : $component->call(...$arguments))
                ->assertHasErrors('mailbox')
                ->assertSet('selectedMessage', [])
                ->assertSet('mailboxPage', [])
                ->assertSet('selectedIntakeId', null)
                ->assertSee('Das Postfach wurde geändert.');
            $this->assertSame($beforeReads, $reads);
        }
        $this->assertSame($initialReviews, DispositionMailboxReview::orderBy('id')->get()->map(fn ($review) => $review->getRawOriginal())->all());
        $this->assertSame(1, AiIntake::count());
        $this->noSideEffects();
    }

    public function test_import_from_modal_opens_existing_native_intake_review_without_queue_or_mail(): void
    {
        $this->configure();
        $this->expectConnectedMailbox([$this->email()]);
        $this->imap->shouldReceive('readMessage')->once()->andReturn($this->email());
        $this->imap->shouldReceive('fetch')->once()->andReturn($this->email());
        $component = Livewire::test(DispositionMailbox::class)
            ->call('open')->call('selectMessage', 32)
            ->call('importSelectedInquiry')->assertHasNoErrors()
            ->assertSet('showMailbox', false)
            ->assertDispatched('ai-intake-submitted')
            ->assertSeeLivewire(AiIntakeInbox::class);

        $this->assertSame(AiIntake::firstOrFail()->id, $component->get('selectedIntakeId'));
        $this->assertSame('review', AiIntake::firstOrFail()->status);
        $this->noSideEffects();
    }

    public function test_polling_skips_manually_excluded_non_inquiry_and_advances_the_cursor(): void
    {
        $settings = $this->configure();
        DispositionMailboxReview::create(['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'folder_hash' => hash('sha256', 'INBOX'), 'uid_validity' => 401, 'uid' => 32, 'classification' => 'not_inquiry', 'reviewed_by' => $this->admin->id]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id]
        ));
        Setting::setValue(AiDispositionSettings::GROUP, 'ai_disposition_mailbox_runtime', ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'uid_validity' => 401, 'cursor_uid' => 31]);
        $this->imap->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 401, 'uid_next' => 33]);
        $this->imap->shouldReceive('uidsAfter')->once()->andReturn(['uids' => [32], 'scan_through' => 32]);
        $this->imap->shouldNotReceive('fetch');

        $runtime = app(AiIntakeMailboxService::class)->poll();
        $this->assertSame('active', $runtime['state']);
        $this->assertSame(32, $runtime['cursor_uid']);
        $this->assertSame(0, $runtime['imported']);
        $this->assertSame(0, AiIntake::count());
        $this->noSideEffects();
    }

    public function test_manually_importing_mail_remains_review_only_even_when_automation_is_enabled(): void
    {
        $this->configure();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id]
        ));
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);

        $this->assertSame('review', $intake->status);
        $this->assertSame('manual_mailbox_review', $intake->error_code);
        $this->assertTrue($intake->messages()->firstOrFail()->metadata['manual_mailbox_capture']);
        $this->assertSame($this->admin->id, $intake->messages()->firstOrFail()->metadata['submitted_by']);
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noSideEffects();
    }

    public function test_additive_migration_indexes_existing_mail_sources_without_changing_their_immutable_content(): void
    {
        $settings = $this->configure();
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);
        $message = $intake->messages()->firstOrFail();
        DB::table('ai_intake_messages')->where('id', $message->id)->update(['mailbox_id' => null]);
        $original = $message->fresh()->getRawOriginal();

        (require database_path('migrations/2026_10_11_150000_create_disposition_mailbox_reviews.php'))->up();
        (require database_path('migrations/2026_10_11_150000_create_disposition_mailbox_reviews.php'))->up();

        $after = $message->fresh()->getRawOriginal();
        $this->assertSame(AiDispositionSettings::mailboxId($settings), $after['mailbox_id']);
        $this->assertSame(array_diff_key($original, ['mailbox_id' => true]), array_diff_key($after, ['mailbox_id' => true]));
        $this->assertSame(1, AiIntakeMessage::count());
        $this->noSideEffects();
    }

    public function test_poll_exclusion_added_while_fetching_prevents_capture_and_continues_the_scan(): void
    {
        Notification::fake();
        $settings = $this->configure();
        $customer = Customer::create(['company_name' => 'Synthetic sender', 'email' => 'customer@example.test', 'is_active' => true]);
        CustomerContact::create(['customer_id' => $customer->id, 'name' => 'Synthetic verified dispatcher', 'email' => 'customer@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id, 'customer_receipt_mode' => 'automatic']
        ));
        $identity = ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'folder_hash' => hash('sha256', 'INBOX'), 'uid_validity' => 401];
        DispositionMailboxReview::create($identity + ['uid' => 33, 'classification' => 'not_inquiry', 'reviewed_by' => $this->admin->id]);
        Setting::setValue(AiDispositionSettings::GROUP, 'ai_disposition_mailbox_runtime', ['mailbox_id' => $identity['mailbox_id'], 'uid_validity' => 401, 'cursor_uid' => 31]);
        $this->imap->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 401, 'uid_next' => 34]);
        $this->imap->shouldReceive('uidsAfter')->once()->andReturn(['uids' => [32, 33], 'scan_through' => 33]);
        $this->imap->shouldReceive('fetch')->once()->withArgs(fn ($freshSettings, $uid, $validity) => $uid === 32 && $validity === 401)->andReturnUsing(function () use ($identity): array {
            DispositionMailboxReview::create($identity + ['uid' => 32, 'classification' => 'not_inquiry', 'reviewed_by' => $this->admin->id]);

            return $this->email();
        });

        $runtime = app(AiIntakeMailboxService::class)->poll();

        $this->assertSame('active', $runtime['state']);
        $this->assertSame(33, $runtime['cursor_uid']);
        $this->assertSame(0, $runtime['imported']);
        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, AiIntakeMessage::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Notification::assertNothingSent();
        $this->noSideEffects();
    }

    public function test_automatic_duplicate_keeps_manually_imported_review_and_native_pause_with_one_original(): void
    {
        Notification::fake();
        $this->configure();
        $email = $this->email();
        $this->imap->shouldReceive('fetch')->once()->andReturn($email);
        $manual = app(DispositionMailboxService::class)->importInquiry($this->admin, 'INBOX', 32, 401);
        $message = $manual->messages()->firstOrFail();
        $original = $message->getRawOriginal();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id]
        ));
        $service = app(AiIntakeService::class);
        $automaticDuplicate = $service->receiveEmail($email);
        $this->assertSame($manual->id, $automaticDuplicate->id);
        $this->assertSame('review', $automaticDuplicate->status);
        $this->assertSame('manual_mailbox_review', $automaticDuplicate->error_code);
        $service->pause($manual, $this->admin, $manual->revision);
        $pausedDuplicate = $service->receiveEmail($email);

        $this->assertSame($manual->id, $pausedDuplicate->id);
        $this->assertSame('paused', $pausedDuplicate->status);
        $this->assertNotNull($pausedDuplicate->paused_at);
        $this->assertSame($original, $message->fresh()->getRawOriginal());
        $this->assertSame($email['raw'], app(AiIntakeAttachmentService::class)->bytes($pausedDuplicate, $message->raw_path, $message->raw_hash));
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Notification::assertNothingSent();
        $this->noSideEffects();
    }

    public function test_failed_import_audit_rolls_back_capture_review_and_only_discards_new_orphan_archives(): void
    {
        Notification::fake();
        $this->configure();
        $existing = app(AiIntakeService::class)->receiveEmail($this->email(['uid' => 31, 'message_id' => '<preserved-original@example.test>']), $this->admin);
        $existingMessage = $existing->messages()->firstOrFail();
        $existingOriginal = $existingMessage->getRawOriginal();
        $existingReviews = DispositionMailboxReview::orderBy('id')->get()->map(fn ($review) => $review->getRawOriginal())->all();
        Storage::disk('local')->put('unrelated-fixture.txt', 'Unrelated file must remain unchanged.');
        $existingPaths = Storage::disk('local')->allFiles();
        sort($existingPaths);
        $this->imap->shouldReceive('fetch')->once()->andReturn($this->email());
        $this->mock(OperationsAuditService::class, fn (MockInterface $mock) => $mock->shouldReceive('record')->once()
            ->withArgs(fn ($review, $actor, $action, $data) => $review instanceof DispositionMailboxReview && $action === 'mailbox.import')
            ->andThrow(new RuntimeException('synthetic-audit-failure')));

        try {
            app(DispositionMailboxService::class)->importInquiry($this->admin, 'INBOX', 32, 401);
            $this->fail('An import with no corresponding audit must be rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-audit-failure', $exception->getMessage());
        }

        $afterPaths = Storage::disk('local')->allFiles();
        sort($afterPaths);
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame($existingReviews, DispositionMailboxReview::orderBy('id')->get()->map(fn ($review) => $review->getRawOriginal())->all());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->assertSame($existingPaths, $afterPaths);
        $this->assertSame($existingOriginal, $existingMessage->fresh()->getRawOriginal());
        $this->assertSame('Unrelated file must remain unchanged.', Storage::disk('local')->get('unrelated-fixture.txt'));
        Notification::assertNothingSent();
        $this->noSideEffects();
    }

    public function test_settings_changed_while_poll_fetches_returns_stale_and_cleans_temporary_files(): void
    {
        Notification::fake();
        $settings = $this->configure();
        $email = $this->email();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id]
        ));
        Setting::setValue(AiDispositionSettings::GROUP, 'ai_disposition_mailbox_runtime', ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'uid_validity' => 401, 'cursor_uid' => 31]);
        $this->imap->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 401, 'uid_next' => 33]);
        $this->imap->shouldReceive('uidsAfter')->once()->andReturn(['uids' => [32], 'scan_through' => 32]);
        $this->imap->shouldReceive('fetch')->once()->andReturnUsing(function () use ($settings, $email): array {
            $temporaryPath = 'mailbox-fetch-fixture.tmp';
            Storage::disk('local')->put($temporaryPath, 'Temporary attachment from the old mailbox.');
            Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
                Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
                ['imap_username' => 'new-mailbox@example.test', 'revision' => $settings['revision'] + 1]
            ));

            return $email + ['_cleanup' => [$temporaryPath]];
        });

        $runtime = app(AiIntakeMailboxService::class)->poll();

        $this->assertSame('stale', $runtime['state']);
        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, AiIntakeMessage::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
        $this->noSideEffects();
    }

    public function test_unreviewed_identity_lock_does_not_override_the_current_ai_classification(): void
    {
        $this->configure();
        $intake = app(AiIntakeService::class)->receiveEmail($this->email(), $this->admin);
        DispositionMailboxReview::firstOrCreate([
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'folder_hash' => hash('sha256', 'INBOX'),
            'uid_validity' => 401, 'uid' => 32,
        ]);
        $intake->update(['analysis' => ['intent' => 'inquiry', 'classification' => 'new_inquiry']]);
        $this->imap->shouldReceive('browse')->once()->andReturn($this->serverPage([$this->email()], 1, 1));

        $result = app(DispositionMailboxService::class)->inbox($this->admin);

        $this->assertNull(DispositionMailboxReview::firstOrFail()->reviewed_by);
        $this->assertSame('inquiry', $result['messages'][0]['classification']);
        $this->assertSame('ai', $result['messages'][0]['classification_source']);
        $this->assertSame($intake->id, $result['messages'][0]['intake_id']);
        $this->noSideEffects();
    }

    private function renderedButton(string $html, string $action): DOMElement
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
            $ariaLabel = ['previousPage' => 'Vorherige Nachrichtenseite', 'nextPage' => 'Nächste Nachrichtenseite'][$action] ?? null;
            $visibleLabel = ["classifyMessage('inquiry')" => 'Anfrage', "classifyMessage('not_inquiry')" => 'Keine Anfrage'][$action] ?? null;
            foreach ((new DOMXPath($document))->query('//button') as $button) {
                if ($button instanceof DOMElement && ($button->getAttribute('wire:click') === $action
                    || ($ariaLabel !== null && $button->getAttribute('aria-label') === $ariaLabel)
                    || ($visibleLabel !== null && trim($button->textContent) === $visibleLabel))) {
                    return $button;
                }
            }
            $this->fail('Rendered mailbox button missing: '.$action);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function test_rendered_pagination_and_classification_buttons_are_disabled_only_when_inapplicable(): void
    {
        $this->configure();
        $this->expectConnectedMailbox([$this->email()], 25);
        $this->imap->shouldReceive('readMessage')->times(3)->andReturn($this->email());
        $component = Livewire::test(DispositionMailbox::class)->call('open');

        $this->assertTrue($this->renderedButton($component->html(), 'previousPage')->hasAttribute('disabled'));
        $this->assertFalse($this->renderedButton($component->html(), 'nextPage')->hasAttribute('disabled'));
        $component->call('selectMessage', 32)->assertSet('selectedMessage.classification', 'unreviewed');
        $this->assertFalse($this->renderedButton($component->html(), "classifyMessage('inquiry')")->hasAttribute('disabled'));
        $this->assertFalse($this->renderedButton($component->html(), "classifyMessage('not_inquiry')")->hasAttribute('disabled'));
        $component->call('classifyMessage', 'inquiry')->assertHasNoErrors()->assertSet('selectedMessage.classification', 'inquiry');
        $this->assertTrue($this->renderedButton($component->html(), "classifyMessage('inquiry')")->hasAttribute('disabled'));
        $this->assertFalse($this->renderedButton($component->html(), "classifyMessage('not_inquiry')")->hasAttribute('disabled'));
        $component->call('classifyMessage', 'unreviewed')->assertHasNoErrors()->assertSet('selectedMessage.classification', 'unreviewed');
        $this->assertFalse($this->renderedButton($component->html(), "classifyMessage('inquiry')")->hasAttribute('disabled'));
        $this->assertFalse($this->renderedButton($component->html(), "classifyMessage('not_inquiry')")->hasAttribute('disabled'));
        $this->noSideEffects();
    }
}
