<?php

namespace App\Livewire\Profile;

use Illuminate\Validation\Rule;
use Livewire\Component;

class ProfileTabContent extends Component
{
    /** Einzige Quelle der Profil-Tabs (Reihenfolge = Anzeige). */
    public const TABS = ['personal', 'security', 'app', 'outlook'];

    /** Alte bzw. alternative ?tab=-Werte und ihr Ziel-Tab. */
    public const ALIASES = ['sessions' => 'security'];

    public string $tab;

    public bool $ready = false;

    public function mount(string $tab): void
    {
        validator(
            ['tab' => $tab],
            ['tab' => ['required', Rule::in(self::TABS)]],
        )->validate();

        $this->tab = $tab;

        // Der beim Seitenaufruf aktive Tab wird sofort serverseitig gerendert.
        // Das spart den zusaetzlichen Livewire-Roundtrip genau dort, wo der
        // Nutzer hinschaut, macht den Inhalt ohne JavaScript verfuegbar und
        // haelt ihn fuer Suchmaschinen und Tests im ersten HTML. Alle uebrigen
        // Tabs laden weiterhin verzoegert.
        $this->ready = $tab === $this->requestedTab();
    }

    /** Beschriftung und Icon je Tab fuer profile/show.blade.php. */
    public static function tabDefinitions(): array
    {
        return [
            'personal' => ['label' => __('app.personal_data'), 'icon' => 'fad fa-user-edit'],
            'security' => ['label' => __('app.security'), 'icon' => 'fad fa-shield-alt'],
            'app' => ['label' => __('app.settings'), 'icon' => 'fad fa-cog'],
            'outlook' => ['label' => 'Outlook', 'icon' => 'fad fa-envelope'],
        ];
    }

    /** Loest einen ?tab=-Wert ueber ALIASES auf; Unbekanntes faellt auf den ersten Tab. */
    public static function resolveTab(mixed $requested): string
    {
        $requested = is_string($requested) ? $requested : self::TABS[0];
        $requested = self::ALIASES[$requested] ?? $requested;

        return in_array($requested, self::TABS, true) ? $requested : self::TABS[0];
    }

    /** Der ueber ?tab= angeforderte Bereich; ohne Angabe der erste. */
    protected function requestedTab(): string
    {
        return self::resolveTab(request()->query('tab', self::TABS[0]));
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
