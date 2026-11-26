<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Einstellungsseite: Reiter-/Abschnittspruefung, Layout und Uebersicht.
 *
 * Die einzelnen Abschnitte (Firmendaten, Einladungen, System, Assistent,
 * Toene, Assistent-Laufzeit, Anrufe, E-Mails) sind eigene Komponenten unter
 * App\Livewire\Admin\Settings\*; sie laden, pruefen und speichern selbst.
 */
class Settings extends Component
{
    #[Locked]
    public string $initialTab = '';

    #[Locked]
    public string $initialSection = '';

    /**
     * Kennzeichnet Snapshots der zerlegten Seite (Abschnitte als eigene
     * Komponenten unter App\Livewire\Admin\Settings\*). Eine vor dem Umbau
     * geoeffnete Seite traegt den Wert nicht; siehe hydrate().
     */
    #[Locked]
    public bool $sectioned = false;

    public function mount(): void
    {
        Gate::authorize('settings.manage');
        $tab = request()->query('tab', '');
        $section = request()->query('section', '');
        $sections = [
            'activities' => [], 'overview' => [], 'company' => ['company'], 'users' => ['invitations', 'permissions'],
            'system' => ['system', 'assistant', 'sounds'], 'email' => ['mails', 'templates'],
        ];
        if ($this->isSuperAdmin()) {
            $sections += ['device-management' => ['device-deployment'], 'ai-disposition' => ['configuration'], 'dropbox' => [], 'tomtom' => [], 'employee-locations' => [], 'superadmin' => ['assistant-runtime', 'calls', 'assistant-knowledge']];
        }
        abort_unless(is_string($tab) && is_string($section), 422);
        if ($tab !== '') {
            abort_unless(array_key_exists($tab, $sections), 403);
            abort_unless($section === '' || in_array($section, $sections[$tab], true), 403);
            $this->initialTab = $tab;
            $this->initialSection = $section;
        }
        $this->sectioned = true;
    }

    /**
     * Uebergang nach dem Umbau: Eine vorher geoeffnete Einstellungsseite
     * schickt Feldaenderungen und save-Aufrufe noch an diese Seite, obwohl
     * Felder und Aktionen jetzt in den Abschnitts-Komponenten liegen. Statt
     * eines Serverfehlers (unbekannte Property/Methode) laeuft die Anfrage
     * mit 419 ab: Der Browser zeigt den Hinweis zur abgelaufenen Sitzung
     * (kein automatisches Neuladen, siehe resources/js/app.js), gespeichert
     * wird nichts.
     */
    public function hydrate(): void
    {
        abort_unless($this->sectioned, 419);
    }

    /**
     * Betriebsnahe KI-, Provider- und Anrufwerte gehoeren nur Benutzer #1.
     * Wegen des allgemeinen Admin-Gate-Bypasses reicht `settings.manage`
     * fuer diese Grenze ausdruecklich nicht aus. Die Seite entscheidet damit
     * nur, welche Reiter sie zeigt; die Superadmin-Abschnitte pruefen die
     * Grenze in mount und in jeder Aktion selbst.
     */
    private function isSuperAdmin(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function render()
    {
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings', [
            'isSuperAdmin' => $this->isSuperAdmin(),
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
