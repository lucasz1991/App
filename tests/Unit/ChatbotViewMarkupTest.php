<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
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
        $this->assertStringContainsString("isDesktopDocked ? 'complementary' : 'dialog'", $this->view);
        $this->assertStringContainsString('x-trap.inert.noscroll="open && !isDesktopDocked"', $this->view);
        $this->assertStringContainsString('aria-labelledby="railtime-chatbot-title"', $this->view);
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
        $this->assertStringContainsString('x-data="railtimeAssistantPet3d()"', $this->view);
        $this->assertStringContainsString('data-assistant-pet-3d-slot="launcher"', $this->view);
        $this->assertStringContainsString('data-assistant-pet-3d-slot="header"', $this->view);
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
}
