<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Ai\OpenRouterToolDecision;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeCustomerContextService;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeHistoryToolService;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeHistoryToolTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('history-tool-'.getmypid()));
        config(['view.compiled' => sys_get_temp_dir().'/railtime-history-tool-'.getmypid()]);
        Cache::flush();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => false, 'supervisor_id' => null, 'revision' => 5, 'imap_host' => 'imap.example.test',
                'imap_username' => 'dispatch@example.test', 'from_address' => 'dispatch@example.test']));
    }

    private function capture(): AiIntake
    {
        $body = 'Synthetische Prüfung Münster. Absender Bahn Nord, Kopie Bahn Süd. Keine Kundenzuordnung bestätigt.';

        return app(AiIntakeService::class)->receiveEmail([
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 401, 'uid' => 37,
            'from' => 'kunde@nord.example.test', 'reply_to' => 'kunde@nord.example.test', 'subject' => 'Synthetic review Münster',
            'message_id' => 'synthetic-history@example.test', 'text' => $body,
            'raw' => "From: kunde@nord.example.test\r\nCc: kunde@sued.example.test\r\nTo: dispatch@example.test\r\nSubject: Synthetic review Münster\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body,
        ], $this->actor)->fresh();
    }

    private function history(int $customerId, array $changes = []): array
    {
        return array_replace(['customer' => ['id' => $customerId, 'name' => 'Synthetic customer '.$customerId],
            'orders' => [['id' => 100 + $customerId, 'title' => 'Historical service only']], 'related_mails' => [],
            'provenance' => ['kind' => 'historical_hint']], $changes);
    }

    private function helper(array $customerIds = [11, 12]): MockInterface
    {
        $helper = $this->mock(AiIntakeCustomerContextService::class);
        $helper->shouldReceive('candidates')->once()->andReturn(array_map(fn ($id) => [
            'customer_id' => $id, 'name' => 'Synthetic candidate '.$id, 'reasons' => ['sender_email'],
        ], $customerIds));

        return $helper;
    }

    private function provider(): MockInterface
    {
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->with(OpenRouterModelProfile::Data)->andReturnTrue();

        return $provider;
    }

    private function decision(int $customerId, string $callId = 'synthetic-call'): OpenRouterToolDecision
    {
        return new OpenRouterToolDecision(null, [['id' => $callId, 'type' => 'function', 'function' => [
            'name' => 'lookup_customer_intake_history', 'arguments' => json_encode(['customer_id' => $customerId], JSON_THROW_ON_ERROR),
        ]]]);
    }

    /** Execute lookup inside an actual server-created current manual run, outside a transaction. */
    private function lookup(AiIntake $intake, array $messages = [], ?callable $beforeLookup = null, bool $automatic = false): array
    {
        $result = null;
        $this->mock(AiIntakeExtractionService::class)->shouldReceive('extract')->once()->andReturnUsing(function (AiIntake $current, ?AiIntakeRun $run = null) use (&$result, $messages, $beforeLookup): array {
            $this->assertSame(0, DB::transactionLevel());
            $beforeLookup?->__invoke($current, $run);
            $result = app(AiIntakeHistoryToolService::class)->lookup($current,
                $messages ?: [['role' => 'user', 'content' => 'Synthetic received source']], $run);

            return ['result' => ['intent' => 'other', 'classification' => 'unrelated', 'extraction_complete' => true, 'overflow' => false,
                'title' => 'Synthetic review', 'summary' => 'Synthetic review only', 'confidence' => .8,
                'positions' => [], 'questions' => [], 'customer_draft' => null], 'context_hints' => $result];
        });
        if ($automatic) {
            app(AiIntakeService::class)->analyze($intake->id);

            return $result ?? [];
        } else {
            app(AiIntakeService::class)->analyzeManually($intake, $this->actor, $intake->revision, 5);
        }

        $this->assertIsArray($result);

        return $result;
    }

    private function noBusinessChanges(AiIntake $intake): void
    {
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertNull($intake->fresh()->customer_contact_id);
        $this->assertSame(0, $intake->proposals()->count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_real_sender_cc_candidates_and_selected_history_are_readonly_non_evidence(): void
    {
        $north = Customer::create(['company_name' => 'Bahn Nord', 'email' => 'kunde@nord.example.test', 'is_active' => true]);
        $south = Customer::create(['company_name' => 'Bahn Süd', 'email' => 'kunde@sued.example.test', 'is_active' => true]);
        $foreign = Customer::create(['company_name' => 'Foreign customer', 'email' => 'foreign@other.example.test', 'is_active' => true]);
        $order = Order::create(['customer_id' => $north->id, 'title' => 'Synthetische Vorleistung Münster', 'location_name' => 'Münster',
            'service_type' => 'Tf', 'starts_at' => '2026-09-08T08:00', 'ends_at' => '2026-09-08T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 9]);
        Order::create(['customer_id' => $foreign->id, 'title' => 'DO NOT EXPOSE FOREIGN ORDER', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-09-08T08:00', 'ends_at' => '2026-09-08T16:00']);
        $intake = $this->capture();
        // Start the decision with no customer assignment even though capture can match an exact sender.
        $intake->update(['customer_id' => null, 'customer_contact_id' => null, 'match_method' => null]);
        $sourceBefore = $intake->latestInboundMessage->getRawOriginal();
        $rawBefore = app(AiIntakeAttachmentService::class)->bytes($intake, $intake->latestInboundMessage->raw_path, $intake->latestInboundMessage->raw_hash);
        $orderBefore = $order->fresh()->getRawOriginal();
        $calls = 0;
        $this->provider()->shouldReceive('completeToolDecision')->twice()->andReturnUsing(function (array $messages, array $tools, OpenRouterModelProfile $profile) use ($north, $south, $foreign, &$calls): OpenRouterToolDecision {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(OpenRouterModelProfile::Data, $profile);
            $this->assertSame([$north->id, $south->id], $tools[0]['function']['parameters']['properties']['customer_id']['enum']);
            $this->assertFalse($tools[0]['function']['parameters']['additionalProperties']);
            $input = json_decode($messages[1]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['Received synthetic Münster original'], $input['current_received_context']);
            $this->assertSame([$north->id, $south->id], array_column($input['allowed_customer_candidates'], 'customer_id'));
            $this->assertNotContains($foreign->id, array_column($input['allowed_customer_candidates'], 'customer_id'));
            $this->assertStringNotContainsString('DO NOT EXPOSE FOREIGN ORDER', json_encode($messages, JSON_THROW_ON_ERROR));
            if ($calls++ === 0) {
                return $this->decision($north->id);
            }
            $this->assertSame('assistant', $messages[2]['role']);
            $this->assertSame('tool', $messages[3]['role']);
            $toolResult = json_decode($messages[3]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('historical_hint', $toolResult['provenance']['kind']);
            $this->assertSame(9, $toolResult['orders'][0]['required_staff']);

            return new OpenRouterToolDecision('No more history needed', []);
        });

        $result = $this->lookup($intake, [
            ['role' => 'system', 'content' => 'DO NOT COPY SYSTEM AS RECEIVED SOURCE'],
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'DO NOT COPY IMAGE URL']],
                ['type' => 'text', 'text' => 'Received synthetic Münster original']]],
        ]);

        $this->assertSame(2, $result['tool_decisions']);
        $this->assertSame([$north->id], $result['used_customer_ids']);
        $this->assertSame($order->id, $result['history'][0]['orders'][0]['id']);
        $this->assertSame('historical_hint', $result['provenance']['kind']);
        $this->assertFalse($result['provenance']['original_evidence']);
        $this->assertFalse($result['provenance']['automatic_assignment']);
        $this->assertSame($intake->id, $result['provenance']['intake_id']);
        $this->assertSame($this->actor->id, $result['provenance']['actor_id']);
        $this->assertSame(AiIntakeRun::sole()->id, $result['provenance']['run_id']);
        $this->assertSame($sourceBefore, $intake->latestInboundMessage->fresh()->getRawOriginal());
        $this->assertSame($rawBefore, app(AiIntakeAttachmentService::class)->bytes($intake, $intake->latestInboundMessage->raw_path, $intake->latestInboundMessage->raw_hash));
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame('succeeded', AiIntakeRun::sole()->status);
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->noBusinessChanges($intake);
    }

    public function test_empty_candidate_set_calls_no_provider_and_consumes_no_budget(): void
    {
        $intake = $this->capture();
        $this->helper([])->shouldNotReceive('history');
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('completeToolDecision');

        $result = $this->lookup($intake);

        $this->assertSame([], $result['history']);
        $this->assertSame([], $result['used_customer_ids']);
        $this->assertSame(0, $result['tool_decisions']);
        $this->assertSame(0, RateLimiter::attempts('operations-ai:hour:'.now()->utc()->format('YmdH')));
        $this->noBusinessChanges($intake);
    }

    public function test_model_can_decline_history_without_any_read_or_assignment(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldNotReceive('history');
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturn(new OpenRouterToolDecision('The original is sufficient', []));

        $result = $this->lookup($intake);

        $this->assertSame(1, $result['tool_decisions']);
        $this->assertSame([], $result['history']);
        $this->assertSame([], $result['used_customer_ids']);
        $this->noBusinessChanges($intake);
    }

    public function test_unbounded_candidate_list_is_rejected_before_provider(): void
    {
        $intake = $this->capture();
        $this->helper(range(1, 11))->shouldNotReceive('history');
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('completeToolDecision');
        try {
            $this->lookup($intake);
            $this->fail('An unbounded customer scope was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->noBusinessChanges($intake);
    }

    public function test_data_provider_configuration_is_required_before_any_read_or_budget_consumption(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldNotReceive('history');
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->once()->with(OpenRouterModelProfile::Data)->andReturnFalse();
        $provider->shouldNotReceive('completeToolDecision');
        try {
            $this->lookup($intake);
            $this->fail('History used an unavailable Data profile.');
        } catch (OpenRouterChatException $error) {
            $this->assertSame('disposition_not_configured', $error->reasonCode);
        }
        $this->assertSame(0, RateLimiter::attempts('operations-ai:hour:'.now()->utc()->format('YmdH')));
        $this->noBusinessChanges($intake);
    }

    public function test_arbitrary_intake_without_a_current_run_cannot_request_history(): void
    {
        $intake = $this->capture();
        $this->mock(AiIntakeCustomerContextService::class)->shouldNotReceive('candidates');
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('completeToolDecision');
        try {
            app(AiIntakeHistoryToolService::class)->lookup($intake, [['role' => 'user', 'content' => 'Synthetic source']]);
            $this->fail('An intake without its current run was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBusinessChanges($intake);
    }

    public function test_two_decisions_limit_distinct_reads_and_feed_first_result_back_to_model(): void
    {
        $intake = $this->capture();
        $helper = $this->helper();
        $helper->shouldReceive('history')->once()->withArgs(fn ($current, $actor, $id) => $current->id === $intake->id && $actor->id === $this->actor->id && $id === 11)->andReturn($this->history(11));
        $helper->shouldReceive('history')->once()->withArgs(fn ($current, $actor, $id) => $id === 12)->andReturn($this->history(12));
        $this->provider()->shouldReceive('completeToolDecision')->twice()->andReturn($this->decision(11, 'first'), $this->decision(12, 'second'));

        $result = $this->lookup($intake);

        $this->assertSame(2, $result['tool_decisions']);
        $this->assertSame([11, 12], $result['used_customer_ids']);
        $this->assertCount(2, $result['history']);
        $this->assertSame(2, RateLimiter::attempts('operations-ai:hour:'.now()->utc()->format('YmdH')));
        $this->noBusinessChanges($intake);
    }

    public function test_repeated_candidate_request_does_not_repeat_the_history_read(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldReceive('history')->once()->andReturn($this->history(11));
        $this->provider()->shouldReceive('completeToolDecision')->twice()->andReturn($this->decision(11, 'first'), $this->decision(11, 'duplicate'));

        $result = $this->lookup($intake);

        $this->assertSame([11], $result['used_customer_ids']);
        $this->assertCount(1, $result['history']);
        $this->assertSame(2, $result['tool_decisions']);
        $this->noBusinessChanges($intake);
    }

    public static function invalidArguments(): array
    {
        return [
            'foreign customer' => ['{"customer_id":99}', 'lookup_customer_intake_history'],
            'string id' => ['{"customer_id":"11"}', 'lookup_customer_intake_history'],
            'extra arguments' => ['{"customer_id":11,"send":true}', 'lookup_customer_intake_history'],
            'list instead of object' => ['[11]', 'lookup_customer_intake_history'],
            'malformed json' => ['{broken', 'lookup_customer_intake_history'],
            'unavailable mutation tool' => ['{"customer_id":11}', 'create_order'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_untrusted_tool_arguments_are_rejected_before_any_history_read(string $arguments, string $name): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldNotReceive('history');
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturn(new OpenRouterToolDecision(null, [
            ['id' => 'bad-request', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]],
        ]));
        try {
            $this->lookup($intake);
            $this->fail('Unsafe tool arguments were accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('history', $error->errors());
        }
        $this->assertSame('failed', AiIntakeRun::sole()->status);
        $this->assertNull(AiIntakeRun::sole()->result);
        $this->noBusinessChanges($intake);
    }

    public static function changedContexts(): array
    {
        return array_combine(['source_revision', 'body', 'raw_hash', 'metadata', 'proposal', 'settings', 'actor', 'run_canceled', 'expired', 'paused'],
            array_map(fn ($change) => [$change], ['source_revision', 'body', 'raw_hash', 'metadata', 'proposal', 'settings', 'actor', 'run_canceled', 'expired', 'paused']));
    }

    #[DataProvider('changedContexts')]
    public function test_changed_context_during_provider_decision_prevents_any_history_read(string $change): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldNotReceive('history');
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturnUsing(function () use ($intake, $change): OpenRouterToolDecision {
            $current = $intake->fresh();
            match ($change) {
                'source_revision' => $current->increment('source_revision'),
                // Simulate concurrent archive/database replacement below the immutable model boundary.
                'body' => DB::table('ai_intake_messages')->where('id', $current->latest_inbound_message_id)->update(['body' => Crypt::encryptString('Changed source during provider')]),
                'raw_hash' => DB::table('ai_intake_messages')->where('id', $current->latest_inbound_message_id)->update(['raw_hash' => hash('sha256', 'changed raw')]),
                'metadata' => DB::table('ai_intake_messages')->where('id', $current->latest_inbound_message_id)->update(['metadata' => Crypt::encryptString(json_encode($current->latestInboundMessage->metadata + ['changed' => true], JSON_THROW_ON_ERROR))]),
                'proposal' => AiIntakeProposal::create(['intake_id' => $current->id, 'source_revision' => $current->source_revision, 'position_index' => 0, 'revision' => 1, 'status' => 'proposed', 'payload' => []]),
                'settings' => Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::all(true), ['revision' => 6])),
                'actor' => $this->actor->update(['status' => false]),
                'run_canceled' => AiIntakeRun::sole()->update(['status' => 'canceled']),
                'expired' => AiIntakeRun::sole()->update(['started_at' => now()->utc()->subMinutes(16)]),
                'paused' => $current->update(['status' => 'paused', 'paused_at' => now()->utc()]),
            };

            return $this->decision(11);
        });
        try {
            $this->lookup($intake);
            $this->fail('A changed history context was accepted.');
        } catch (HttpException $error) {
            $this->assertSame($change === 'actor' ? 403 : 409, $error->getStatusCode(), 'Changed context: '.$change.'; '.$error->getMessage());
        } catch (AuthorizationException) {
            $this->assertSame('actor', $change);
        }
        $this->assertSame($change === 'run_canceled' ? 'canceled' : 'failed', AiIntakeRun::sole()->status);
        $this->assertNull(AiIntakeRun::sole()->result);
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_history_read_mutating_the_snapshot_discards_result_before_a_second_decision(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldReceive('history')->once()->andReturnUsing(function () use ($intake): array {
            DB::table('ai_intake_messages')->where('id', $intake->latest_inbound_message_id)->update(['body' => Crypt::encryptString('Changed while reading history')]);

            return $this->history(11);
        });
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturn($this->decision(11));
        $this->expectException(HttpException::class);
        $this->lookup($intake);
    }

    public function test_shared_hourly_budget_blocks_second_decision_and_additional_history_read(): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::all(true), ['max_ai_calls_per_hour' => 1]));
        $intake = $this->capture();
        $this->helper([11])->shouldReceive('history')->once()->andReturn($this->history(11));
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturn($this->decision(11));
        try {
            $this->lookup($intake);
            $this->fail('The shared AI budget was bypassed.');
        } catch (OpenRouterChatException $error) {
            $this->assertSame('disposition_rate_limit', $error->reasonCode);
        }
        $this->assertSame(1, RateLimiter::attempts('operations-ai:hour:'.now()->utc()->format('YmdH')));
        $this->assertSame('failed', AiIntakeRun::sole()->status);
        $this->noBusinessChanges($intake);
    }

    public function test_whole_received_context_over_limit_is_rejected_before_provider(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldNotReceive('history');
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('completeToolDecision');
        try {
            $this->lookup($intake, [['role' => 'user', 'content' => str_repeat('x', 120001)]]);
            $this->fail('An oversized source context was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->noBusinessChanges($intake);
    }

    public function test_oversized_history_is_rejected_in_full_before_second_provider_decision(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldReceive('history')->once()->andReturn($this->history(11, ['note' => str_repeat('x', 30001)]));
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturn($this->decision(11));
        try {
            $this->lookup($intake);
            $this->fail('Oversized history was silently truncated.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertNull(AiIntakeRun::sole()->result);
        $this->noBusinessChanges($intake);
    }

    public function test_wrong_history_customer_or_provenance_is_rejected(): void
    {
        $intake = $this->capture();
        $this->helper([11])->shouldReceive('history')->once()->andReturn($this->history(99, ['provenance' => ['kind' => 'original_evidence']]));
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturn($this->decision(11));
        try {
            $this->lookup($intake);
            $this->fail('Wrong history identity or evidence provenance was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->noBusinessChanges($intake);
    }

    public function test_forged_manual_run_configuration_is_rejected_before_candidates_and_provider(): void
    {
        $intake = $this->capture();
        $this->mock(AiIntakeCustomerContextService::class)->shouldNotReceive('candidates');
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('completeToolDecision');
        try {
            $this->lookup($intake, beforeLookup: function (AiIntake $current, AiIntakeRun $run): void {
                $run->update(['configuration' => array_replace($run->configuration, ['actor_id' => 999])]);
            });
            $this->fail('A forged manual actor proof was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->noBusinessChanges($intake);
    }

    public function test_automatic_lookup_requires_running_supervised_automation_and_stops_when_disabled(): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::all(true), ['enabled' => true, 'supervisor_id' => $this->actor->id]));
        $intake = $this->capture();
        $intake->update(['status' => 'received', 'error_code' => null]);
        $this->helper([11])->shouldNotReceive('history');
        $this->provider()->shouldReceive('completeToolDecision')->once()->andReturnUsing(function (): OpenRouterToolDecision {
            $this->assertSame('analysis', AiIntakeRun::sole()->kind);
            Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::all(true), ['enabled' => false]));

            return $this->decision(11);
        });

        $this->assertSame([], $this->lookup($intake, automatic: true));
        $this->assertSame('failed', AiIntakeRun::sole()->status);
        $this->assertNull(AiIntakeRun::sole()->result);
        $this->noBusinessChanges($intake);
    }
}
