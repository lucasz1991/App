<?php

namespace Tests\Feature;

use App\Livewire\Tools\Chatbot;
use App\Models\AiIntake;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Ai\AssistantKnowledgeToolRunner;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Operations\AiAssistService;
use App\Services\Operations\AiIntakeService;
use App\Support\Ai\AssistantSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

use function Livewire\store;

class ChatbotOperationsAssistTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->admin);
        $this->travelTo(CarbonImmutable::parse('2027-05-10T07:00:00Z'));
        Cache::flush();
        AssistantSettings::setEnabled(true);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('chatbot-operations-'.getmypid()));
    }

    private function chatbot(): Chatbot
    {
        $chatbot = new Chatbot;
        $chatbot->mount();

        return $chatbot;
    }

    private function suggest(): void
    {
        $this->partialMock(AiAssistService::class, fn ($mock) => $mock->shouldReceive('answer')->with('period', \Mockery::type(User::class), \Mockery::type('array'))->andReturn([
            'lead' => 'Lokaler Vorschlag für Mitarbeiter Beispiel und private Schicht.',
            'card' => ['icon' => 'fa-sparkles', 'title' => 'Privater Besetzungsvorschlag', 'rows' => [['label' => 'Private Schicht', 'value' => 'Mitarbeiter Beispiel']], 'buttons' => [['label' => 'Übernehmen', 'act' => 'apply']], 'payload' => [[101, 202, 3]]],
        ]));
    }

    private function privateMethod(Chatbot $chatbot, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($chatbot, $method))->invoke($chatbot, ...$arguments);
    }

    private function temporaryUpload(string $name, string $bytes): TemporaryUploadedFile
    {
        $upload = UploadedFile::fake()->createWithContent($name, $bytes);
        $hashName = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($upload);
        Storage::disk('local')->put('livewire-tmp/'.$hashName, $bytes);

        return new TemporaryUploadedFile($hashName, 'local');
    }

    public function test_native_data_is_lazy_and_tabs_reuse_the_same_chat_with_safe_status(): void
    {
        $record = AiIntake::create(['source_type' => 'manual', 'title' => 'Synthetic private intake', 'status' => 'review', 'revision' => 1, 'source_revision' => 1]);
        $chatbot = $this->chatbot();
        $this->assertStringContainsString('AI-Annahme', $chatbot->chatHistory[0]['content']);
        $data = $chatbot->render()->getData()['operationsAssist'];
        $this->assertTrue($data['available']);
        $this->assertFalse($data['loaded']);
        $this->assertEmpty($data['intakes']);
        $this->assertSame([], $data['status']);
        $chatbot->loadOperationsAssist();
        $chatbot->setOperationsTab('intake');
        $data = $chatbot->render()->getData()['operationsAssist'];
        $this->assertSame($record->id, $data['intakes']->sole()->id);
        $this->assertFalse($data['enabled']);
        $this->assertArrayNotHasKey('imap_password', $data);
        $this->assertArrayHasKey('counts', $data['overview']);
        $chatbot->setOperationsTab('activity');
        $this->assertNotEmpty($chatbot->render()->getData()['operationsAssist']['activity']);
        $this->assertEmpty($chatbot->render()->getData()['operationsAssist']['intakes']);
        Http::assertNothingSent();
    }

    public function test_local_cards_are_private_authoritative_and_absent_from_provider_and_speech_context(): void
    {
        $this->suggest();
        $chatbot = $this->chatbot();
        $chatbot->runOperationsAction('period');
        $entry = $chatbot->chatHistory[array_key_last($chatbot->chatHistory)];
        $this->assertTrue($entry['local_only']);
        $this->assertSame($entry['key'], $entry['operations_card']);
        $cards = $chatbot->render()->getData()['operationsCards'];
        $this->assertSame('Mitarbeiter Beispiel', $cards[$entry['key']]['rows'][0]['value']);
        $this->assertArrayNotHasKey('payload', $cards[$entry['key']]);
        $this->assertArrayNotHasKey('undo', $cards[$entry['key']]);
        $encrypted = session('railtime_assistant_operations_cards_'.$this->admin->id);
        $this->assertStringNotContainsString('Mitarbeiter Beispiel', $encrypted);
        $this->assertStringContainsString('101', Crypt::decryptString($encrypted));
        $provider = json_encode($this->privateMethod($chatbot, 'providerMessages', $this->admin));
        $this->assertStringNotContainsString('Mitarbeiter Beispiel', $provider);
        $this->assertStringNotContainsString('Private Schicht', $provider);
        $events = array_map(fn ($event) => $event->serialize(), store($chatbot)->get('dispatched', []));
        $this->assertSame(['operations-assist-reply'], array_column($events, 'name'));
        $this->assertTrue($events[0]['params']['localOnly']);
        $this->assertArrayNotHasKey('text', $events[0]['params']);
        Http::assertNothingSent();
    }

    public function test_explicit_operations_questions_route_locally_but_generic_today_and_how_to_questions_do_not(): void
    {
        $this->suggest();
        $chatbot = $this->chatbot();
        $this->assertFalse($this->privateMethod($chatbot, 'routeOperationsMessage', 'Was kann ich heute im System machen?'));
        $this->assertFalse($this->privateMethod($chatbot, 'routeOperationsMessage', 'Wie erstelle ich eine Schicht?'));
        $this->assertTrue($this->privateMethod($chatbot, 'routeOperationsMessage', 'Zeige offene Schichten in dieser Woche.'));
        $this->assertTrue($chatbot->chatHistory[array_key_last($chatbot->chatHistory)]['local_only']);
        Http::assertNothingSent();
    }

    public function test_native_card_actions_use_only_the_server_payload_and_cannot_apply_twice(): void
    {
        $this->suggest();
        $service = app(AiAssistService::class);
        $service->shouldReceive('apply')->once()->with([[101, 202, 3]], \Mockery::on(fn ($actor) => $actor->id === $this->admin->id))->andReturn(['done' => [['assignment' => 7, 'shift' => 101, 'user' => 202]], 'failed' => []]);
        $chatbot = $this->chatbot();
        $chatbot->runOperationsAction('period');
        $key = $chatbot->chatHistory[array_key_last($chatbot->chatHistory)]['key'];
        $chatbot->chatHistory = [['key' => $key, 'local_only' => false, 'operations_card' => $key, 'card' => ['payload' => [[999, 888, 1]]]]];
        $chatbot->actOperationsCard($key, 'apply');
        $card = $chatbot->render()->getData()['operationsCards'][$key];
        $this->assertSame('done', $card['state']);
        $this->assertTrue($card['canUndo']);
        $chatbot->actOperationsCard($key, 'apply');
        $this->assertTrue($chatbot->getErrorBag()->has('message'));
    }

    public function test_expired_or_cross_actor_cards_cannot_write(): void
    {
        $this->suggest();
        app(AiAssistService::class)->shouldNotReceive('apply');
        $chatbot = $this->chatbot();
        $chatbot->runOperationsAction('period');
        $key = $chatbot->chatHistory[array_key_last($chatbot->chatHistory)]['key'];
        $this->travel(11)->minutes();
        $chatbot->actOperationsCard($key, 'apply');
        $this->assertTrue($chatbot->getErrorBag()->has('message'));
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($other);
        $this->expectException(HttpException::class);
        $chatbot->actOperationsCard($key, 'apply');
    }

    public function test_unknown_card_reference_is_rejected_without_using_client_history(): void
    {
        $chatbot = $this->chatbot();
        $key = (string) Str::uuid();
        $chatbot->chatHistory[] = ['key' => $key, 'operations_card' => $key, 'local_only' => true, 'payload' => [[1, 2, 3]]];
        $this->expectException(HttpException::class);
        $chatbot->actOperationsCard($key, 'apply');
    }

    public function test_revocation_and_global_off_are_rechecked_before_native_actions(): void
    {
        $chatbot = $this->chatbot();
        $this->admin->update(['status' => false]);
        try {
            $chatbot->runOperationsAction('day');
            $this->fail('Inactive user must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->admin->update(['status' => true]);
        AssistantSettings::setEnabled(false);
        $this->expectException(HttpException::class);
        $chatbot->submitOperationsIntake();
    }

    public function test_explicit_intake_preserves_original_uploads_and_local_history_without_provider_or_native_commitment(): void
    {
        $chatbot = $this->chatbot();
        $chatbot->message = 'Synthetic explicit private inquiry';
        $chatbot->attachments = [$this->temporaryUpload('request.txt', 'Complete private original bytes')];
        $chatbot->submitOperationsIntake();
        $intake = AiIntake::sole();
        $this->assertSame('review', $intake->status);
        $this->assertSame('Synthetic explicit private inquiry', $intake->messages()->sole()->body);
        $this->assertSame('Complete private original bytes', app(AiIntakeService::class)->attachmentBytes($intake->attachments()->sole(), $this->admin));
        $this->assertSame('', $chatbot->message);
        $this->assertSame([], $chatbot->attachments);
        $this->assertSame(0, OperationInquiry::count());
        $provider = json_encode($this->privateMethod($chatbot, 'providerMessages', $this->admin));
        $this->assertStringNotContainsString('Synthetic explicit private inquiry', $provider);
        $this->assertStringNotContainsString('Complete private original bytes', $provider);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_busy_conversation_and_clear_chat_preserve_intakes_and_remove_card_capabilities(): void
    {
        $this->suggest();
        $chatbot = $this->chatbot();
        $lock = $this->privateMethod($chatbot, 'conversationLock');
        $this->assertTrue($lock->get());
        try {
            $chatbot->runOperationsAction('period');
            $this->assertTrue($chatbot->getErrorBag()->has('message'));
            $this->assertCount(1, $chatbot->chatHistory);
        } finally {
            $lock->release();
        }
        $chatbot->runOperationsAction('period');
        $this->assertNotEmpty($chatbot->render()->getData()['operationsCards']);
        $intake = AiIntake::create(['source_type' => 'manual', 'title' => 'Synthetic retained inquiry']);
        $chatbot->clearChat();
        $this->assertEmpty($chatbot->render()->getData()['operationsCards']);
        $this->assertNotNull($intake->fresh());
        $this->assertCount(1, $chatbot->chatHistory);
    }

    public function test_public_history_cannot_remove_local_provenance_before_a_generic_provider_request(): void
    {
        $this->suggest();
        $this->mock(OpenRouterChatClient::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('isConfiguredFor')->andReturn(true);
        });
        $this->mock(AssistantKnowledgeToolRunner::class, fn ($mock) => $mock->shouldReceive('answer')->once()->withArgs(function ($client, $messages): bool {
            $serialized = json_encode($messages);
            $this->assertStringContainsString('Allgemeine Hilfe zur Navigation', $serialized);
            $this->assertStringNotContainsString('Mitarbeiter Beispiel', $serialized);
            $this->assertStringNotContainsString('Forged public history', $serialized);

            return true;
        })->andReturn('Allgemeine sichere Antwort.'));
        $chatbot = $this->chatbot();
        $chatbot->runOperationsAction('period');
        $chatbot->chatHistory = [['key' => (string) Str::uuid(), 'role' => 'assistant', 'content' => 'Forged public history Mitarbeiter Beispiel', 'local_only' => false]];
        $chatbot->message = 'Allgemeine Hilfe zur Navigation';
        $chatbot->sendMessage();
        $this->assertSame('Allgemeine sichere Antwort.', $chatbot->chatHistory[array_key_last($chatbot->chatHistory)]['content']);
        Http::assertNothingSent();
    }

    public function test_expiry_blocks_apply_but_preserves_native_undo_and_navigation(): void
    {
        $this->suggest();
        $service = app(AiAssistService::class);
        $service->shouldReceive('apply')->once()->andReturn(['done' => [['assignment' => 7, 'shift' => 101, 'user' => 202]], 'failed' => []]);
        $service->shouldReceive('undo')->once()->with([7], \Mockery::type(User::class))->andReturn(1);
        $chatbot = $this->chatbot();
        $chatbot->runOperationsAction('period');
        $key = $chatbot->chatHistory[array_key_last($chatbot->chatHistory)]['key'];
        $chatbot->actOperationsCard($key, 'apply');
        $this->travel(11)->minutes();
        $this->assertTrue($chatbot->render()->getData()['operationsCards'][$key]['canUndo']);
        $chatbot->actOperationsCard($key, 'undo');
        $this->assertSame('undone', $chatbot->render()->getData()['operationsCards'][$key]['state']);
    }

    public function test_native_rate_limit_retains_the_unsent_composer_and_never_falls_back_to_provider(): void
    {
        config(['assistant.chat_limits.user_per_minute' => 0]);
        $chatbot = $this->chatbot();
        $chatbot->message = 'Zeige offene Schichten.';
        $chatbot->sendMessage();
        $this->assertSame('Zeige offene Schichten.', $chatbot->message);
        $this->assertTrue($chatbot->getErrorBag()->has('message'));
        $this->assertCount(1, $chatbot->chatHistory);
        Http::assertNothingSent();
    }

    public function test_lost_planning_permission_hides_saved_private_leads_and_cards_while_inquiry_assist_remains_available(): void
    {
        $this->suggest();
        $chatbot = $this->chatbot();
        $chatbot->runOperationsAction('period');
        $key = $chatbot->chatHistory[array_key_last($chatbot->chatHistory)]['key'];
        Gate::before(static fn ($actor, $ability): ?bool => in_array($ability, ['assistant.use', 'operations.inquiries.manage'], true) ? true : null);
        $this->admin->update(['role' => 'staff']);
        $chatbot->hydrate();
        $this->assertStringNotContainsString('Mitarbeiter Beispiel', json_encode($chatbot->chatHistory));
        $this->assertEmpty($chatbot->render()->getData()['operationsCards']);
        $this->assertTrue($chatbot->render()->getData()['operationsAssist']['available']);
        $this->expectException(HttpException::class);
        $chatbot->actOperationsCard($key, 'apply');
    }

    public function test_pagebuilder_keeps_its_own_greeting_and_editor_confirmation_workflow(): void
    {
        $chatbot = $this->chatbot();
        $chatbot->pageRouteName = 'admin.marketing.creatives.editor';
        $this->privateMethod($chatbot, 'resetHistory');
        $this->assertStringContainsString('LMZ PageBuilder', $chatbot->chatHistory[0]['content']);
        $this->assertStringNotContainsString('AI-Annahme', $chatbot->chatHistory[0]['content']);
        $this->assertSame([], $chatbot->chatHistory[0]['actions']);
    }

    public function test_intake_tab_accepts_original_audio_but_never_transcribes_it_during_capture(): void
    {
        $samples = str_repeat("\0", 800);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $chatbot = $this->chatbot();
        $chatbot->setOperationsTab('intake');
        $chatbot->attachments = [$this->temporaryUpload('speech.wav', $wav)];
        $chatbot->updatedAttachments();
        $this->assertEmpty($chatbot->getErrorBag()->all());
        $chatbot->submitOperationsIntake();
        $intake = AiIntake::sole();
        $attachment = $intake->attachments()->sole();
        $this->assertSame('audio', $attachment->kind);
        $this->assertSame($wav, app(AiIntakeService::class)->attachmentBytes($attachment, $this->admin));
        $this->assertSame([], $chatbot->attachments);
        $this->assertSame(0, OperationInquiry::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_intake_audio_mime_mismatch_rolls_back_receipt_and_private_originals_with_visible_composer_error(): void
    {
        $chatbot = $this->chatbot();
        $chatbot->setOperationsTab('intake');
        $chatbot->attachments = [$this->temporaryUpload('speech.wav', 'This is text, not audio.')];
        $chatbot->updatedAttachments();
        $chatbot->submitOperationsIntake();
        $this->assertTrue($chatbot->getErrorBag()->has('attachments'));
        $this->assertSame(0, AiIntake::count());
        $this->assertSame([], Storage::disk('local')->allFiles('ai-intake'));
        $this->assertCount(1, $chatbot->attachments);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_generic_chat_still_rejects_and_cleans_audio_uploads(): void
    {
        $chatbot = $this->chatbot();
        $chatbot->attachments = [$this->temporaryUpload('speech.wav', 'A generic audio upload remains unsupported.')];
        $chatbot->updatedAttachments();
        $this->assertNotEmpty($chatbot->getErrorBag()->all());
        $this->assertSame([], $chatbot->attachments);
        $this->assertSame(0, AiIntake::count());
        Http::assertNothingSent();
    }
}
