<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeRun;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Operations\AiIntakeAssistantService;
use App\Services\Operations\AiIntakeMessageContent;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeAssistantServiceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('intake-assistant-'.getmypid()));
        Cache::flush();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => false, 'supervisor_id' => null, 'revision' => 5, 'imap_host' => 'imap.example.test', 'imap_username' => 'dispatch@example.test', 'from_address' => 'dispatch@example.test']));
    }

    private function intake(array $extra = []): AiIntake
    {
        return app(AiIntakeService::class)->receiveEmail($extra + [
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 401, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test', 'subject' => 'Table request',
            'message_id' => 'synthetic@example.test', 'text' => 'Beginn Ende 08:00 16:00',
            'raw' => "From: customer@example.test\r\nTo: dispatch@example.test\r\nSubject: Table request\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<table><tr><th>Beginn</th><th>Ende</th></tr><tr><td>08:00</td><td>16:00</td></tr></table>",
        ], $this->actor)->fresh();
    }

    private function previousQuestion(AiIntake $intake, User $actor, string $question, string $answer, array $extra = []): AiIntakeRun
    {
        $configuration = array_replace([
            'mode' => 'intake_question', 'actor_id' => $actor->id, 'intake_revision' => $intake->revision,
            'prompt' => $question, 'extraction_version' => AiIntakeMessageContent::VERSION,
        ], $extra['configuration'] ?? []);
        unset($extra['configuration']);

        return $intake->runs()->create(array_replace([
            'kind' => 'intake_question', 'status' => 'succeeded', 'source_revision' => $intake->source_revision,
            'settings_revision' => AiDispositionSettings::all(true)['revision'], 'supervising_user_id' => $actor->id,
            'input_hash' => hash('sha256', 'synthetic prior question'), 'input_snapshot' => [],
            'configuration' => $configuration, 'result' => ['answer' => $answer],
            'started_at' => now()->utc(), 'finished_at' => now()->utc(),
        ], $extra));
    }

    private function assertNoBusinessSideEffects(AiIntake $intake, array $before): void
    {
        $this->assertSame($before, $intake->fresh()->getRawOriginal());
        $this->assertSame(0, $intake->proposals()->count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_question_uses_structured_original_and_never_changes_intake_or_sends(): void
    {
        $intake = $this->intake();
        $before = $intake->fresh()->getRawOriginal();
        $original = $intake->latestInboundMessage->getRawOriginal();
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages) {
            $input = json_decode($messages[1]['content'], true);
            $this->assertSame('Welche Zeiten enthält die Tabelle?', $input['question']);
            $this->assertStringContainsString('TABLE', $input['received_messages'][0]['text']);
            $this->assertStringContainsString('08:00', $input['received_messages'][0]['text']);
            $this->assertSame([], $input['conversation']);

            return new OpenRouterChatResponse('{"answer":"Beginn 08:00, Ende 16:00. Das Datum ist noch offen."}', model: 'test-model');
        });

        $answer = app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Welche Zeiten enthält die Tabelle?');

        $this->assertStringContainsString('08:00', $answer['answer']);
        $this->assertSame($before, $intake->fresh()->getRawOriginal());
        $this->assertSame($original, $intake->latestInboundMessage->fresh()->getRawOriginal());
        $run = AiIntakeRun::findOrFail($answer['run_id']);
        $this->assertSame('succeeded', $run->status);
        $this->assertStringNotContainsString('Welche Zeiten', $run->getRawOriginal('configuration'));
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_stale_revision_rejected_before_provider_call(): void
    {
        $intake = $this->intake();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision + 1, 'Warum?');
            $this->fail('Stale input accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(0, AiIntakeRun::count());
    }

    public function test_changed_source_during_answer_discards_result(): void
    {
        $intake = $this->intake();
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function () use ($intake) {
            $intake->increment('source_revision');

            return new OpenRouterChatResponse('{"answer":"Obsolete answer"}');
        });
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Warum?');
            $this->fail('Obsolete answer returned');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('failed', AiIntakeRun::sole()->status);
        $this->assertNull(AiIntakeRun::sole()->result);
    }

    public function test_prompt_is_validated_without_silent_truncation(): void
    {
        $intake = $this->intake();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        $this->expectException(ValidationException::class);
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, str_repeat('x', 2001));
    }

    public function test_revoked_permission_discards_inflight_answer(): void
    {
        $intake = $this->intake();
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function () {
            $this->actor->update(['status' => false]);

            return new OpenRouterChatResponse('{"answer":"Must not leave the service"}');
        });
        $this->expectException(HttpException::class);
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Warum?');
    }

    public function test_history_is_bounded_chronological_and_isolated_to_current_actor_intake_and_context(): void
    {
        $intake = $this->intake();
        $otherIntake = $this->intake(['uid' => 33, 'message_id' => 'other-synthetic@example.test']);
        $otherActor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $before = $intake->getRawOriginal();

        $this->previousQuestion($intake, $this->actor, 'Obsolete intake', 'Do not include', ['configuration' => ['intake_revision' => $intake->revision + 1]]);
        $this->previousQuestion($intake, $this->actor, 'Obsolete extractor', 'Do not include', ['configuration' => ['extraction_version' => 'older-version']]);
        $this->previousQuestion($intake, $this->actor, 'Foreign configuration actor', 'Do not include', ['configuration' => ['actor_id' => $otherActor->id]]);
        $expected = [];
        for ($index = 1; $index <= 8; $index++) {
            $this->previousQuestion($intake, $this->actor, 'Question '.$index, 'Answer '.$index);
            if ($index > 2) {
                $expected[] = ['question' => 'Question '.$index, 'answer' => 'Answer '.$index];
            }
        }
        $this->previousQuestion($intake, $otherActor, 'Private other actor question', 'Private other actor answer');
        $this->previousQuestion($otherIntake, $this->actor, 'Other intake question', 'Other intake answer');
        $this->previousQuestion($intake, $this->actor, 'Old source', 'Do not include', ['source_revision' => $intake->source_revision + 1]);
        $this->previousQuestion($intake, $this->actor, 'Old settings', 'Do not include', ['settings_revision' => 4]);
        $this->previousQuestion($intake, $this->actor, 'Canceled question', 'Do not include', ['status' => 'canceled']);

        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages) use ($expected, $intake, $otherIntake) {
            $input = json_decode($messages[1]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $input['conversation']);
            $this->assertSame([$intake->latest_inbound_message_id], array_column($input['received_messages'], 'message_id'));
            $this->assertNotContains($otherIntake->latest_inbound_message_id, array_column($input['received_messages'], 'message_id'));
            $this->assertStringNotContainsString('Private other actor', $messages[1]['content']);
            $this->assertStringNotContainsString('Other intake question', $messages[1]['content']);

            return new OpenRouterChatResponse('{"answer":"Current actor answer"}');
        });

        $answer = app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Current question');

        $this->assertSame('Current actor answer', $answer['answer']);
        $this->assertNoBusinessSideEffects($intake, $before);
    }

    public function test_total_payload_limit_includes_analysis_and_history_before_provider_call(): void
    {
        $intake = $this->intake();
        $intake->update(['analysis' => ['summary' => str_repeat('A', 55000)]]);
        for ($index = 0; $index < 6; $index++) {
            $this->previousQuestion($intake, $this->actor, str_repeat('Q', 1800), str_repeat('H', 7900));
        }
        $before = $intake->fresh()->getRawOriginal();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');

        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Use the complete context');
            $this->fail('An oversized context was sent or silently truncated.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }

        $latest = $intake->runs()->latest('id')->firstOrFail();
        $this->assertSame('failed', $latest->status);
        $this->assertNull($latest->result);
        $this->assertSame(6, $intake->runs()->where('status', 'succeeded')->count());
        $this->assertNoBusinessSideEffects($intake, $before);
    }

    public function test_history_does_not_reuse_answers_from_old_revisions_versions_or_inconsistent_actor_identity(): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $otherActor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->previousQuestion($intake, $this->actor, 'Question before a native change', 'Obsolete answer',
            ['configuration' => ['intake_revision' => $intake->revision - 1]]);
        $this->previousQuestion($intake, $this->actor, 'Question before table extraction', 'Obsolete answer',
            ['configuration' => ['extraction_version' => 'legacy-extraction']]);
        $this->previousQuestion($intake, $this->actor, 'Inconsistent actor question', 'Private answer',
            ['configuration' => ['actor_id' => $otherActor->id]]);
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages) {
            $input = json_decode($messages[1]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([], $input['conversation']);
            $this->assertStringNotContainsString('Obsolete answer', $messages[1]['content']);
            $this->assertStringNotContainsString('Private answer', $messages[1]['content']);

            return new OpenRouterChatResponse('{"answer":"Fresh answer"}');
        });

        $answer = app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Use this source version');

        $this->assertSame('Fresh answer', $answer['answer']);
        $this->assertSame(4, $intake->runs()->where('status', 'succeeded')->count());
        $this->assertNoBusinessSideEffects($intake, $before);
    }

    public function test_cancellation_during_provider_call_stays_canceled_without_returning_or_persisting_answer(): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $canceled = null;
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function () use (&$canceled) {
            $run = AiIntakeRun::sole();
            $run->update(['status' => 'canceled', 'error_code' => 'synthetic_human_cancellation', 'finished_at' => now()->utc()]);
            $canceled = $run->fresh()->getRawOriginal();

            return new OpenRouterChatResponse('{"answer":"Late canceled answer"}');
        });

        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Cancel while answering');
            $this->fail('A canceled answer left the service.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }

        $run = AiIntakeRun::sole();
        $this->assertSame($canceled, $run->getRawOriginal());
        $this->assertSame('canceled', $run->status);
        $this->assertNull($run->result);
        $this->assertNoBusinessSideEffects($intake, $before);
    }

    public function test_settings_change_during_provider_call_rejects_answer_without_native_changes(): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function () {
            Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY,
                array_replace(AiDispositionSettings::all(true), ['revision' => 6]));

            return new OpenRouterChatResponse('{"answer":"Answer with old settings"}');
        });

        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Use current settings');
            $this->fail('An answer with stale settings left the service.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }

        $run = AiIntakeRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->result);
        $this->assertSame(5, $run->settings_revision);
        $this->assertNoBusinessSideEffects($intake, $before);
    }

    public function test_inactive_or_unauthorized_actor_cannot_start_question_or_provider_call(): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        $inactive = User::factory()->create(['role' => 'admin', 'status' => false]);
        $unauthorized = User::factory()->create(['role' => 'guest', 'status' => true]);

        try {
            app(AiIntakeAssistantService::class)->ask($intake, $inactive, $intake->revision, 'Forbidden question');
            $this->fail('An inactive actor started a question.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $unauthorized, $intake->revision, 'Forbidden question');
            $this->fail('An unauthorized actor started a question.');
        } catch (AuthorizationException $error) {
            $this->assertNull($error->status());
        }

        $this->assertSame(0, AiIntakeRun::count());
        $this->assertNoBusinessSideEffects($intake, $before);
    }

    public function test_corrupted_original_blocks_question_instead_of_falling_back_to_stored_plain_body(): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $message = $intake->latestInboundMessage;
        $messageBefore = $message->getRawOriginal();
        Storage::disk('local')->put($message->raw_path, 'Corrupted synthetic original');
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');

        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Read the original table');
            $this->fail('Stored plain body replaced a corrupt original.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }

        $this->assertSame($messageBefore, $message->fresh()->getRawOriginal());
        $this->assertSame('failed', AiIntakeRun::sole()->status);
        $this->assertNull(AiIntakeRun::sole()->result);
        $this->assertNoBusinessSideEffects($intake, $before);
    }
}
