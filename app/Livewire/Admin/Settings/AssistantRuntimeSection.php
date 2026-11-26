<?php

namespace App\Livewire\Admin\Settings;

use App\Livewire\Concerns\AuthorizesSuperAdminSettings;
use App\Services\Ai\AssistantSpeechRouter;
use App\Support\Ai\AssistantSpeechSettings;
use App\Support\Ai\OpenRouterSettings;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Einstellungen > Superadmin > Assistent-Laufzeit: Sprachweg und OpenRouter.
 *
 * Beide Bloecke bleiben EINE Komponente, weil das Speichern der
 * OpenRouter-Werte die Sprachdiagnose ($assistantSpeechStatus) des ersten
 * Blocks neu berechnet. Nur Benutzer #1: mount, jede Aktion und render
 * pruefen die Grenze selbst. Der Akkordeon-Rahmen bleibt in der Seite.
 */
class AssistantRuntimeSection extends Component
{
    use AuthorizesSuperAdminSettings;

    /** Globaler, nur vom Superadmin aenderbarer Sprachweg. */
    public string $assistantSpeechRouting = '';

    /** @var array<string, mixed> Normalisierte Diagnose ohne Geheimnisse. */
    public array $assistantSpeechStatus = [];

    /**
     * OpenRouter-Zugang und Modellprofile — nur fuer den Super-Admin.
     *
     * @var array<string, mixed>
     */
    public array $openRouter = [];

    public function mount(): void
    {
        $this->authorizeSuperAdminSettings();

        $this->assistantSpeechRouting = AssistantSpeechSettings::mode(uncached: true);
        // Opening any settings tab must not trigger an unrelated network probe.
        $this->assistantSpeechStatus = app(AssistantSpeechRouter::class)->capabilities(passive: true);
        $this->openRouter = OpenRouterSettings::forForm();
    }

    /** OpenRouter-Verbindung und Modellprofile speichern. */
    public function saveOpenRouter(): void
    {
        $this->authorizeSuperAdminSettings();

        $rules = [
            'openRouter.api_url' => ['required', 'url', 'max:2048'],
            'openRouter.api_key' => ['nullable', 'string', 'max:512'],
            'openRouter.referer_url' => ['nullable', 'url', 'max:2048'],
            'openRouter.model_title' => ['nullable', 'string', 'max:255'],
            'openRouter.stream_enabled' => ['boolean'],
            'openRouter.tts_voice' => ['nullable', 'string', 'max:255'],
        ];

        foreach (array_keys(OpenRouterSettings::MODEL_FIELDS) as $field) {
            $rules["openRouter.{$field}"] = ['nullable', 'string', 'max:255'];
        }

        foreach (OpenRouterSettings::RUNTIME_FIELDS as $field => [, $min, $max]) {
            $rules["openRouter.{$field}"] = $field === 'temperature'
                ? ['required', 'numeric', "min:{$min}", "max:{$max}"]
                : ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        $this->validate($rules);

        OpenRouterSettings::save($this->openRouter);
        $this->assistantSpeechStatus = app(AssistantSpeechRouter::class)->capabilities(refresh: true);

        // Zurueckgelesen, damit die Oberflaeche exakt zeigt, was gilt — und
        // der Schluessel weiterhin nur als Maske erscheint.
        $this->openRouter = OpenRouterSettings::forForm();

        $this->dispatch('openrouter-settings-saved');
        $this->skipRender();
    }

    public function saveAssistantSpeechRouting(): void
    {
        $this->authorizeSuperAdminSettings();

        $this->validate([
            'assistantSpeechRouting' => [
                'required',
                'string',
                Rule::in(AssistantSpeechSettings::modes()),
            ],
        ]);

        AssistantSpeechSettings::setMode($this->assistantSpeechRouting);
        $this->assistantSpeechRouting = AssistantSpeechSettings::mode(uncached: true);
        $this->assistantSpeechStatus = app(AssistantSpeechRouter::class)->capabilities(refresh: true);

        $this->dispatch('assistant-speech-settings-saved', fields: ['assistantSpeechRouting']);
    }

    public function updatedAssistantSpeechRouting(): void
    {
        $this->saveAssistantSpeechRouting();
    }

    public function refreshAssistantSpeechStatus(): void
    {
        $this->authorizeSuperAdminSettings();
        $this->assistantSpeechStatus = app(AssistantSpeechRouter::class)->capabilities(refresh: true);
    }

    public function render()
    {
        $this->authorizeSuperAdminSettings();

        return view('livewire.admin.settings.assistant-runtime-section');
    }
}
