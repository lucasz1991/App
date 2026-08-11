<?php

namespace Tests\Unit;

use App\Livewire\Tools\Chatbot;
use Illuminate\Support\Facades\Blade;
use ReflectionMethod;
use Tests\TestCase;

class ChatbotViewMarkupTest extends TestCase
{
    private string $view;

    private string $javascript;

    private string $css;

    private string $pet;

    private string $pet3d;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2);
        $this->view = (string) file_get_contents($root.'/resources/views/livewire/tools/chatbot.blade.php');
        $this->javascript = (string) file_get_contents($root.'/resources/js/chatbot.js');
        $this->css = (string) file_get_contents($root.'/resources/css/chatbot.css');
        $this->pet = (string) file_get_contents($root.'/resources/views/components/railtime-assistant-pet.blade.php');
        $this->pet3d = (string) file_get_contents($root.'/resources/js/assistant-pet-3d.js');
    }

    public function test_view_exposes_the_livewire_chat_contract_with_escaped_messages(): void
    {
        $this->assertStringContainsString('wire:submit.prevent="sendMessage"', $this->view);
        $this->assertStringContainsString('wire:model="message"', $this->view);
        $this->assertStringContainsString('wire:click="clearChat"', $this->view);
        $this->assertStringContainsString('wire:click="quickAction(', $this->view);
        $this->assertStringContainsString('x-bind:disabled="navigationCleanupInFlight || isLoading || !assistantAvailable"', $this->view);
        $this->assertStringContainsString('x-bind:disabled="navigationCleanupInFlight"', $this->view);
        $this->assertStringContainsString('wire:key="railtime-chatbot-message-', $this->view);
        $this->assertStringContainsString('wire:stream="assistant-response-stream"', $this->view);
        $this->assertStringContainsString('{{ $content }}', $this->view);
        $this->assertStringNotContainsString('rt-chatbot__composer-meta', $this->view);
        $this->assertStringNotContainsString('class="rt-chatbot__privacy"', $this->view);
        $this->assertStringNotContainsString('{!!', $this->view);
    }

    public function test_blade_template_compiles(): void
    {
        $compiled = app('blade.compiler')->compileString($this->view);

        $this->assertStringContainsString('railtimeChatbot', $compiled);
        $this->assertStringContainsString('assistant.audio-output.stream', $compiled);
    }

    public function test_view_renders_the_backend_payload_and_escapes_assistant_content(): void
    {
        $html = Blade::render($this->view, [
            'assistantName' => 'RailTime Test',
            'assistantAvailable' => true,
            'speechAvailable' => true,
            'chatHistory' => [[
                'key' => 'reply-1',
                'role' => 'assistant',
                'content' => '<script>alert("unsafe")</script>',
                'created_at' => '2026-08-01T01:00:00+02:00',
                'actions' => [[
                    'kind' => 'prompt',
                    'key' => 'inbox',
                    'label' => 'Postfach prüfen',
                ]],
            ]],
            'isLoading' => false,
        ]);

        $this->assertStringContainsString('RailTime Test', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("unsafe")</script>', $html);
        $this->assertStringContainsString('Postfach prüfen', $html);
        $this->assertStringContainsString('class="rt-chatbot__message-actions"', $html);
        $this->assertMatchesRegularExpression(
            '/rt-chatbot__message-stack.*?&lt;script&gt;alert\(&quot;unsafe&quot;\)&lt;\/script&gt;.*?rt-chatbot__message-actions.*?Postfach prüfen/s',
            $html,
        );
    }

    public function test_pagebuilder_context_and_pending_diff_render_independently_of_chat_age(): void
    {
        $token = str_repeat('A', 48);
        $html = Blade::render($this->view, [
            'assistantName' => 'RailTime Assist',
            'assistantAvailable' => true,
            'speechAvailable' => false,
            'pageRouteName' => 'admin.mail-documents.editor',
            'pageBuilderUi' => [
                'active' => true,
                'connected' => true,
                'mode_label' => 'E-Mail',
                'document_label' => 'Signatur',
                'selection_label' => 'Überschrift',
                'selection_character_count' => 19,
                'selection_excerpt' => 'VERTRAULICHER-ROHTEXT-DARF-NICHT-ERSCHEINEN',
                'unsaved' => true,
            ],
            'quickActions' => [[
                'key' => 'pagebuilder_selection',
                'label' => 'Auswahl analysieren',
                'prompt' => 'Auswahl prüfen',
            ]],
            'chatHistory' => [
                [
                    'key' => 'older-pending',
                    'role' => 'assistant',
                    'content' => 'Änderung vorbereitet.',
                    'created_at' => '2026-08-01T01:00:00+02:00',
                    'actions' => [[
                        'kind' => 'pending_tool',
                        'token' => $token,
                        'label' => 'Text ändern',
                        'route_name' => 'admin.mail-documents.editor',
                        'status' => 'error',
                        'segment' => 'Überschrift',
                        'before' => 'Alter Text',
                        'after' => 'Neuer Text',
                        'error' => 'Die Änderung wurde nicht ausgeführt.',
                    ]],
                ],
                [
                    'key' => 'latest-message',
                    'role' => 'assistant',
                    'content' => 'Spätere Nachricht.',
                    'created_at' => '2026-08-01T01:01:00+02:00',
                ],
            ],
            'isLoading' => false,
        ]);

        $this->assertStringContainsString('PageBuilder Copilot', $html);
        $this->assertStringContainsString('Signatur', $html);
        $this->assertStringContainsString('Ungespeichert', $html);
        $this->assertStringContainsString('19', $html);
        $this->assertStringContainsString('Zeichen', $html);
        $this->assertStringNotContainsString('VERTRAULICHER-ROHTEXT-DARF-NICHT-ERSCHEINEN', $html);
        $this->assertStringContainsString('Alter Text', $html);
        $this->assertStringContainsString('Neuer Text', $html);
        $this->assertStringContainsString('Die Änderung wurde nicht ausgeführt.', $html);
        $this->assertStringContainsString('Verwerfen', $html);
    }

    public function test_pending_redesign_renders_the_full_escaped_safety_detail_after_its_diff(): void
    {
        $token = str_repeat('R', 48);
        $detail = 'Alle Story-, Post- und Web-Layouts werden ersetzt. Ungespeicherte lokale Layout-Änderungen werden ersetzt. Es wird nichts veröffentlicht, exportiert oder versendet. <strong>Freigaben werden zurückgesetzt.</strong>';
        $html = Blade::render($this->view, [
            'assistantName' => 'RailTime Assist',
            'assistantAvailable' => true,
            'speechAvailable' => false,
            'pageRouteName' => 'admin.marketing.creatives.editor',
            'pageBuilderUi' => [
                'active' => true,
                'connected' => true,
                'mode_label' => 'Marketing',
                'document_label' => 'Story',
                'unsaved' => true,
            ],
            'chatHistory' => [[
                'key' => 'pending-redesign',
                'role' => 'assistant',
                'content' => 'Das vollständige Redesign ist zur Bestätigung vorbereitet.',
                'created_at' => '2026-08-11T08:17:00+02:00',
                'actions' => [[
                    'kind' => 'pending_tool',
                    'token' => $token,
                    'label' => 'Komplettes Motiv neu gestalten',
                    'route_name' => 'admin.marketing.creatives.editor',
                    'status' => 'pending',
                    'segment' => 'Story, Post und Web',
                    'before' => 'Aktuelle Layouts',
                    'after' => 'RailTime Modern',
                    'detail' => $detail,
                ]],
            ]],
            'isLoading' => false,
        ]);

        $escapedDetail = e($detail);

        $this->assertStringContainsString('rt-chatbot__message-action-diff', $html);
        $this->assertStringContainsString('Aktuelle Layouts', $html);
        $this->assertStringContainsString('RailTime Modern', $html);
        $this->assertStringContainsString('Ungespeicherte lokale Layout-Änderungen werden ersetzt.', $html);
        $this->assertStringContainsString('Es wird nichts veröffentlicht, exportiert oder versendet.', $html);
        $this->assertStringContainsString($escapedDetail, $html);
        $this->assertStringNotContainsString('<strong>Freigaben werden zurückgesetzt.</strong>', $html);
        $this->assertLessThan(strpos($html, $escapedDetail), strpos($html, 'rt-chatbot__message-action-diff'));
    }

    public function test_view_uses_only_the_named_same_origin_audio_routes(): void
    {
        $this->assertStringContainsString("Route::has('assistant.audio-input.transcribe')", $this->view);
        $this->assertStringContainsString("route('assistant.audio-input.transcribe', [], false)", $this->view);
        $this->assertStringContainsString("Route::has('assistant.audio-output.stream')", $this->view);
        $this->assertStringContainsString("route('assistant.audio-output.stream', [], false)", $this->view);
        $this->assertStringNotContainsString('serviceToken', $this->view);
        $this->assertStringNotContainsString('Authorization', $this->view);
    }

    public function test_panel_switches_between_mobile_dialog_and_desktop_complementary_landmark(): void
    {
        $this->assertStringContainsString('x-data="railtimeChatbot({', $this->view);
        $this->assertStringContainsString('x-on:railtime-assistant-reply.window', $this->view);
        $this->assertStringContainsString("isDesktopDocked || pageBuilderActive ? 'complementary' : 'dialog'", $this->view);
        $this->assertStringContainsString('x-trap.inert.noscroll="open && !isDesktopDocked && !pageBuilderActive"', $this->view);
        $this->assertStringContainsString('aria-labelledby="railtime-chatbot-title"', $this->view);
    }

    public function test_pagebuilder_copilot_stays_contextual_and_non_modal(): void
    {
        $this->assertStringContainsString('x-on:railtime-assistant-open.window="handleAssistantOpen($event.detail)"', $this->view);
        $this->assertStringContainsString('if (handlePageBuilderContextUpdated($event.detail) && open) $wire.updatePageBuilderAssistantContext($event.detail)', $this->view);
        $this->assertStringContainsString('railtime-pagebuilder-assistant-claim-failed', $this->view);
        $this->assertStringContainsString('class="rt-chatbot__pagebuilder-context"', $this->view);
        $this->assertStringContainsString('class="rt-chatbot__pagebuilder-chips"', $this->view);
        $this->assertStringContainsString('class="rt-chatbot__pagebuilder-quickbar"', $this->view);
        $this->assertStringContainsString('rt-chatbot__message-action-diff', $this->view);
        $this->assertStringContainsString('wire:click="dismissAssistantAction(', $this->view);
        $this->assertStringContainsString('x-on:click="cancelPageBuilderActionClaim(', $this->view);
        $this->assertStringContainsString('wire:target="confirmAssistantAction,dismissAssistantAction"', $this->view);
        $this->assertStringContainsString('x-on:keydown.escape.window.capture="handlePanelEscape($event)"', $this->view);
        $this->assertStringContainsString("root.setAttribute('data-rt-pagebuilder-assist-open', 'true')", $this->javascript);
        $this->assertStringContainsString("root.removeAttribute('data-rt-pagebuilder-assist-open')", $this->javascript);
        $this->assertStringContainsString(".rt-chatbot[data-pagebuilder-active='true'] .rt-chatbot__backdrop", $this->css);
        $this->assertStringContainsString('width: min(24.5rem, 34vw);', $this->css);
        $this->assertStringContainsString('height: min(62dvh, 36rem);', $this->css);
    }

    public function test_pet_replaces_the_visual_launcher_and_primes_actions_before_opening_the_panel(): void
    {
        $this->assertStringContainsString('class="rt-chatbot__pet-stage"', $this->view);
        $this->assertStringContainsString('class="rt-chatbot__pet-launcher"', $this->view);
        $this->assertStringContainsString('<x-railtime-assistant-pet', $this->view);
        $this->assertStringContainsString('x-on:click="handlePetClick()"', $this->view);
        $this->assertStringContainsString('x-show="petBubbleVisible"', $this->view);
        $this->assertStringContainsString('x-for="action in petBubbleActions"', $this->view);
        $this->assertStringContainsString('x-on:click.stop="runPetBubbleAction(action)"', $this->view);
        $this->assertStringContainsString('async handlePetClick()', $this->javascript);
        // Der Launcher traegt die AI-Partikelwolke (RT-Morph); das fruehere
        // 3D-Maskottchen bleibt als Backup in assistant-pet-3d.js liegen.
        $this->assertStringContainsString('x-data="railtimeAssistantCloud()"', $this->view);
        $this->assertStringContainsString('data-assistant-cloud-slot="launcher"', $this->view);
        $this->assertStringContainsString('data-assistant-cloud-slot="header"', $this->view);
        $this->assertStringContainsString('rt-assistant-cloud__fallback', $this->view);
        $this->assertSame(2, substr_count($this->view, 'x-bind:data-state="petState()"'));
        $this->assertStringNotContainsString('(speaking || ttsPlaying)', $this->view);
        $this->assertStringContainsString("petBubbleAnnounce ? 'polite' : 'off'", $this->view);
        $this->assertStringNotContainsString('class="rt-chatbot__launcher"', $this->view);

        preg_match('/<div\s+class="rt-chatbot__pet-stage"(?<attributes>[^>]*)>/s', $this->view, $stage);
        $this->assertStringNotContainsString('x-data=', $stage['attributes'] ?? '');
        $this->assertStringContainsString('x-ref="launcher"', $this->view);
        $this->assertLessThan(
            strpos($this->view, 'class="rt-chatbot__pet-stage"'),
            strpos($this->view, 'class="rt-chatbot__pet-controller"'),
        );
    }

    public function test_pet_is_a_text_free_red_leaf_capsule_creature(): void
    {
        $this->assertStringContainsString('aria-hidden="true"', $this->pet);
        $this->assertStringContainsString('focusable="false"', $this->pet);
        $this->assertStringContainsString('rt-assistant-pet__capsule', $this->pet);
        $this->assertStringContainsString('rt-assistant-pet__face-screen', $this->pet);
        $this->assertStringContainsString('rt-assistant-pet__face', $this->pet);
        $this->assertStringContainsString('rt-assistant-pet__leaf', $this->pet);
        $this->assertStringContainsString('rt-assistant-pet__feet', $this->pet);
        $this->assertStringNotContainsString('rt-assistant-pet__antenna', $this->pet);
        $this->assertStringNotContainsString('rt-assistant-pet__circuit', $this->pet);
        $this->assertStringNotContainsString('rt-assistant-pet__tail', $this->pet);
        $this->assertStringNotContainsString('rt-assistant-pet__robot', $this->pet);
        $this->assertStringNotContainsString('<text', $this->pet);
        $this->assertStringContainsString('--rt-pet-body: #e21d3f;', $this->css);
    }

    public function test_prominent_pet_uses_one_lazy_webgl_model_that_moves_between_launcher_and_header(): void
    {
        $this->assertStringContainsString("import('three')", $this->pet3d);
        $this->assertStringContainsString('new THREE.WebGLRenderer', $this->pet3d);
        $this->assertStringContainsString('MAX_DEVICE_PIXEL_RATIO = 1.75', $this->pet3d);
        $this->assertStringContainsString('MAX_FRAMES_PER_SECOND = 30', $this->pet3d);
        $this->assertStringContainsString('new IntersectionObserver', $this->pet3d);
        $this->assertStringContainsString('new THREE.SphereGeometry', $this->pet3d);
        $this->assertStringNotContainsString('new THREE.ExtrudeGeometry', $this->pet3d);
        $this->assertStringContainsString('railtime-assistant-red-baby-creature', $this->pet3d);
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $this->pet3d);
        $this->assertStringContainsString("'(prefers-reduced-motion: reduce)'", $this->pet3d);
        $this->assertStringContainsString('this.petActiveSlot.appendChild(this.petCanvas)', $this->pet3d);
        $this->assertStringContainsString("targetName = this.\$el.dataset.petOpen === 'true' ? 'header' : 'launcher'", $this->pet3d);
        $this->assertStringContainsString("canvas.addEventListener('webglcontextlost'", $this->pet3d);
        $this->assertStringContainsString('this.petRenderer.dispose()', $this->pet3d);
        $this->assertStringContainsString('SVG fallback stays active', $this->pet3d);
        $this->assertStringContainsString('PET_MOTION_PROFILES', $this->pet3d);
        $this->assertStringContainsString('assistantPetMotionProfile(state)', $this->pet3d);
        $this->assertStringContainsString('profile.mouthPulse', $this->pet3d);
        $this->assertStringNotContainsString('petState:', $this->pet3d);
    }

    public function test_settings_popover_keeps_audio_and_automatic_help_controls_in_the_panel(): void
    {
        $this->assertStringContainsString('class="rt-chatbot__service-alert"', $this->view);
        $this->assertStringNotContainsString('class="rt-chatbot__control-deck"', $this->view);
        $this->assertStringContainsString('id="railtime-chatbot-settings"', $this->view);
        $this->assertStringContainsString('role="dialog"', $this->view);
        $this->assertStringContainsString('aria-labelledby="railtime-chatbot-settings-title"', $this->view);
        $this->assertStringContainsString('aria-haspopup="dialog"', $this->view);
        $this->assertStringContainsString('x-trap="settingsOpen"', $this->view);
        $this->assertStringContainsString('x-on:click="toggleSettings()"', $this->view);
        $this->assertStringContainsString('x-on:click.outside="if (settingsOpen) closeSettings(false)"', $this->view);
        $this->assertStringContainsString('x-on:keydown.escape.stop.prevent="closeSettings(true)"', $this->view);
        $this->assertStringContainsString('x-model="autoRead"', $this->view);
        $this->assertStringContainsString('x-bind:checked="autoListen"', $this->view);
        $this->assertStringContainsString('x-on:change="setAutoListen($event.target.checked, $event.target)"', $this->view);
        $this->assertStringContainsString('x-model="autoHelp"', $this->view);
        $this->assertStringContainsString('x-model.number="speechRate"', $this->view);
        $this->assertStringContainsString("'pageRouteName' => \$resolvedPageRouteName", $this->view);
        $this->assertStringContainsString("'pageHelpHint' => \$resolvedPageHelpHint", $this->view);
        $this->assertStringContainsString("'pageHelpHints' => \$resolvedPageHelpHints", $this->view);
        $this->assertStringContainsString("'autoReadDefault' => false", $this->view);
        $this->assertStringContainsString("'autoListenDefault' => false", $this->view);
        $this->assertStringContainsString("'autoHelpDefault' => true", $this->view);
        $this->assertStringNotContainsString('x-teleport', $this->view);
        $this->assertStringNotContainsString('rt-chatbot__voice-controls', $this->view);
        $this->assertStringContainsString('class="rt-chatbot__message-actions"', $this->view);
        $this->assertStringContainsString('class="rt-chatbot__composer-state"', $this->view);
        $this->assertStringContainsString('x-show="recording || voiceUploading"', $this->view);
        $this->assertStringContainsString('aria-live="polite"', $this->view);
        $this->assertStringNotContainsString('Direkt loslegen', $this->view);
        $this->assertStringNotContainsString('rt-chatbot__quick-actions-track', $this->view);
        $this->assertStringNotContainsString('rt-chatbot__composer-heading', $this->view);
        $this->assertStringNotContainsString('Mikrofon verfügbar', $this->view);
        $this->assertStringNotContainsString('class="rt-chatbot__eyebrow"', $this->view);
    }

    public function test_audio_controller_has_generation_cancellation_and_real_playback_state(): void
    {
        $this->assertStringContainsString("import('../css/chatbot.css')", $this->javascript);
        $this->assertStringContainsString("from './microphone-stream.js'", $this->javascript);
        $this->assertStringContainsString('acquireMicrophoneStream()', $this->javascript);
        $this->assertStringContainsString('holdMicrophoneStream()', $this->javascript);
        $this->assertStringContainsString('releaseMicrophoneStream()', $this->javascript);
        $this->assertStringContainsString('VOICE_CAPTURE_LIMIT_MS = 45_000', $this->javascript);
        $this->assertStringContainsString('ttsCurrentGeneration', $this->javascript);
        $this->assertStringContainsString('new AbortController()', $this->javascript);
        $this->assertStringContainsString('audio.onplaying = () =>', $this->javascript);
        $this->assertStringContainsString('audio.ontimeupdate = updateProgress', $this->javascript);
        $this->assertStringContainsString('PHRASE_AUDIO_CACHE_MAX_ITEMS = 8', $this->javascript);
        $this->assertStringContainsString('ttsTokenState(key, start, end, total)', $this->javascript);
        $this->assertStringContainsString('URL.revokeObjectURL', $this->javascript);
        $this->assertStringContainsString('(prefers-reduced-motion: reduce)', $this->javascript);
        $this->assertStringContainsString('PET_BUBBLE_CYCLE_MS', $this->javascript);
        $this->assertStringContainsString('petReplyReady', $this->javascript);
        $this->assertStringContainsString('clearPetBubbleTimers()', $this->javascript);
    }

    public function test_styles_reserve_one_top_level_layer_without_creating_a_sidebar(): void
    {
        $this->assertStringContainsString('@media (min-width: 1140px)', $this->css);
        $this->assertStringContainsString('--rt-keyboard-inset', $this->css);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $this->css);
        $this->assertMatchesRegularExpression('/\.rt-chatbot__panel\s*\{[^}]*z-index:\s*80;/s', $this->css);
        $this->assertStringContainsString('.rt-chatbot__pet-bubble', $this->css);
        $this->assertStringContainsString('@keyframes rt-chatbot-pet-float', $this->css);
        $this->assertStringContainsString('@keyframes rt-chatbot-pet-blink', $this->css);
        $this->assertStringContainsString('@keyframes rt-chatbot-pet-leaf-left', $this->css);
        $this->assertStringContainsString("[data-state='offline'] .rt-assistant-pet-3d__canvas", $this->css);
        $this->assertStringContainsString("[data-state='thinking']", $this->css);
        $this->assertStringContainsString("[data-state='listening']", $this->css);
        $this->assertStringContainsString("[data-state='speaking']", $this->css);
        $this->assertStringContainsString("[data-state='curious']", $this->css);
        $this->assertStringContainsString("[data-state='happy']", $this->css);
        $this->assertStringContainsString("[data-state='wave']", $this->css);
        $this->assertStringContainsString('.rt-chatbot__speech-token', $this->css);
        $this->assertStringContainsString('.rt-chatbot__message-actions', $this->css);
        $this->assertMatchesRegularExpression('/\.rt-chatbot__message-actions\s*\{[^}]*flex-wrap:\s*wrap;/s', $this->css);
        $this->assertStringNotContainsString('overflow-x: auto;', $this->css);
        $this->assertStringContainsString('.rt-chatbot__panel-enter-start', $this->css);
        $this->assertStringNotContainsString('color: var(--rt-chatbot-soft);', $this->css);
        $this->assertStringNotContainsString('margin-right:', $this->css);
        $this->assertStringNotContainsString('padding-right: 26rem', $this->css);
    }

    public function test_layout_uses_a_bottom_sheet_below_desktop_and_a_bottom_right_card_on_desktop(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1139\.98px\)\s*\{.*?\.rt-chatbot__panel\s*\{[^}]*bottom:\s*var\(--rt-keyboard-inset[^}]*height:\s*auto;[^}]*border-radius:\s*1\.5rem 1\.5rem 0 0;/s',
            $this->css,
        );
        $this->assertStringContainsString(".rt-chatbot__panel[data-empty-chat='false']", $this->css);
        $this->assertStringContainsString(".rt-chatbot__panel[data-empty-chat='true']", $this->css);

        preg_match('/@media \(min-width: 1140px\)\s*\{.*?\.rt-chatbot__panel\s*\{(?<panel>[^}]*)\}/s', $this->css, $matches);

        $desktopPanel = $matches['panel'] ?? '';
        $this->assertNotSame('', $desktopPanel);
        $this->assertStringContainsString('right:', $desktopPanel);
        $this->assertStringContainsString('bottom:', $desktopPanel);
        $this->assertStringContainsString('height: auto;', $desktopPanel);
        $this->assertStringNotContainsString('top:', $desktopPanel);
        $this->assertStringNotContainsString('left:', $desktopPanel);
    }

    public function test_pagebuilder_ui_context_and_actions_are_mode_specific(): void
    {
        $chatbot = new Chatbot;
        $chatbot->pageRouteName = 'admin.mail-documents.editor';
        $chatbot->pageBuilderAssistantContext = [
            'route_name' => 'admin.mail-documents.editor',
            'format_or_kind' => 'signature',
            'editor_ready' => true,
            'fullscreen_open' => true,
            'read_only' => false,
            'unsaved' => true,
            'selection' => [
                'block_id' => 'rt-mail-heading',
                'tag' => 'h2',
                'text' => 'Deine Zukunft. Unsere gemeinsame Fahrt.',
            ],
        ];

        $context = $this->invokePrivate($chatbot, 'pageBuilderUiContext');
        $mailActions = $this->invokePrivate($chatbot, 'availableQuickActions');
        $chatbot->pageRouteName = 'admin.marketing.creatives.editor';
        $marketingActions = $this->invokePrivate($chatbot, 'availableQuickActions');

        $this->assertTrue($context['active']);
        $this->assertTrue($context['connected']);
        $this->assertSame('Signatur', $context['document_label']);
        $this->assertSame('Überschrift', $context['selection_label']);
        $this->assertTrue($context['unsaved']);
        $this->assertSame(mb_strlen('Deine Zukunft. Unsere gemeinsame Fahrt.'), $context['selection_character_count']);
        $this->assertArrayNotHasKey('selection_excerpt', $context);
        $this->assertSame([
            'pagebuilder_selection',
            'pagebuilder_mail_copy',
            'pagebuilder_validation',
            'pagebuilder_mail_spacing',
        ], array_column($mailActions, 'key'));
        $this->assertSame([
            'pagebuilder_marketing_redesign',
            'pagebuilder_selection',
            'pagebuilder_marketing_copy',
            'pagebuilder_validation',
            'pagebuilder_marketing_media',
        ], array_column($marketingActions, 'key'));
    }

    public function test_pending_change_presentation_is_bounded_and_drops_unknown_fields(): void
    {
        $chatbot = new Chatbot;
        $chatbot->pageRouteName = 'admin.mail-documents.editor';
        $actions = $this->invokePrivate($chatbot, 'sanitizeHistoryActions', [[
            'kind' => 'pending_tool',
            'token' => str_repeat('T', 48),
            'label' => 'Text ändern',
            'route_name' => 'admin.mail-documents.editor',
            'status' => 'error',
            'segment' => 'Überschrift',
            'before' => str_repeat('A', 1300),
            'after' => 'Präziser neuer Text',
            'error' => 'Die Bestätigung ist abgelaufen.',
            'html' => '<script>alert(1)</script>',
        ]]);

        $this->assertCount(1, $actions);
        $this->assertSame('error', $actions[0]['status']);
        $this->assertSame(1200, mb_strlen($actions[0]['before']));
        $this->assertSame('Präziser neuer Text', $actions[0]['after']);
        $this->assertArrayNotHasKey('html', $actions[0]);
    }

    public function test_redesign_result_messages_distinguish_an_uncertain_server_state_from_a_preflight_failure(): void
    {
        $this->app->setLocale('de');
        $chatbot = new Chatbot;

        $reloadRequired = $this->invokePrivate(
            $chatbot,
            'assistantActionResultMessage',
            'pagebuilder',
            'redesign_document',
            'reload_required',
        );
        $storageError = $this->invokePrivate(
            $chatbot,
            'assistantActionResultMessage',
            'pagebuilder',
            'redesign_document',
            'storage_error',
        );

        $this->assertStringContainsString('kann bereits gespeichert sein', $reloadRequired);
        $this->assertStringContainsString('Lade die Seite neu', $reloadRequired);
        $this->assertStringContainsString('prüfe Story, Post und Web', $reloadRequired);
        $this->assertStringNotContainsString('Entwurf bleibt erhalten', $reloadRequired);
        $this->assertStringNotContainsString('Entwurf wurde nicht ersetzt', $reloadRequired);

        $this->assertStringContainsString('vor dem Serveraufruf', $storageError);
        $this->assertStringContainsString('Der gespeicherte Entwurf wurde nicht ersetzt.', $storageError);
        $this->assertStringNotContainsString('kann bereits gespeichert sein', $storageError);
    }

    private function invokePrivate(object $target, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, ...$arguments);
    }
}
