<?php

namespace App\Livewire\Admin\Settings;

use App\Support\Sound\SoundLibrary;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Einstellungen > System > Toene. Der Akkordeon-Rahmen bleibt in der Seite. */
class SoundsSection extends Component
{
    /**
     * Systemweite Standard-Ton-Zuordnung (Ereignis => Signatur).
     *
     * @var array<string, string>
     */
    public array $sounds = [];

    public function mount(): void
    {
        Gate::authorize('settings.manage');

        $this->sounds = SoundLibrary::systemMap();
    }

    /**
     * Systemweite Standard-Ton-Zuordnung speichern (Ereignis => Signatur).
     */
    public function saveSounds(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'sounds' => ['required', 'array'],
            'sounds.*' => ['required', 'string', Rule::in(SoundLibrary::signatures())],
        ]);

        SoundLibrary::saveSystemMap($this->sounds);

        $this->dispatch(
            'sound-settings-saved',
            fields: array_map(fn (string $event): string => 'sounds.'.$event, SoundLibrary::events()),
        );

        // Neue wirksame Zuordnung sofort im Browser uebernehmen (rt-sounds.js).
        $this->dispatch('rt-sounds:map', map: SoundLibrary::mapFor(auth()->user()));
        $this->skipRender();
    }

    public function render()
    {
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings.sounds-section');
    }
}
