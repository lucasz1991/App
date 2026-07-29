<?php

namespace App\Livewire\Settings;

use App\Models\UserSoundPreference;
use App\Support\Sound\SoundLibrary;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Persoenliche Ton-Zuordnung im Profil. Leerer Wert = Systemstandard;
 * gespeichert werden ausschliesslich bewusste Abweichungen, damit spaetere
 * Aenderungen des Standards weiterhin greifen.
 */
class SoundPreferences extends Component
{
    /** @var array<string, string> Ereignis => Signatur ('' = Systemstandard) */
    public array $sounds = [];

    public function mount(): void
    {
        $overrides = auth()->user()
            ->soundPreferences
            ->pluck('signature', 'event');

        foreach (SoundLibrary::events() as $event) {
            $this->sounds[$event] = (string) ($overrides[$event] ?? '');
        }
    }

    public function save(): void
    {
        $this->validate([
            'sounds' => ['required', 'array'],
            'sounds.*' => ['nullable', 'string', Rule::in([...SoundLibrary::signatures(), ''])],
        ]);

        $user = auth()->user();

        foreach (SoundLibrary::events() as $event) {
            $signature = (string) ($this->sounds[$event] ?? '');

            if ($signature === '') {
                // Zurueck zum Systemstandard: Abweichung entfernen.
                UserSoundPreference::query()
                    ->where('user_id', $user->id)
                    ->where('event', $event)
                    ->delete();

                continue;
            }

            UserSoundPreference::updateOrCreate(
                ['user_id' => $user->id, 'event' => $event],
                ['signature' => $signature],
            );
        }

        // Beziehung neu laden, sonst rechnet mapFor() mit dem alten Stand.
        $user->unsetRelation('soundPreferences');

        $this->dispatch(
            'sound-preferences-saved',
            fields: array_map(fn (string $event): string => 'sounds.'.$event, SoundLibrary::events()),
        );
        $this->dispatch('rt-sounds:map', map: SoundLibrary::mapFor($user));
    }

    public function render(): View
    {
        return view('livewire.settings.sound-preferences', [
            'systemMap' => SoundLibrary::systemMap(),
        ]);
    }
}
