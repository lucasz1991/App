<?php

namespace Tests\Feature;

use App\Jobs\DeliverAiIntakeMail;
use App\Jobs\ProbeAiDispositionWorker;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Operations\AiDispositionClient;
use App\Services\Operations\AiDispositionSmtpTransport;
use App\Services\Operations\AiIntakeImapClient;
use App\Services\Operations\AiIntakeMailboxService;
use App\Services\Operations\AiIntakeMailService;
use App\Support\Ai\AssistantSettings;
use App\Support\Ai\OpenRouterSettings;
use App\Support\Operations\AiDispositionSettings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\IMAP;

class AiDispositionTransportTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        Queue::fake();
        Storage::fake('local');
        Cache::flush();
        OpenRouterSettings::save(['api_key' => 'synthetic-key', 'api_url' => 'https://openrouter.ai/api/v1/chat/completions', 'text_model' => 'synthetic/text', 'data_model' => 'synthetic/data']);
    }

    private function enable(array $extra = []): void
    {
        AiDispositionSettings::save($extra + ['enabled' => true, 'supervisor_id' => $this->admin->id,
            'imap_host' => 'imap.example.test', 'imap_username' => 'dispatch@example.test', 'imap_password' => 'synthetic-mail-password',
            'smtp_host' => 'smtp.example.test', 'from_address' => 'dispatch@example.test', 'expected_revision' => AiDispositionSettings::all()['revision']], $this->admin);
    }

    private function intake(array $extra = [], array $metadata = []): AiIntake
    {
        $customer = Customer::create(['company_name' => 'Synthetic rail customer', 'is_active' => true]);
        $contact = CustomerContact::create(['customer_id' => $customer->id, 'name' => 'Synthetic contact', 'email' => 'customer@example.test', 'roles' => ['ordering'], 'is_active' => true, 'revision' => 1, 'updated_by' => $this->admin->id]);
        $intake = AiIntake::create($extra + ['source_type' => 'email', 'status' => 'review', 'revision' => 1, 'source_revision' => 1, 'customer_id' => $customer->id, 'customer_contact_id' => $contact->id, 'supervising_user_id' => $this->admin->id, 'match_method' => 'contact_email', 'question_round' => 0]);
        $message = AiIntakeMessage::create(['intake_id' => $intake->id, 'direction' => 'inbound', 'sender_email' => $contact->email, 'external_message_id' => 'request@example.test', 'body' => 'Synthetic partial inquiry', 'metadata' => $metadata]);
        $intake->forceFill(['latest_inbound_message_id' => $message->id])->save();

        return $intake;
    }

    public function test_defaults_remain_disabled_and_passwords_are_encrypted_masked_and_retained(): void
    {
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->enable();
        $stored = Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY);
        $this->assertStringStartsWith('enc:v1:', $stored['imap_password']);
        $this->assertStringNotContainsString('synthetic-mail-password', json_encode($stored));
        $this->assertSame(AiDispositionSettings::SECRET_MASK, AiDispositionSettings::forForm()['imap_password']);
        $this->enable(['imap_password' => AiDispositionSettings::SECRET_MASK]);
        $this->assertSame('synthetic-mail-password', AiDispositionSettings::all()['imap_password']);
        $this->assertSame(3, AiDispositionSettings::all()['revision']);
    }

    public function test_regular_admin_cannot_change_mailbox_even_with_admin_gate_bypass(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $this->expectException(HttpException::class);
        AiDispositionSettings::save(['enabled' => false], $other);
    }

    public function test_stale_settings_revision_is_rejected_without_overwriting(): void
    {
        $this->enable();
        try {
            AiDispositionSettings::save(['expected_revision' => 1, 'from_name' => 'Stale value'], $this->admin);
            $this->fail('Expected stale settings rejection');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('RailTime Disposition', AiDispositionSettings::all()['from_name']);
    }

    public function test_structured_calls_share_credentials_but_work_when_chatbot_disabled(): void
    {
        $this->enable();
        AssistantSettings::setEnabled(false);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['id' => 'synthetic-generation', 'model' => 'synthetic/data', 'choices' => [['message' => ['content' => '{"summary":"synthetic"}']]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18, 'cost' => 0.00012]]))]));
        $stack->push(Middleware::history($history));
        $client = new AiDispositionClient(new OpenRouterChatClient(new Client(['handler' => $stack])));
        $response = $client->structured('intake', [['role' => 'user', 'content' => 'Synthetic request']], ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary'], 'additionalProperties' => false]);
        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('synthetic/data', $sent['model']);
        $this->assertSame('json_schema', $sent['response_format']['type']);
        $this->assertTrue($sent['provider']['require_parameters']);
        $this->assertSame('Bearer synthetic-key', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(18, $response->usage['total_tokens']);
        $this->assertSame(0.00012, $response->costUsd);
        $this->assertSame('synthetic-generation', $response->requestId);
    }

    public function test_existing_complete_contract_does_not_send_schema_without_opt_in(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"choices":[{"message":{"content":"ordinary reply"}}]}')]));
        $stack->push(Middleware::history($history));
        $response = (new OpenRouterChatClient(new Client(['handler' => $stack])))->complete([['role' => 'user', 'content' => 'Hello']], OpenRouterModelProfile::Data);
        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayNotHasKey('response_format', $sent);
        $this->assertSame('ordinary reply', $response->content);
        $this->assertNull($response->costUsd);
    }

    public function test_incomplete_structured_response_is_rejected_and_preferences_do_not_replace_invariants(): void
    {
        $this->enable(['instructions' => 'Sortiere Leistungen chronologisch.']);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"choices":[{"finish_reason":"length","message":{"content":"{}"}}]}')]));
        $stack->push(Middleware::history($history));
        $client = new AiDispositionClient(new OpenRouterChatClient(new Client(['handler' => $stack])));
        try {
            $client->structured('intake', [['role' => 'system', 'content' => 'Never make binding commitments.'], ['role' => 'user', 'content' => 'Synthetic request']], ['type' => 'object']);
            $this->fail('Expected incomplete response rejection');
        } catch (OpenRouterChatException $error) {
            $this->assertSame('incomplete_structured_response', $error->reasonCode);
        }
        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringStartsWith('Never make binding commitments.', $sent['messages'][0]['content']);
        $this->assertStringContainsString('Sortiere Leistungen chronologisch.', $sent['messages'][0]['content']);
        $this->assertStringContainsString('Unvereinbare Wünsche werden ignoriert.', $sent['messages'][0]['content']);
        $this->assertSame('Synthetic request', $sent['messages'][1]['content']);
    }

    public function test_multiple_positions_have_separate_server_questions_without_untrusted_titles(): void
    {
        $this->enable();
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($this->intake(), [
            ['field' => 'starts_at', 'position' => 1, 'title' => 'We confirm your order', 'question' => 'We confirm your order'],
            ['field' => 'starts_at', 'position' => 2, 'title' => 'We confirm another order'],
        ]);
        $this->assertStringContainsString('Leistung 1:', $delivery->body);
        $this->assertStringContainsString('Leistung 2:', $delivery->body);
        $this->assertStringNotContainsString('We confirm', $delivery->body);
    }

    public function test_position_labels_come_from_current_evidenced_proposal_fields(): void
    {
        $this->enable();
        $intake = $this->intake();
        AiIntakeProposal::create(['intake_id' => $intake->id, 'position_index' => 0, 'source_revision' => $intake->source_revision, 'payload' => ['demand' => ['role_name' => 'Tf', 'location_name' => 'Hamburg'], 'evidence' => ['role_name' => ['message_id' => $intake->latest_inbound_message_id, 'quote' => 'Tf'], 'location_name' => ['message_id' => $intake->latest_inbound_message_id, 'quote' => 'Hamburg']]]]);
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake, [['field' => 'starts_at', 'position' => 1, 'title' => 'Invented title']]);
        $this->assertStringContainsString('Funktion: „Tf“', $delivery->body);
        $this->assertStringContainsString('Ort: „Hamburg“', $delivery->body);
        $this->assertStringNotContainsString('Invented title', $delivery->body);
    }

    public function test_fetch_archives_exact_rfc822_literal_without_reconstructing_separator(): void
    {
        $raw = "From: customer@example.test\r\nSubject: Exact original\r\nContent-Type: text/plain\r\n\r\n\r\nOriginal body\r\n";
        $size = Mockery::mock(\Webklex\PHPIMAP\Connection\Protocols\Response::class);
        $size->shouldReceive('validatedData')->once()->andReturn([8 => strlen($raw)]);
        $literal = Mockery::mock(\Webklex\PHPIMAP\Connection\Protocols\Response::class);
        $literal->shouldReceive('validatedData')->once()->andReturn([8 => $raw]);
        $protocol = Mockery::mock(ProtocolInterface::class);
        $protocol->shouldReceive('sizes')->with([8], IMAP::ST_UID)->once()->andReturn($size);
        $protocol->shouldReceive('fetch')->with(['RFC822'], [8], null, IMAP::ST_UID)->once()->andReturn($literal);
        $client = Mockery::mock(\Webklex\PHPIMAP\Client::class);
        $client->shouldReceive('checkFolder')->with('INBOX')->once()->andReturn(['uidvalidity' => 42, 'uidnext' => 9]);
        $client->shouldReceive('setActiveFolder')->with('INBOX')->once();
        $client->shouldReceive('getConnection')->andReturn($protocol);
        $client->shouldReceive('disconnect')->once();
        $imap = new class($client) extends AiIntakeImapClient
        {
            public function __construct(private readonly \Webklex\PHPIMAP\Client $client) {}

            protected function connect(array $settings): \Webklex\PHPIMAP\Client
            {
                return $this->client;
            }
        };
        $result = $imap->fetch(AiDispositionSettings::DEFAULTS, 8, 42);
        $this->assertSame($raw, $result['raw']);
        $this->assertSame('Original body', $result['text']);
    }

    public function test_stale_poller_cannot_restore_cursor_after_settings_change(): void
    {
        $this->enable();
        $imap = Mockery::mock(AiIntakeImapClient::class);
        $imap->shouldReceive('snapshot')->once()->andReturnUsing(function (): array {
            AiDispositionSettings::save(['enabled' => false], $this->admin);

            return ['uid_validity' => 42, 'uid_next' => 101, 'messages' => 100];
        });
        (new AiIntakeMailboxService($imap, app(AiDispositionSmtpTransport::class)))->poll();
        $runtime = Setting::getValueUncached(AiDispositionSettings::GROUP, 'ai_disposition_mailbox_runtime');
        $this->assertSame('activation_pending', $runtime['state']);
        $this->assertArrayNotHasKey('cursor_uid', $runtime);
        $this->assertFalse(AiDispositionSettings::enabled());
    }

    public function test_scheduler_expires_abandoned_analysis_without_provider_retry_even_when_disabled(): void
    {
        $this->enable();
        $intake = $this->intake(['status' => 'analyzing']);
        $oldRun = AiIntakeRun::create(['intake_id' => $intake->id, 'kind' => 'analysis', 'status' => 'running', 'source_revision' => 1, 'settings_revision' => 2, 'supervising_user_id' => $this->admin->id, 'input_hash' => str_repeat('a', 64), 'started_at' => now()->utc()->subMinutes(16)]);
        $current = $this->intake(['status' => 'analyzing', 'source_revision' => 2]);
        $obsolete = AiIntakeRun::create(['intake_id' => $current->id, 'kind' => 'analysis', 'status' => 'running', 'source_revision' => 1, 'settings_revision' => 2, 'supervising_user_id' => $this->admin->id, 'input_hash' => str_repeat('b', 64), 'started_at' => now()->utc()->subMinutes(20)]);
        $newRun = AiIntakeRun::create(['intake_id' => $current->id, 'kind' => 'analysis', 'status' => 'running', 'source_revision' => 2, 'settings_revision' => 2, 'supervising_user_id' => $this->admin->id, 'input_hash' => str_repeat('c', 64), 'started_at' => now()->utc()->subMinutes(1)]);
        AiDispositionSettings::save(['enabled' => false], $this->admin);
        $this->artisan('operations:ai-intake-poll')->assertSuccessful();
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('worker_run_outcome_unknown', $intake->fresh()->error_code);
        $this->assertSame('failed', $oldRun->fresh()->status);
        $this->assertSame('failed', $obsolete->fresh()->status);
        $this->assertSame('analyzing', $current->fresh()->status);
        $this->assertSame('running', $newRun->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_ambiguous_reply_to_is_preserved_as_manual_review_error(): void
    {
        $raw = "From: customer@example.test\r\nReply-To: customer@example.test, other@example.test\r\nTo: dispatch@example.test\r\nSubject: Synthetic ambiguous mail\r\nContent-Type: text/plain\r\n\r\nSynthetic source";
        $message = (new AiIntakeImapClient)->parse($raw, AiDispositionSettings::DEFAULTS, 8, 42);
        $this->assertSame('mailbox_sender_ambiguous', $message['source_error']);
        $this->assertSame('ambiguous', $message['reply_to']);
    }

    public function test_clarification_is_deduplicated_queued_after_commit_and_fixed_server_content(): void
    {
        $this->enable();
        $intake = $this->intake();
        $service = app(AiIntakeMailService::class);
        $first = $service->enqueueClarification($intake, ['starts_at', 'role_name', 'We confirm your order at €1']);
        $second = $service->enqueueClarification($intake, ['starts_at']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiIntakeDelivery::count());
        $this->assertSame(1, $intake->fresh()->question_round);
        $this->assertStringNotContainsString('€1', $first->body);
        $this->assertStringContainsString('noch keine verbindliche Auftragsbestätigung', $first->body);
        Queue::assertPushed(DeliverAiIntakeMail::class, fn ($job) => $job->afterCommit && $job->connection === 'ai_disposition' && $job->queue === 'ai-disposition');
        $this->assertSame('customer@example.test', $first->recipient_email);
        $this->assertSame('request@example.test', $first->in_reply_to);
    }

    public function test_pause_disable_revision_and_recipient_change_cancel_pending_delivery(): void
    {
        $this->enable();
        $intake = $this->intake();
        $service = app(AiIntakeMailService::class);
        $delivery = $service->enqueueClarification($intake, ['starts_at']);
        $intake->contact->update(['email' => 'other@example.test', 'revision' => 2]);
        $transport = Mockery::mock(AiDispositionSmtpTransport::class);
        $transport->shouldNotReceive('send');
        $this->assertSame('canceled', (new AiIntakeMailService($transport))->deliver($delivery->id));
        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    public function test_unknown_send_result_is_never_retried_and_success_persists_thread(): void
    {
        $this->enable();
        $intake = $this->intake();
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake, ['starts_at']);
        $transport = Mockery::mock(AiDispositionSmtpTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('synthetic SMTP timeout'));
        $service = new AiIntakeMailService($transport);
        $this->assertSame('unknown', $service->deliver($delivery->id));
        $this->assertSame('unknown', $service->deliver($delivery->id));
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame('transport_outcome_unknown', $delivery->fresh()->failure_code);
    }

    public function test_successful_delivery_persists_outbound_message_and_second_job_does_not_resend(): void
    {
        $this->enable();
        $intake = $this->intake();
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake, ['starts_at']);
        $transport = Mockery::mock(AiDispositionSmtpTransport::class);
        $transport->shouldReceive('send')->once();
        $service = new AiIntakeMailService($transport);
        $this->assertSame('sent', $service->deliver($delivery->id));
        $this->assertSame('sent', $service->deliver($delivery->id));
        $outbound = $intake->messages()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame($delivery->message_id_header, $outbound->external_message_id);
        $this->assertSame('dispatch@example.test', $outbound->sender_email);
    }

    public function test_stale_pause_settings_disable_and_supervisor_revocation_prevent_send(): void
    {
        $this->enable();
        $intake = $this->intake();
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake, ['starts_at']);
        $intake->update(['paused_at' => now(), 'status' => 'paused', 'revision' => 3]);
        $transport = Mockery::mock(AiDispositionSmtpTransport::class);
        $transport->shouldNotReceive('send');
        $service = new AiIntakeMailService($transport);
        $this->assertSame('canceled', $service->deliver($delivery->id));

        $intake->update(['paused_at' => null, 'status' => 'review', 'source_revision' => 2]);
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake->fresh(), ['ends_at']);
        AiDispositionSettings::save(['enabled' => false], $this->admin);
        $this->assertSame('canceled', $service->deliver($delivery->id));
        $this->enable();
        $intake->refresh()->update(['status' => 'review', 'source_revision' => 3, 'question_round' => 0]);
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake->fresh(), ['ends_at']);
        $this->admin->update(['status' => false]);
        $this->assertSame('canceled', $service->deliver($delivery->id));
    }

    public function test_actual_transport_builds_isolated_mailer_and_thread_headers_without_network(): void
    {
        $this->enable();
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($this->intake(), ['starts_at']);
        $global = config('mail');
        $mailer = app(MailManager::class)->build(['transport' => 'array']);
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->withArgs(fn ($config) => $config['host'] === 'smtp.example.test' && $config['require_tls'] && $config['username'] === 'dispatch@example.test')->andReturn($mailer);
        $this->app->instance(MailManager::class, $manager);
        (new AiDispositionSmtpTransport)->send($delivery, AiDispositionSettings::all());
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('dispatch@example.test', $sent->getFrom()[0]->getAddress());
        $this->assertSame('customer@example.test', $sent->getTo()[0]->getAddress());
        $this->assertSame('auto-replied', $sent->getHeaders()->get('Auto-Submitted')->getBodyAsString());
        $this->assertStringContainsString('request@example.test', $sent->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame($global, config('mail'));
    }

    public function test_mail_reply_timeout_routes_to_review_without_sending_another_question(): void
    {
        $this->enable();
        $intake = $this->intake();
        $delivery = app(AiIntakeMailService::class)->enqueueClarification($intake, ['starts_at']);
        $delivery->update(['status' => 'sent', 'sent_at' => now()->utc()->subHours(47)]);
        $this->assertSame(0, app(AiIntakeMailService::class)->expireAwaitingReplies());
        $this->assertSame('waiting_customer', $intake->fresh()->status);
        $delivery->update(['status' => 'sent', 'sent_at' => now()->utc()->subHours(49)]);
        $count = app(AiIntakeMailService::class)->expireAwaitingReplies();
        $this->assertSame(1, $count);
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('customer_reply_timeout', $intake->fresh()->error_code);
        $this->assertSame(1, AiIntakeDelivery::count());
    }

    public function test_no_auto_reply_for_unverified_customer_or_mail_loops(): void
    {
        $this->enable();
        $intake = $this->intake(['match_method' => 'manual']);
        $this->assertNull(app(AiIntakeMailService::class)->enqueueClarification($intake, ['starts_at']));
        $loop = $this->intake([], ['auto_submitted' => 'auto-generated']);
        $this->assertNull(app(AiIntakeMailService::class)->enqueueClarification($loop, ['starts_at']));
        Queue::assertNotPushed(DeliverAiIntakeMail::class);
    }

    public function test_dedicated_smtp_uses_separate_credentials_and_keeps_global_mail_config(): void
    {
        $this->enable(['smtp_same_credentials' => false, 'smtp_username' => 'separate@example.test', 'smtp_password' => 'synthetic-separate-password']);
        $global = config('mail');
        $config = app(AiDispositionSmtpTransport::class)->configuration(AiDispositionSettings::all());
        $this->assertSame('separate@example.test', $config['username']);
        $this->assertSame('synthetic-separate-password', $config['password']);
        $this->assertTrue($config['require_tls']);
        $this->assertSame($global, config('mail'));
    }

    public function test_first_poll_captures_baseline_without_import_and_uid_validity_change_pauses(): void
    {
        $this->enable();
        $imap = Mockery::mock(AiIntakeImapClient::class);
        $imap->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 42, 'uid_next' => 101, 'messages' => 100]);
        $imap->shouldNotReceive('fetch');
        $service = new AiIntakeMailboxService($imap, app(AiDispositionSmtpTransport::class));
        $result = $service->poll();
        $this->assertSame(100, $result['cursor_uid']);
        $this->assertSame(0, AiIntake::count());
        $next = Mockery::mock(AiIntakeImapClient::class);
        $next->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 43, 'uid_next' => 106, 'messages' => 105]);
        $next->shouldNotReceive('fetch');
        $result = (new AiIntakeMailboxService($next, app(AiDispositionSmtpTransport::class)))->poll();
        $this->assertSame('uid_validity_changed', $result['state']);
        $this->assertSame(100, $result['cursor_uid']);
    }

    public function test_poll_continues_after_a_quarantined_source_and_advances_cursor_over_uid_gaps(): void
    {
        $this->enable();
        $mailbox = AiDispositionSettings::mailboxId();
        Setting::setValue(AiDispositionSettings::GROUP, 'ai_disposition_mailbox_runtime', ['mailbox_id' => $mailbox, 'uid_validity' => 42, 'cursor_uid' => 100, 'state' => 'active']);
        $imap = Mockery::mock(AiIntakeImapClient::class);
        $imap->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 42, 'uid_next' => 105, 'messages' => 2]);
        $imap->shouldReceive('uidsAfter')->once()->andReturn(['uids' => [101, 103], 'scan_through' => 104]);
        $imap->shouldReceive('fetch')->with(Mockery::type('array'), 101, 42)->once()->andReturn(['mailbox_id' => $mailbox, 'uid_validity' => 42, 'uid' => 101, 'from' => 'unknown@example.test', 'text' => '', 'raw' => 'Synthetic header-only source', 'source_error' => 'mailbox_message_size', 'raw_archive_complete' => false, 'declared_size' => 40000000]);
        $imap->shouldReceive('fetch')->with(Mockery::type('array'), 103, 42)->once()->andReturn(['mailbox_id' => $mailbox, 'uid_validity' => 42, 'uid' => 103, 'from' => 'unknown@example.test', 'text' => 'Synthetic later inquiry', 'raw' => 'Synthetic later original']);
        $result = (new AiIntakeMailboxService($imap, app(AiDispositionSmtpTransport::class)))->poll();
        $this->assertSame('active', $result['state']);
        $this->assertSame(104, $result['cursor_uid']);
        $this->assertSame(2, AiIntake::count());
        $quarantined = AiIntake::oldest('id')->first();
        $this->assertSame('review', $quarantined->status);
        $this->assertSame('mailbox_message_size', $quarantined->error_code);
        $this->assertFalse($quarantined->messages()->first()->metadata['raw_archive_complete']);
    }

    public function test_read_only_connection_probe_never_sends_and_rechecks_current_actor(): void
    {
        $this->enable();
        $imap = Mockery::mock(AiIntakeImapClient::class);
        $imap->shouldReceive('snapshot')->once()->andReturn(['uid_validity' => 42, 'uid_next' => 2, 'messages' => 1]);
        $smtp = Mockery::mock(AiDispositionSmtpTransport::class);
        $smtp->shouldReceive('probe')->once()->andReturn(['authenticated' => true, 'tls' => true]);
        $smtp->shouldNotReceive('send');
        $service = new AiIntakeMailboxService($imap, $smtp);
        $result = $service->probe($this->admin);
        $this->assertSame('authenticated', $result['imap']['state']);
        $this->assertSame('authenticated', $result['smtp']['state']);
        User::whereKey($this->admin->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        $service->probe($this->admin);
    }

    public function test_preview_import_is_explicit_revision_bound_and_worker_proof_is_bounded(): void
    {
        $this->enable();
        $imap = Mockery::mock(AiIntakeImapClient::class);
        $imap->shouldReceive('recent')->once()->andReturn(['uid_validity' => 42, 'messages' => [['uid' => 9, 'subject' => 'Synthetic old mail', 'date' => '2026-10-01']]]);
        $service = new AiIntakeMailboxService($imap, app(AiDispositionSmtpTransport::class));
        $preview = $service->previewRecent($this->admin);
        $this->assertSame(['queued' => 1], $service->importPreview($this->admin, $preview['token'], [9]));
        $this->assertSame(0, AiIntake::count());
        $service->probeWorker($this->admin);
        Queue::assertPushed(ProbeAiDispositionWorker::class, function ($job): bool {
            $job->handle();

            return true;
        });
        $this->assertSame('ai_disposition', $service->status()['worker']['connection']);
        $this->travel(6)->minutes();
        $this->assertSame([], $service->status()['worker']);
    }

    public function test_offline_mime_parser_has_no_flag_or_mailbox_side_effects(): void
    {
        $raw = "From: Known <customer@example.test>\r\nTo: dispatch@example.test\r\nSubject: Synthetic inquiry\r\nMessage-ID: <synthetic@example.test>\r\nAuto-Submitted: no\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSynthetic source only";
        $message = (new AiIntakeImapClient)->parse($raw, AiDispositionSettings::DEFAULTS, 8, 42);
        $this->assertSame('customer@example.test', $message['from']);
        $this->assertSame('synthetic@example.test', $message['message_id']);
        $this->assertSame('no', $message['auto_submitted']);
        $this->assertSame($raw, $message['raw']);
        $this->assertSame(8, $message['uid']);
        $this->assertSame([], $message['attachments']);
    }
}
