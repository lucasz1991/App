<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Tools\Chatbot;
use App\Models\AssistantKnowledgeEntry;
use App\Models\AssistantKnowledgeTopic;
use App\Models\User;
use App\Services\Ai\AssistantKnowledgePool;
use App\Services\Ai\AssistantSpeechRouter;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Ai\OpenRouterToolDecision;
use App\Services\Ai\SpeechServiceClient;
use App\Support\Ai\AssistantKnowledgeSettings;
use App\Support\Ai\AssistantSettings;
use App\Support\PageHelpCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['assistant.history_limit' => 80, 'assistant.transcript_limit' => 36]);
    }

    public function test_it_keeps_the_history_server_side_and_dispatches_a_completed_reply(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldReceive('completeToolDecision')
            ->once()
            ->andReturnUsing(function (array $messages, array $tools): OpenRouterToolDecision {
                $this->assertSame('system', $messages[0]['role']);
                $this->assertStringContainsString('Kompakter RailTime-Wissenskontext:', $messages[0]['content']);
                $this->assertStringContainsString(AssistantKnowledgeSettings::DEFAULT_INTRO, $messages[0]['content']);
                $this->assertSame('user', $messages[array_key_last($messages)]['role']);
                $this->assertContains('open_railtime_page', array_column(array_column($tools, 'function'), 'name'));

                return new OpenRouterToolDecision('Guten Tag', []);
            });
        $provider->shouldNotReceive('stream');
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->andReturn(true);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        $component = Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->assertSet('pageRouteName', 'unknown')
            ->set('message', 'Wie nutze ich RailTime?')
            ->call('sendMessage')
            ->assertSet('message', '')
            ->assertSet('isLoading', false)
            ->assertDispatched('railtime-assistant-reply');

        $history = $component->get('chatHistory');
        $this->assertNotSame('', $component->get('pageHelpHint'));
        $this->assertSame('user', $history[count($history) - 2]['role']);
        $this->assertSame('Wie nutze ich RailTime?', $history[count($history) - 2]['content']);
        $this->assertSame('assistant', $history[count($history) - 1]['role']);
        $this->assertSame('Guten Tag', $history[count($history) - 1]['content']);
        $this->assertSame($history, session('railtime_assistant_history_'.$user->id));
    }

    public function test_it_builds_bounded_localized_page_help_hints_without_an_ai_request_and_refreshes_them_on_hydration(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        app()->setLocale('en');
        $englishHelp = app(PageHelpCatalog::class)->forRoute('unknown');

        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldNotReceive('completeToolDecision');
        $provider->shouldNotReceive('complete');
        $provider->shouldNotReceive('stream');
        $speech = Mockery::mock(AssistantSpeechRouter::class);
        $speech->shouldReceive('capabilities')->andReturn([
            'mode' => 'local_only',
            'stt_configured' => false,
            'tts_configured' => false,
        ]);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(AssistantSpeechRouter::class, $speech);

        $component = Livewire::actingAs($user)->test(Chatbot::class);
        $englishHints = $component->get('pageHelpHints');

        $this->assertSame($englishHelp['summary'], $component->get('pageHelpHint'));
        $this->assertSame($englishHelp['title'], $englishHints[0]);
        $this->assertSame($englishHelp['summary'], $englishHints[1]);
        $this->assertCount(5, $englishHints);
        $this->assertCount(5, array_unique(array_map('mb_strtolower', $englishHints)));
        $this->assertTrue(collect($englishHints)->every(
            fn (string $hint): bool => $hint !== '' && mb_strlen($hint) <= 160,
        ));

        app()->setLocale('de');
        $germanHelp = app(PageHelpCatalog::class)->forRoute('unknown');
        $component->instance()->hydrate();
        $germanHints = $component->instance()->pageHelpHints;

        $this->assertSame($germanHelp['summary'], $component->instance()->pageHelpHint);
        $this->assertSame($germanHelp['title'], $germanHints[0]);
        $this->assertSame($germanHelp['summary'], $germanHints[1]);
        $this->assertNotSame($englishHints, $germanHints);
        $this->assertCount(5, $germanHints);
    }

    public function test_it_rejects_oversized_input_before_calling_the_provider(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        config(['assistant.max_input_characters' => 10]);
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldNotReceive('stream');
        $provider->shouldNotReceive('completeToolDecision');
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        $component = Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->set('message', 'Diese Nachricht ist zu lang')
            ->call('sendMessage')
            ->assertHasErrors(['message'])
            ->assertSee('höchstens 10 Zeichen');

        $this->assertCount(1, $component->get('chatHistory'));
    }

    public function test_it_executes_the_curated_knowledge_tool_before_the_final_reply(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $topic = AssistantKnowledgeTopic::query()->create([
            'name' => 'Dienstplanung',
            'is_active' => true,
        ]);
        AssistantKnowledgeEntry::query()->create([
            'topic_id' => $topic->id,
            'title' => 'Schichttausch freigeben',
            'content' => 'Die zuständige Stelle prüft und bestätigt die Tauschanfrage.',
            'keywords' => ['Schichttausch'],
            'is_active' => true,
        ]);

        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldReceive('completeToolDecision')
            ->once()
            ->andReturn(new OpenRouterToolDecision(null, [[
                'id' => 'call_knowledge_chat',
                'type' => 'function',
                'function' => [
                    'name' => AssistantKnowledgePool::TOOL_NAME,
                    'arguments' => '{"query":"Schichttausch freigeben"}',
                ],
            ]]));
        $provider->shouldReceive('stream')
            ->once()
            ->withArgs(function (array $messages, callable $onDelta, array $tools, string $choice): bool {
                $tool = collect($messages)->firstWhere('role', 'tool');
                $this->assertSame('none', $choice);
                $this->assertSame(AssistantKnowledgePool::TOOL_NAME, $tools[0]['function']['name']);
                $this->assertStringContainsString('zuständige Stelle', $tool['content']);
                $onDelta('Die zuständige Stelle muss den Tausch freigeben.');

                return true;
            })
            ->andReturn('Die zuständige Stelle muss den Tausch freigeben.');

        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        $component = Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->set('message', 'Wie gebe ich einen Schichttausch frei?')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertDispatched('railtime-assistant-reply');

        $history = $component->get('chatHistory');
        $this->assertSame('Die zuständige Stelle muss den Tausch freigeben.', $history[array_key_last($history)]['content']);
    }

    public function test_attachment_context_is_encrypted_and_raw_uploads_are_discarded(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $topic = AssistantKnowledgeTopic::query()->create([
            'name' => 'Dateianalyse',
            'is_active' => true,
        ]);
        AssistantKnowledgeEntry::query()->create([
            'topic_id' => $topic->id,
            'title' => 'Interne Testnotiz einordnen',
            'content' => 'KNOWLEDGE-RESULT-FOR-ATTACHMENT',
            'keywords' => ['Testnotiz'],
            'is_active' => true,
        ]);

        $decisionProfile = null;
        $decisionPlugins = null;
        $decisionAnnotations = [[
            'type' => 'file',
            'file' => [
                'hash' => 'parsed-file-hash',
                'name' => 'parsed.pdf',
                'content' => [['type' => 'text', 'text' => 'PARSED-PDF-CONTEXT']],
            ],
        ]];
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldReceive('isConfiguredFor')->once()->andReturn(true);
        $provider->shouldReceive('completeToolDecision')
            ->once()
            ->withArgs(function (
                array $messages,
                array $tools,
                OpenRouterModelProfile $profile,
                array $plugins,
            ) use (&$decisionProfile, &$decisionPlugins): bool {
                $userMessage = collect($messages)->last(fn (array $message): bool => $message['role'] === 'user');
                $payload = json_encode($userMessage['content'], JSON_UNESCAPED_UNICODE);
                $this->assertStringContainsString('BEGINN NICHT VERTRAUENSWUERDIGER DATEIINHALT', $payload);
                $this->assertStringContainsString('Interne Testnotiz', $payload);
                $this->assertSame(OpenRouterModelProfile::Data, $profile);
                $this->assertSame([], $plugins);
                $this->assertSame(AssistantKnowledgePool::TOOL_NAME, $tools[0]['function']['name']);
                $decisionProfile = $profile;
                $decisionPlugins = $plugins;

                return true;
            })
            ->andReturn(new OpenRouterToolDecision(null, [[
                'id' => 'call_attachment_knowledge',
                'type' => 'function',
                'function' => [
                    'name' => AssistantKnowledgePool::TOOL_NAME,
                    'arguments' => '{"query":"Interne Testnotiz"}',
                ],
            ]], $decisionAnnotations));
        $provider->shouldReceive('complete')
            ->once()
            ->withArgs(function (
                array $messages,
                OpenRouterModelProfile $profile,
                array $plugins,
                array $tools,
                string $toolChoice,
            ) use (&$decisionProfile, &$decisionPlugins, $decisionAnnotations): bool {
                $assistantMessage = collect($messages)->first(
                    fn (array $message): bool => ($message['role'] ?? null) === 'assistant'
                        && isset($message['tool_calls']),
                );
                $toolMessage = collect($messages)->firstWhere('role', 'tool');

                $this->assertSame($decisionProfile, $profile);
                $this->assertSame($decisionPlugins, $plugins);
                $this->assertSame($decisionAnnotations, $assistantMessage['annotations']);
                $this->assertSame('call_attachment_knowledge', $toolMessage['tool_call_id']);
                $this->assertStringContainsString('KNOWLEDGE-RESULT-FOR-ATTACHMENT', $toolMessage['content']);
                $this->assertSame(AssistantKnowledgePool::TOOL_NAME, $tools[0]['function']['name']);
                $this->assertSame('none', $toolChoice);

                return true;
            })
            ->andReturn(new OpenRouterChatResponse('Die Datei enthält eine Testnotiz.', []));
        $provider->shouldNotReceive('stream');

        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        $component = Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->set('attachments', [UploadedFile::fake()->createWithContent('notiz.txt', 'Interne Testnotiz')])
            ->set('message', '')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('attachments', []);

        $stored = session('railtime_assistant_attachment_context_'.$user->id);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('Interne Testnotiz', $stored);
        $decrypted = Crypt::decryptString($stored);
        $this->assertStringContainsString('Interne Testnotiz', $decrypted);
        $this->assertStringContainsString('PARSED-PDF-CONTEXT', $decrypted);

        $history = $component->get('chatHistory');
        $this->assertSame('notiz.txt', $history[count($history) - 2]['attachments'][0]['name']);

        $component->call('clearChat');
        $this->assertNull(session('railtime_assistant_attachment_context_'.$user->id));
    }

    public function test_attachment_discard_returns_and_dispatches_a_token_bound_acknowledgement(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $cleanupId = 'cleanup_8f511c6e-1558-4ebd-a968-9dbab1a4de24';

        Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->set('attachments', [UploadedFile::fake()->createWithContent('notiz.txt', 'Temporärer Inhalt')])
            ->call('discardAttachments', $cleanupId)
            ->assertSet('attachments', [])
            ->assertDispatched('railtime-assistant-attachments-discarded')
            ->assertReturned([
                'cleanup_id' => $cleanupId,
                'remaining' => 0,
            ]);
    }

    public function test_attachment_discard_never_acknowledges_zero_when_storage_delete_fails(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $cleanupId = 'cleanup_3f339694-010e-422b-896e-a939069b5e96';
        $undeletableUpload = new class extends TemporaryUploadedFile
        {
            public function __construct() {}

            public function delete(): bool
            {
                return false;
            }
        };
        $component = Livewire::actingAs($user)->test(Chatbot::class);
        $component->instance()->attachments = [$undeletableUpload];

        $acknowledgement = $component->instance()->discardAttachments($cleanupId);

        $this->assertSame([
            'cleanup_id' => $cleanupId,
            'remaining' => 1,
        ], $acknowledgement);
        $this->assertSame([$undeletableUpload], $component->instance()->attachments);
    }

    public function test_external_speech_configuration_keeps_the_chat_controls_selectable(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $speech = Mockery::mock(AssistantSpeechRouter::class);
        $speech->shouldReceive('capabilities')->andReturn([
            'mode' => 'external_only',
            'stt_configured' => true,
            'tts_configured' => true,
            'stt_available' => true,
            'tts_available' => true,
        ]);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(AssistantSpeechRouter::class, $speech);

        Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->assertSet('speechAvailable', true)
            ->assertSet('sttConfigured', true)
            ->assertSet('ttsConfigured', true)
            ->assertSet('speechRoutingLabel', 'Nur OpenRouter')
            ->assertSet('externalFallback', false);
    }

    public function test_inactive_users_cannot_mount_the_assistant(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => false]);

        Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->assertForbidden();
    }

    public function test_global_disable_blocks_admin_before_provider_availability_checks(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        AssistantSettings::setEnabled(false);

        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldNotReceive('isConfigured');
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldNotReceive('isConfigured');
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->assertForbidden();
    }

    public function test_staff_without_assistant_permission_cannot_mount_the_assistant(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldNotReceive('isConfigured');
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldNotReceive('isConfigured');
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        Livewire::actingAs($user)
            ->test(Chatbot::class)
            ->assertForbidden();
    }

    public function test_an_existing_livewire_session_is_revoked_on_the_next_hydration(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->once()->andReturn(true);
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->once()->andReturn(false);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        $component = Livewire::actingAs($user)->test(Chatbot::class);

        AssistantSettings::setEnabled(false);

        $component->call('clearChat')->assertForbidden();
    }

    public function test_global_provider_concurrency_fails_fast_without_persisting_the_prompt(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        config(['assistant.chat_limits.provider_concurrency' => 1]);
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldNotReceive('stream');
        $provider->shouldNotReceive('completeToolDecision');
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);
        $slot = Cache::lock('assistant-chat:provider-slot:1', 60);
        $this->assertTrue($slot->get());

        try {
            $component = Livewire::actingAs($user)
                ->test(Chatbot::class)
                ->set('message', 'Warte bitte')
                ->call('sendMessage')
                ->assertHasErrors(['message']);

            $this->assertCount(1, $component->get('chatHistory'));
        } finally {
            $slot->release();
        }
    }

    public function test_a_parallel_tab_cannot_enter_the_same_session_history_critical_section(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $provider = Mockery::mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfigured')->andReturn(true);
        $provider->shouldNotReceive('stream');
        $provider->shouldNotReceive('completeToolDecision');
        $speech = Mockery::mock(SpeechServiceClient::class);
        $speech->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(OpenRouterChatClient::class, $provider);
        $this->app->instance(SpeechServiceClient::class, $speech);

        $component = Livewire::actingAs($user)->test(Chatbot::class);
        $sessionHash = hash('sha256', session()->getId());
        $lock = Cache::lock('assistant-chat:conversation:'.$user->id.':'.$sessionHash, 60);
        $this->assertTrue($lock->get());

        try {
            $component
                ->set('message', 'Parallele Nachricht')
                ->call('sendMessage')
                ->assertHasErrors(['message']);

            $this->assertCount(1, $component->get('chatHistory'));
        } finally {
            $lock->release();
        }
    }
}
