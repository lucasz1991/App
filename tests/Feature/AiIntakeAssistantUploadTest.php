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
use App\Services\Ai\Attachments\AssistantAttachmentException;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Operations\AiIntakeAssistantService;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeAssistantUploadTest extends TestCase
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
        Storage::set('local', Storage::fake('assistant-upload-'.getmypid()));
        Cache::flush();
        config(['view.compiled' => sys_get_temp_dir().'/railtime-assistant-upload-'.getmypid()]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => false, 'supervisor_id' => null, 'revision' => 5, 'imap_host' => 'imap.example.test', 'imap_username' => 'dispatch@example.test', 'from_address' => 'dispatch@example.test']));
    }

    private function intake(): AiIntake
    {
        return app(AiIntakeService::class)->receiveEmail(['mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 40, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test', 'subject' => 'Synthetic source', 'message_id' => 'synthetic@example.test',
            'text' => 'Original Tf Hamburg.', 'raw' => "From: customer@example.test\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nOriginal Tf Hamburg."], $this->actor)->fresh();
    }

    private function provider(callable $inspect): void
    {
        $client = $this->mock(OpenRouterChatClient::class);
        $client->shouldReceive('isConfiguredFor')->with(OpenRouterModelProfile::Data)->andReturnTrue();
        $client->shouldReceive('complete')->once()->andReturnUsing(function ($messages, $profile, $plugins) use ($inspect) {
            $this->assertSame(OpenRouterModelProfile::Data, $profile);
            $inspect($messages, $plugins);

            return new OpenRouterChatResponse('{"answer":"Unverbindliche synthetische Beratung."}', model: 'synthetic-model');
        });
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

    private function audio(): UploadedFile
    {
        $samples = str_repeat("\0\0", 80);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;

        return UploadedFile::fake()->createWithContent('voice.wav', $wav);
    }

    public function test_text_upload_supplies_actual_content_without_becoming_original_evidence(): void
    {
        $intake = $this->intake();
        $original = $intake->latestInboundMessage->getRawOriginal();
        $this->provider(function ($messages, $plugins): void {
            $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('Supplemental-only correction', $encoded);
            $this->assertStringContainsString('keine empfangenen Originalbelege', $encoded);
            $this->assertStringContainsString('Original Tf Hamburg.', $encoded);
            $this->assertSame([], $plugins);
        });
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, '', [UploadedFile::fake()->createWithContent('hint.txt', 'Supplemental-only correction')], null, 5);
        $this->assertSame($original, $intake->latestInboundMessage->fresh()->getRawOriginal());
        $this->assertStringNotContainsString('Supplemental-only correction', AiIntakeRun::latest('id')->first()->getRawOriginal('configuration'));
        $this->noBusinessWrites();
    }

    public function test_pdf_is_sent_as_real_file_with_native_parser_plugin(): void
    {
        $intake = $this->intake();
        $this->provider(function ($messages, $plugins): void {
            $file = collect($messages[1]['content'])->firstWhere('type', 'file');
            $this->assertSame('hint.pdf', $file['file']['filename']);
            $this->assertStringStartsWith('data:application/pdf;base64,', $file['file']['file_data']);
            $this->assertSame('file-parser', $plugins[0]['id']);
        });
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Vergleichen', [UploadedFile::fake()->createWithContent('hint.pdf', "%PDF-1.7\nsynthetic bounded PDF")]);
        $this->noBusinessWrites();
    }

    public function test_image_is_sent_as_multimodal_content_using_data_model(): void
    {
        $intake = $this->intake();
        $this->provider(function ($messages): void {
            $image = collect($messages[1]['content'])->firstWhere('type', 'image_url');
            $this->assertStringStartsWith('data:image/', $image['image_url']['url']);
        });
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Bild vergleichen', [UploadedFile::fake()->image('hint.png', 30, 30)]);
        $this->noBusinessWrites();
    }

    public function test_audio_is_transcribed_explicitly_and_supplies_its_actual_text(): void
    {
        $intake = $this->intake();
        $this->mock(AssistantSpeechRouter::class)->shouldReceive('transcribe')->once()->andReturn(['text' => 'Spoken supplemental hint', 'provider' => 'synthetic']);
        $this->provider(fn ($messages) => $this->assertStringContainsString('Spoken supplemental hint', json_encode($messages)));
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, '', [], $this->audio(), 5);
        $this->noBusinessWrites();
    }

    public function test_changed_source_after_speech_blocks_following_model_request(): void
    {
        $intake = $this->intake();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        $this->mock(AssistantSpeechRouter::class)->shouldReceive('transcribe')->once()->andReturnUsing(function () use ($intake) {
            $intake->increment('source_revision');

            return ['text' => 'Stale speech'];
        });
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, '', [], $this->audio(), 5);
            $this->fail('Stale speech accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('failed', AiIntakeRun::latest('id')->first()->status);
        $this->noBusinessWrites();
    }

    public function test_spoofed_mime_and_combined_file_count_fail_before_any_provider(): void
    {
        $intake = $this->intake();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'hint', [UploadedFile::fake()->createWithContent('false.png', 'plain text')]);
            $this->fail('Spoof accepted');
        } catch (AssistantAttachmentException $error) {
            $this->assertSame('mime_mismatch', $error->reasonCode);
        }
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'hint', array_fill(0, 3, UploadedFile::fake()->createWithContent('hint.txt', 'hint')), $this->audio());
            $this->fail('Four files accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('uploads', $error->errors());
        }
        $this->noBusinessWrites();
    }

    public function test_full_text_that_would_be_truncated_is_rejected(): void
    {
        $intake = $this->intake();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        $this->expectException(ValidationException::class);
        app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'hint', [UploadedFile::fake()->createWithContent('hint.txt', str_repeat('x', 20001))]);
    }

    public function test_prepared_review_context_is_private_and_rejects_different_intake(): void
    {
        $intake = $this->intake();
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        $context = app(AiIntakeAssistantService::class)->prepareReviewContext($intake, $this->actor, $intake->revision, 5, [UploadedFile::fake()->createWithContent('hint.txt', 'Supplemental hint')]);
        $this->assertStringContainsString('KEIN ORIGINALBELEG', $context->requestContent()[0]['text']);
        $this->assertSame(0, $intake->proposals()->count());
        $other = AiIntake::create(['source_type' => 'email', 'status' => 'review', 'revision' => 1, 'source_revision' => 1]);
        try {
            app(AiIntakeService::class)->analyzeManually($other, $this->actor, 1, 5, '', $context);
            $this->fail('Cross-intake context accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('review', $other->fresh()->status);
        $this->noBusinessWrites();
    }

    public function test_empty_overview_uses_real_provider_with_no_intake_or_business_writes(): void
    {
        $this->provider(function ($messages): void {
            $payload = json_decode($messages[1]['content'], true);
            $this->assertSame(0, $payload['overview']['total']);
            $this->assertSame([], $payload['overview']['intakes']);
            $this->assertStringContainsString('keine vollständigen Originalmails', $messages[0]['content']);
        });
        $service = app(AiIntakeAssistantService::class);
        $fingerprint = $service->overviewFingerprint($this->actor, null);
        $service->askOverview($this->actor, null, 5, $fingerprint, 'Wie prüfe ich eine neue Anfrage?');
        $this->assertCount(1, $service->overviewHistory($this->actor, null, 5, $fingerprint));
        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBusinessWrites();
    }

    public function test_overview_scope_and_history_are_isolated_by_actor_and_customer(): void
    {
        $customer = Customer::create(['company_name' => 'Allowed customer']);
        $foreign = Customer::create(['company_name' => 'Other customer']);
        AiIntake::create(['customer_id' => $customer->id, 'source_type' => 'manual', 'status' => 'review', 'title' => 'Allowed intake', 'revision' => 1, 'source_revision' => 1]);
        AiIntake::create(['customer_id' => $foreign->id, 'source_type' => 'manual', 'status' => 'review', 'title' => 'Foreign intake', 'revision' => 1, 'source_revision' => 1]);
        $this->provider(function ($messages): void {
            $payload = json_decode($messages[1]['content'], true);
            $this->assertSame(1, $payload['overview']['total']);
            $this->assertSame('Allowed intake', $payload['overview']['intakes'][0]['title']);
            $this->assertStringNotContainsString('Foreign intake', $messages[1]['content']);
        });
        $service = app(AiIntakeAssistantService::class);
        $fingerprint = $service->overviewFingerprint($this->actor, $customer->id);
        $service->askOverview($this->actor, $customer->id, 5, $fingerprint, 'Was ist offen?');
        $this->assertCount(1, $service->overviewHistory($this->actor, $customer->id, 5, $fingerprint));
        $this->assertSame([], $service->overviewHistory($this->actor, null, 5, $fingerprint));
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->assertSame([], $service->overviewHistory($other, $customer->id, 5, $fingerprint));
        $this->noBusinessWrites();
    }

    public function test_overview_changed_during_provider_discards_answer_and_history(): void
    {
        $intake = $this->intake();
        $this->provider(fn () => $intake->increment('revision'));
        $service = app(AiIntakeAssistantService::class);
        $fingerprint = $service->overviewFingerprint($this->actor, null);
        try {
            $service->askOverview($this->actor, null, 5, $fingerprint, 'Was ist offen?');
            $this->fail('Stale overview answer accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame([], $service->overviewHistory($this->actor, null, 5, $fingerprint));
        $this->noBusinessWrites();
    }

    public function test_overview_upload_contains_actual_content_without_creating_intake(): void
    {
        $service = app(AiIntakeAssistantService::class);
        $fingerprint = $service->overviewFingerprint($this->actor, null);
        $this->provider(fn ($messages) => $this->assertStringContainsString('Supplemental overview text', json_encode($messages)));
        $service->askOverview($this->actor, null, 5, $fingerprint, '', [UploadedFile::fake()->createWithContent('hint.txt', 'Supplemental overview text')]);
        $this->assertSame(0, AiIntake::count());
        $this->noBusinessWrites();
    }

    public function test_livewire_overview_is_always_present_and_foreign_host_events_are_ignored(): void
    {
        $component = Livewire::test(AiIntakeAssistant::class, ['overview' => true, 'hostComponentId' => 'owner'])
            ->assertDontSee('Alle Eingänge')->assertSeeHtml('>Frage oder Prüfhinweis</label>')
            ->assertSeeHtml('wire:model="prompt"')->assertSee('Dateien anhängen')->assertDontSee('Bei einem Eingang „Mit AI besprechen“ wählen.');
        $component->dispatch('select-ai-intake-assistant', intakeId: 99999, componentId: 'foreign')->assertSet('intakeId', null);
        $component->dispatch('ai-intake-assistant-overview', customerId: null, componentId: 'foreign')->assertSet('overview', true);
        $component->call('beginContextUpload')->assertSet('contextChanged', false);
    }

    public function test_livewire_owned_file_removal_deletes_temp_and_stale_selection_drops_uploads(): void
    {
        $intake = $this->intake();
        $component = Livewire::test(AiIntakeAssistant::class, ['initialIntakeId' => $intake->id])->call('beginContextUpload')
            ->set('uploads', [UploadedFile::fake()->createWithContent('hint.txt', 'Synthetic file')])->assertHasNoErrors();
        $path = $component->get('uploads')[0]->getRealPath();
        $this->assertFileExists($path);
        $component->call('removeUpload', 0)->assertSet('uploads', []);
        $this->assertFileDoesNotExist($path);
        $component->call('beginContextUpload')->set('uploads', [UploadedFile::fake()->createWithContent('second.txt', 'Synthetic second')]);
        $path = $component->get('uploads')[0]->getRealPath();
        $intake->increment('source_revision');
        $component->call('ask')->assertSet('contextChanged', true)->assertSet('uploads', []);
        $this->assertFileDoesNotExist($path);
        $this->noBusinessWrites();
    }

    public function test_livewire_failed_question_retains_ready_upload_and_prompt_for_retry(): void
    {
        $intake = $this->intake();
        $client = $this->mock(OpenRouterChatClient::class);
        $client->shouldReceive('isConfiguredFor')->andReturnTrue();
        $client->shouldReceive('complete')->once()->andThrow(new OpenRouterChatException('transport_error'));
        $component = Livewire::test(AiIntakeAssistant::class, ['initialIntakeId' => $intake->id])->call('beginContextUpload')
            ->set('uploads', [UploadedFile::fake()->createWithContent('hint.txt', 'Synthetic file')])->set('prompt', 'Keep this question')->call('ask')
            ->assertHasErrors('assistant')->assertSet('prompt', 'Keep this question')->assertSet('contextChanged', false);
        $this->assertCount(1, $component->get('uploads'));
        $this->assertFileExists($component->get('uploads')[0]->getRealPath());
        $this->noBusinessWrites();
    }

    public function test_native_manual_review_receives_supplemental_context_but_rejects_its_fake_evidence(): void
    {
        $intake = $this->intake();
        $original = $intake->latestInboundMessage->getRawOriginal();
        $context = app(AiIntakeAssistantService::class)->prepareReviewContext($intake, $this->actor, $intake->revision, 5, [UploadedFile::fake()->createWithContent('hint.txt', 'Supplemental-only place')]);
        $client = $this->mock(OpenRouterChatClient::class);
        $client->shouldReceive('isConfiguredFor')->andReturnTrue();
        $client->shouldReceive('complete')->once()->andReturnUsing(function ($messages) use ($intake) {
            $this->assertStringContainsString('Supplemental-only place', json_encode($messages));
            $this->assertStringContainsString('KEIN ORIGINALBELEG', $messages[2]['content'][0]['text']);
            $result = ['intent' => 'inquiry', 'classification' => 'new_inquiry', 'extraction_complete' => true, 'overflow' => false, 'title' => 'Synthetic recognition',
                'summary' => 'Unverbindliche Prüfung.', 'confidence' => 0.5, 'questions' => [], 'customer_draft' => null,
                'positions' => [['title' => 'Synthetic position', 'starts_at' => null, 'ends_at' => null, 'timezone' => 'Europe/Berlin', 'location_name' => 'Supplemental-only place',
                    'role_name' => null, 'required_staff' => null, 'segments' => [], 'qualification_requirements' => [], 'missing_fields' => [],
                    'evidence' => [['field' => 'location_name', 'message_id' => $intake->latest_inbound_message_id, 'attachment_id' => null, 'quote' => 'Supplemental-only place']]]]];

            return new OpenRouterChatResponse(json_encode($result), model: 'synthetic-model');
        });
        $recognized = app(AiIntakeService::class)->analyzeManually($intake, $this->actor, $intake->revision, 5, '', $context);
        $this->assertNull($recognized->analysis['positions'][0]['location_name']);
        $this->assertSame('review', $recognized->status);
        $this->assertSame($original, $intake->latestInboundMessage->fresh()->getRawOriginal());
        $this->noBusinessWrites();
    }

    public function test_livewire_failed_manual_review_retains_owned_upload_and_guidance_after_own_revision_change(): void
    {
        $intake = $this->intake();
        $client = $this->mock(OpenRouterChatClient::class);
        $client->shouldReceive('isConfiguredFor')->andReturnTrue();
        $client->shouldReceive('complete')->once()->andThrow(new OpenRouterChatException('transport_error'));
        $component = Livewire::test(AiIntakeAssistant::class, ['initialIntakeId' => $intake->id])->call('beginContextUpload')
            ->set('uploads', [UploadedFile::fake()->createWithContent('hint.txt', 'Synthetic file')])->set('prompt', 'Keep this guidance')->call('review')
            ->assertHasErrors('assistant')->assertSet('prompt', 'Keep this guidance')->assertSet('contextChanged', false);
        $this->assertSame($intake->revision + 1, $component->get('intakeRevision'));
        $this->assertCount(1, $component->get('uploads'));
        $this->assertFileExists($component->get('uploads')[0]->getRealPath());
        $this->noBusinessWrites();
    }

    public function test_overview_latest_twenty_coverage_is_explicit_and_does_not_include_original_bodies(): void
    {
        for ($index = 1; $index <= 23; $index++) {
            AiIntake::create(['source_type' => 'manual', 'status' => 'review', 'revision' => 1, 'source_revision' => 1, 'title' => 'Synthetic intake '.$index]);
        }
        $this->provider(function ($messages): void {
            $snapshot = json_decode($messages[1]['content'], true)['overview'];
            $this->assertSame(23, $snapshot['total']);
            $this->assertSame(20, $snapshot['included']);
            $this->assertCount(20, $snapshot['intakes']);
            $this->assertSame('Synthetic intake 23', $snapshot['intakes'][0]['title']);
            $this->assertSame('Synthetic intake 4', $snapshot['intakes'][19]['title']);
            $this->assertStringContainsString('Ältere Eingänge sind nicht einzeln geprüft', $snapshot['coverage']);
        });
        $service = app(AiIntakeAssistantService::class);
        $service->askOverview($this->actor, null, 5, $service->overviewFingerprint($this->actor, null), 'Was ist offen?');
        $this->assertSame(23, AiIntake::count());
        $this->noBusinessWrites();
    }

    public function test_overview_stale_settings_fail_before_speech_and_provider(): void
    {
        $this->mock(OpenRouterChatClient::class)->shouldNotReceive('complete');
        $this->mock(AssistantSpeechRouter::class)->shouldNotReceive('transcribe');
        $service = app(AiIntakeAssistantService::class);
        try {
            $service->askOverview($this->actor, null, 4, $service->overviewFingerprint($this->actor, null), '', [], $this->audio());
            $this->fail('Stale settings accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBusinessWrites();
    }

    public function test_host_selection_uses_one_authoritative_listener_and_leaves_overview_history(): void
    {
        $intake = $this->intake();
        $component = Livewire::test(AiIntakeAssistant::class, ['overview' => true, 'hostComponentId' => 'owner']);
        $listeners = $component->effects['listeners'] ?? [];
        $this->assertContains('select-ai-intake-assistant', $listeners);
        $this->assertContains('ai-intake-assistant-overview', $listeners);
        $this->assertNotContains('ai-intake-selected', $listeners);
        $component->set('prompt', 'Unsent overview draft')->dispatch('select-ai-intake-assistant', intakeId: $intake->id, componentId: 'owner')
            ->assertSet('intakeId', $intake->id)->assertSet('prompt', '')->assertSet('intakeRevision', $intake->revision)->assertDontSee('Alle Eingänge')
            ->assertSeeHtml('>Frage oder Prüfhinweis</label>')->assertSeeHtml('wire:model="prompt"');
        $component->dispatch('ai-intake-assistant-overview', customerId: null, componentId: 'owner')->assertSet('intakeId', null)->assertDontSee('Alle Eingänge')
            ->assertSeeHtml('>Frage oder Prüfhinweis</label>')->assertSeeHtml('wire:model="prompt"');
        $this->noBusinessWrites();
    }
}
