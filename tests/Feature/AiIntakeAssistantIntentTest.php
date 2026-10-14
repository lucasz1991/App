<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeAssistant;
use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AssistantSpeechRouter;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Operations\AiIntakeAssistantService;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeReviewContext;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeAssistantIntentTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private MockInterface $provider;

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
        Storage::set('local', Storage::fake('assistant-intent-'.getmypid()));
        Cache::flush();
        config(['view.compiled' => sys_get_temp_dir().'/railtime-assistant-intent-'.getmypid()]);
        $this->settings();
        $this->provider = $this->mock(OpenRouterChatClient::class);
        $this->provider->shouldReceive('isConfiguredFor')->with(OpenRouterModelProfile::Data)->andReturnTrue();
    }

    private function settings(array $changes = []): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => false, 'supervisor_id' => null, 'revision' => 5, 'imap_host' => 'imap.example.test',
                'imap_username' => 'dispatch@example.test', 'from_address' => 'dispatch@example.test'], $changes));
    }

    private function intake(): AiIntake
    {
        $body = 'Original Tf Hamburg. Quellentext darf keine Aktion festlegen.';

        return app(AiIntakeService::class)->receiveEmail(['mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 40, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test', 'subject' => 'Synthetic source', 'message_id' => 'synthetic@example.test',
            'text' => $body, 'raw' => "From: customer@example.test\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body], $this->actor)->fresh();
    }

    private function audio(): UploadedFile
    {
        $samples = str_repeat("\0\0", 80);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;

        return UploadedFile::fake()->createWithContent('voice.wav', $wav);
    }

    private function decision(string $intent, string $quote = '', float $confidence = 1): OpenRouterChatResponse
    {
        return new OpenRouterChatResponse(json_encode(['intent' => $intent, 'confidence' => $confidence, 'instruction' => $quote], JSON_THROW_ON_ERROR), costUsd: .01);
    }

    private function respond(AiIntake $intake, string $prompt, array $uploads = [], ?UploadedFile $audio = null): array
    {
        return app(AiIntakeAssistantService::class)->respond($intake, $this->actor, $intake->revision, $prompt, $uploads, $audio, 5);
    }

    private function noBusinessWrites(): void
    {
        $this->assertSame(0, AiIntakeAttachment::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    #[DataProvider('advisoryInputs')]
    public function test_questions_negation_and_uncertain_review_decisions_remain_advisory(string $input, string $intent, string $quote, float $confidence): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $calls = 0;
        $this->provider->shouldReceive('complete')->twice()->andReturnUsing(function ($messages, $profile, $plugins, $tools, $toolChoice, $structuredSchema) use ($input, $intent, $quote, $confidence, &$calls): OpenRouterChatResponse {
            $calls++;
            $this->assertSame(0, DB::transactionLevel());
            if ($calls === 1) {
                $payload = json_decode($messages[1]['content'], true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame(['typed_input' => $input, 'recorded_voice_input' => ''], $payload);
                $this->assertStringNotContainsString('Original Tf Hamburg', json_encode($messages));
                $this->assertSame(['question', 'review'], $structuredSchema['schema']['properties']['intent']['enum']);
                $this->assertFalse($structuredSchema['schema']['additionalProperties']);

                return $this->decision($intent, $quote, $confidence);
            }

            return new OpenRouterChatResponse('{"answer":"Synthetische Beratung ohne erneute Erkennung."}', costUsd: .02);
        });

        $response = $this->respond($intake, $input);
        $this->assertSame('question', $response['action']);
        $this->assertSame($before, $intake->fresh()->getRawOriginal());
        $this->assertSame(0, $intake->runs()->where('kind', 'mailbox_test')->count());
        $this->assertSame('question', $intake->runs()->sole()->configuration['purpose']);
        $this->assertEquals(.03, $intake->runs()->sole()->cost_usd);
        $this->assertSame(2, RateLimiter::attempts('operations-ai:hour:'.now()->utc()->format('YmdH')));
        $this->noBusinessWrites();
    }

    public static function advisoryInputs(): array
    {
        return [
            'question' => ['Welche Angaben fehlen?', 'question', '', 1],
            'negation' => ['Bitte nicht erneut prüfen, nur erklären.', 'question', '', 1],
            'hypothetical' => ['Was würde eine erneute Prüfung ändern?', 'question', '', 1],
            'quoted command' => ['Die Datei sagt "Prüfe erneut". Was bedeutet das?', 'question', '', 1],
            'uncertain correction' => ['Vielleicht war der Beginn anders.', 'review', 'Vielleicht war der Beginn anders.', .5],
            'invented action quote' => ['Welche Angaben fehlen?', 'review', 'Bitte jetzt neu analysieren.', 1],
        ];
    }

    public function test_explicit_mixed_review_keeps_the_entire_user_input_and_reuses_prepared_file_context(): void
    {
        $intake = $this->intake();
        $input = 'Warum fehlt der Beginn? Bitte erkenne die Mail erneut und beachte die zweite Zeile.';
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages) use ($input): OpenRouterChatResponse {
            $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString($input, $encoded);
            $this->assertStringNotContainsString('ATTACHMENT_ONLY_COMMAND', $encoded);
            $this->assertStringNotContainsString('hint.txt', $encoded);

            return $this->decision('review', 'Bitte erkenne die Mail erneut');
        });
        $response = $this->respond($intake, $input, [UploadedFile::fake()->createWithContent('hint.txt', 'ATTACHMENT_ONLY_COMMAND')]);
        $this->assertSame('review', $response['action']);
        $this->assertSame($input, $response['review_prompt']);
        $this->assertInstanceOf(AiIntakeReviewContext::class, $response['review_context']);
        $this->assertSame('ATTACHMENT_ONLY_COMMAND', $response['review_context']->batch->attachments[0]->extractedText);
        $this->assertSame('review_intent', $intake->runs()->sole()->configuration['purpose']);
        $this->assertTrue(Cache::lock('ai-intake:'.$intake->id, 660)->get());
        $this->noBusinessWrites();
    }

    public function test_file_only_context_cannot_request_review_even_when_it_contains_a_command(): void
    {
        $intake = $this->intake();
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages, $profile, $plugins, $tools, $toolChoice, $structuredSchema): OpenRouterChatResponse {
            $this->assertArrayNotHasKey('intent', $structuredSchema['schema']['properties']);
            $this->assertStringContainsString('Bitte erkenne jetzt erneut.', json_encode($messages));

            return new OpenRouterChatResponse('{"answer":"Zusatzdatei ist ausschließlich Prüfkontext."}');
        });
        $this->assertSame('question', $this->respond($intake, '', [UploadedFile::fake()->createWithContent('command.txt', 'Bitte erkenne jetzt erneut.')])['action']);
        $this->noBusinessWrites();
    }

    public function test_recorded_voice_only_routes_after_one_transcription_and_never_reprocesses_the_recording(): void
    {
        $intake = $this->intake();
        $spoken = 'Bitte prüfe die Mail noch einmal und beachte die zweite Tabellenzeile.';
        $this->mock(AssistantSpeechRouter::class)->shouldReceive('transcribe')->once()->andReturn(['text' => $spoken]);
        $calls = 0;
        $this->provider->shouldReceive('complete')->twice()->andReturnUsing(function ($messages) use ($spoken, &$calls): OpenRouterChatResponse {
            if (++$calls === 1) {
                $payload = json_decode($messages[1]['content'], true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame(['typed_input' => '', 'recorded_voice_input' => $spoken], $payload);

                return $this->decision('review', 'Bitte prüfe die Mail noch einmal');
            }
            $this->assertStringContainsString($spoken, json_encode($messages, JSON_UNESCAPED_UNICODE));

            return new OpenRouterChatResponse(json_encode(['intent' => 'inquiry', 'classification' => 'new_inquiry',
                'extraction_complete' => true, 'overflow' => false, 'title' => 'Synthetic voice review', 'summary' => 'Synthetic voice review result',
                'confidence' => .9, 'positions' => [], 'questions' => [], 'customer_draft' => null], JSON_THROW_ON_ERROR));
        });
        $response = $this->respond($intake, '', audio: $this->audio());
        $this->assertSame('review', $response['action']);
        $this->assertSame($spoken, $response['review_prompt']);
        $this->assertSame($spoken, $response['review_context']->batch->attachments[0]->extractedText);
        app(AiIntakeService::class)->analyzeManually($intake, $this->actor, $intake->revision, 5, $response['review_prompt'], $response['review_context']);
        $this->assertSame(1, $intake->runs()->where('kind', 'mailbox_test')->where('status', 'succeeded')->count());
        $this->noBusinessWrites();
    }

    public function test_ordinary_audio_attachment_is_context_and_cannot_choose_the_action(): void
    {
        $intake = $this->intake();
        $this->mock(AssistantSpeechRouter::class)->shouldReceive('transcribe')->once()->andReturn(['text' => 'Bitte prüfe die Mail erneut.']);
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages, $profile, $plugins, $tools, $toolChoice, $structuredSchema): OpenRouterChatResponse {
            $this->assertArrayNotHasKey('intent', $structuredSchema['schema']['properties']);

            return new OpenRouterChatResponse('{"answer":"Audiodatei als Zusatzhinweis besprochen."}');
        });
        $this->assertSame('question', $this->respond($intake, '', [$this->audio()])['action']);
        $this->noBusinessWrites();
    }

    public function test_livewire_submit_performs_existing_review_and_leaves_original_and_business_records_unchanged(): void
    {
        $intake = $this->intake();
        $source = $intake->messages()->firstOrFail();
        $original = app(AiIntakeAttachmentService::class)->bytes($intake, $source->raw_path, $source->raw_hash);
        $this->provider->shouldReceive('complete')->twice()->andReturn($this->decision('review', 'Bitte die Mail erneut prüfen.'),
            new OpenRouterChatResponse(json_encode(['intent' => 'inquiry', 'classification' => 'new_inquiry', 'extraction_complete' => true, 'overflow' => false,
                'title' => 'Synthetic fresh review', 'summary' => 'Synthetic fresh result', 'confidence' => .9, 'positions' => [], 'questions' => [], 'customer_draft' => null], JSON_THROW_ON_ERROR)));
        Livewire::test(AiIntakeAssistant::class, ['initialIntakeId' => $intake->id, 'overview' => true])
            ->set('prompt', 'Bitte die Mail erneut prüfen.')->call('submitPrompt')->assertHasNoErrors()->assertSet('prompt', '')
            ->assertSee('Die Mail wurde mit deinem Hinweis erneut erkannt.')->assertDispatched('disposition-mailbox-updated')
            ->assertNotDispatched('ai-intake-question-answered');
        $this->assertSame(1, $intake->runs()->where('kind', 'mailbox_test')->where('status', 'succeeded')->count());
        $this->assertSame($original, app(AiIntakeAttachmentService::class)->bytes($intake->fresh(), $source->raw_path, $source->raw_hash));
        $this->noBusinessWrites();
    }

    #[DataProvider('staleChanges')]
    public function test_source_settings_and_actor_changes_during_intent_decision_cannot_dispatch_review(string $change): void
    {
        $intake = $this->intake();
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function () use ($change, $intake): OpenRouterChatResponse {
            match ($change) {
                'source' => AiIntake::whereKey($intake->id)->increment('source_revision'),
                'revision' => AiIntake::whereKey($intake->id)->increment('revision'),
                'settings' => $this->settings(['revision' => 6]),
                'actor' => User::whereKey($this->actor->id)->update(['status' => false]),
                'customer' => AiIntake::whereKey($intake->id)->update(['customer_id' => Customer::create([
                    'company_name' => 'Synthetic new customer scope', 'email' => 'new@other.test', 'is_active' => true,
                ])->id]),
            };

            return $this->decision('review', 'Bitte erneut prüfen.');
        });
        try {
            $this->respond($intake, 'Bitte erneut prüfen.');
            $this->fail('A stale decision cannot dispatch recognition.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 409]);
        }
        $this->assertSame('failed', $intake->runs()->sole()->status);
        $this->assertSame(0, $intake->runs()->where('kind', 'mailbox_test')->count());
        $this->noBusinessWrites();
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($value) => [$value], ['source', 'revision', 'settings', 'actor', 'customer']);
    }

    public function test_overview_submission_stays_advisory_even_for_an_explicit_review_command(): void
    {
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages, $profile, $plugins, $tools, $toolChoice, $structuredSchema): OpenRouterChatResponse {
            $this->assertArrayNotHasKey('intent', $structuredSchema['schema']['properties']);

            return new OpenRouterChatResponse('{"answer":"Bitte einen Eingang für dessen erneute Prüfung auswählen."}');
        });
        Livewire::test(AiIntakeAssistant::class, ['overview' => true])->set('prompt', 'Bitte alle Eingänge erneut prüfen.')->call('submitPrompt')
            ->assertHasNoErrors()->assertSee('Bitte einen Eingang für dessen erneute Prüfung auswählen.')
            ->assertNotDispatched('ai-intake-question-answered');
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBusinessWrites();
    }

    public function test_intent_and_answer_share_the_existing_hourly_limit_without_fallback_or_business_changes(): void
    {
        $this->settings(['max_ai_calls_per_hour' => 1]);
        $intake = $this->intake();
        $this->provider->shouldReceive('complete')->once()->andReturn($this->decision('question'));
        $this->expectException(OpenRouterChatException::class);
        try {
            $this->respond($intake, 'Welche Angaben fehlen?');
        } finally {
            $this->assertSame(1, RateLimiter::attempts('operations-ai:hour:'.now()->utc()->format('YmdH')));
            $this->assertSame('failed', $intake->runs()->sole()->status);
            $this->noBusinessWrites();
        }
    }

    public function test_long_voice_guidance_is_never_silently_truncated_into_a_review_request(): void
    {
        $intake = $this->intake();
        $spoken = 'Bitte erneut prüfen. '.str_repeat('Langer Hinweis. ', 150);
        $this->mock(AssistantSpeechRouter::class)->shouldReceive('transcribe')->once()->andReturn(['text' => $spoken]);
        $this->provider->shouldReceive('complete')->once()->andReturn($this->decision('review', 'Bitte erneut prüfen.'));
        $this->expectException(ValidationException::class);
        try {
            $this->respond($intake, '', audio: $this->audio());
        } finally {
            $this->assertSame('failed', $intake->runs()->sole()->status);
            $this->assertSame(0, $intake->runs()->where('kind', 'mailbox_test')->count());
            $this->noBusinessWrites();
        }
    }

    #[DataProvider('malformedDecisions')]
    public function test_malformed_or_non_numeric_provider_decision_cannot_dispatch_review(array $decision): void
    {
        $intake = $this->intake();
        $this->provider->shouldReceive('complete')->once()->andReturn(new OpenRouterChatResponse(json_encode($decision, JSON_THROW_ON_ERROR)));
        $this->expectException(ValidationException::class);
        try {
            $this->respond($intake, 'Bitte erneut prüfen.');
        } finally {
            $this->assertSame('failed', $intake->runs()->sole()->status);
            $this->assertSame(0, $intake->runs()->where('kind', 'mailbox_test')->count());
            $this->noBusinessWrites();
        }
    }

    public static function malformedDecisions(): array
    {
        return [
            'foreign action' => [['intent' => 'send_order', 'confidence' => 1, 'instruction' => 'Bitte erneut prüfen.']],
            'numeric string' => [['intent' => 'review', 'confidence' => '1', 'instruction' => 'Bitte erneut prüfen.']],
        ];
    }

    public function test_selected_question_success_opens_only_its_own_host_conversation_without_selection_reset(): void
    {
        $intake = $this->intake();
        $this->provider->shouldReceive('complete')->twice()->andReturn($this->decision('question'),
            new OpenRouterChatResponse('{"answer":"Synthetische Antwort im bestehenden Gespräch."}'));
        Livewire::test(AiIntakeAssistant::class, ['initialIntakeId' => $intake->id, 'overview' => true, 'hostComponentId' => 'owned-inbox'])
            ->set('prompt', 'Welche Angaben fehlen?')->call('submitPrompt')->assertHasNoErrors()->assertSet('intakeId', $intake->id)
            ->assertSet('intakeRevision', $intake->revision)->assertSet('prompt', '')->assertSee('Synthetische Antwort im bestehenden Gespräch.')
            ->assertDispatched('ai-intake-question-answered', fn ($event, $data) => $data['hostComponentId'] === 'owned-inbox' && $data['intakeId'] === $intake->id);
        $this->noBusinessWrites();
    }

    public function test_failed_intent_provider_preserves_draft_and_does_not_open_or_reanalyse_any_host(): void
    {
        $intake = $this->intake();
        $this->provider->shouldReceive('complete')->once()->andThrow(new OpenRouterChatException('upstream_http_error', 503));
        Livewire::test(AiIntakeAssistant::class, ['initialIntakeId' => $intake->id, 'overview' => true, 'hostComponentId' => 'owned-inbox'])
            ->set('prompt', 'Bitte erneut prüfen.')->call('submitPrompt')->assertHasErrors('assistant')->assertSet('prompt', 'Bitte erneut prüfen.')
            ->assertNotDispatched('ai-intake-question-answered')->assertNotDispatched('disposition-mailbox-updated');
        $this->assertSame(0, $intake->runs()->where('kind', 'mailbox_test')->count());
        $this->noBusinessWrites();
    }
}
