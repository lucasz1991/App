<?php

namespace App\Livewire\Profile;

use Illuminate\Validation\Rule;
use Livewire\Component;

class ProfileTabContent extends Component
{
    public string $tab;

    public bool $ready = false;

    public function mount(string $tab): void
    {
        validator(
            ['tab' => $tab],
            ['tab' => ['required', Rule::in(['personal', 'security', 'app', 'sessions'])]],
        )->validate();

        $this->tab = $tab;

        // Der beim Seitenaufruf aktive Tab wird sofort serverseitig gerendert.
        // Das spart den zusaetzlichen Livewire-Roundtrip genau dort, wo der
        // Nutzer hinschaut, macht den Inhalt ohne JavaScript verfuegbar und
        // haelt ihn fuer Suchmaschinen und Tests im ersten HTML. Alle uebrigen
        // Tabs laden weiterhin verzoegert.
        $this->ready = $tab === $this->requestedTab();
    }

    /** Der ueber ?tab= angeforderte Bereich; ohne Angabe der erste. */
    protected function requestedTab(): string
    {
        $requested = (string) request()->query('tab', 'personal');

        return in_array($requested, ['personal', 'security', 'app', 'sessions'], true)
            ? $requested
            : 'personal';
    }

    public function load(): void
    {
        if ($this->ready) {
            return;
        }

        $this->ready = true;
    }

    public function render()
    {
        return view('livewire.profile.profile-tab-content');
    }
}
