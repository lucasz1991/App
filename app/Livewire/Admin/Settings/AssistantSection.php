<?php

namespace App\Livewire\Admin\Settings;

use App\Support\Ai\AssistantSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Einstellungen > System > Chatbot-Assistent (Freigabe). Der Akkordeon-Rahmen bleibt in der Seite. */
class AssistantSection extends Component
{
    /** Systemweite Freigabe fuer RailTime Assist. */
    public bool $assistantEnabled = true;

    public function mount(): void
    {
        Gate::authorize('settings.manage');

        $this->assistantEnabled = AssistantSettings::enabled(uncached: true);
    }

    public function saveAssistant(): void
    {
        Gate::authorize('settings.manage');

        AssistantSettings::setEnabled($this->assistantEnabled);

        $this->dispatch('assistant-settings-saved', fields: ['assistantEnabled']);
    }

    public function updatedAssistantEnabled(): void
    {
        $this->saveAssistant();
    }

    public function render()
    {
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings.assistant-section');
    }
}
