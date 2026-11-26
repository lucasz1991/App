<?php

namespace App\Livewire\Admin\Settings;

use App\Livewire\Concerns\AuthorizesSuperAdminSettings;
use App\Support\Calls\CallSettings;
use Livewire\Component;

/**
 * Einstellungen > Superadmin > Anrufe. Nur Benutzer #1: mount, jede Aktion
 * und render pruefen die Grenze selbst. Der Akkordeon-Rahmen bleibt in der Seite.
 */
class CallsSection extends Component
{
    use AuthorizesSuperAdminSettings;

    /**
     * Betriebswerte der Anruffunktion (Klingeldauer, Token-Gueltigkeit, ...).
     *
     * @var array<string, int>
     */
    public array $calls = [];

    public function mount(): void
    {
        $this->authorizeSuperAdminSettings();

        $this->calls = CallSettings::all(uncached: true);
    }

    /** Betriebswerte der Anruffunktion speichern. */
    public function saveCalls(): void
    {
        $this->authorizeSuperAdminSettings();

        $rules = [];

        foreach (CallSettings::FIELDS as $key => [, $min, $max]) {
            $rules["calls.{$key}"] = ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        $this->validate($rules);

        CallSettings::save($this->calls);

        // Zurueckgelesen, damit die Oberflaeche exakt zeigt, was gilt.
        $this->calls = CallSettings::all(uncached: true);

        $this->dispatch('call-settings-saved');
        $this->skipRender();
    }

    public function render()
    {
        $this->authorizeSuperAdminSettings();

        return view('livewire.admin.settings.calls-section');
    }
}
