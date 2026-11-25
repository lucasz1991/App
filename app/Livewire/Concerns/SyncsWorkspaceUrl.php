<?php

namespace App\Livewire\Concerns;

use App\Support\Operations\OperationsPages;

/**
 * Einheitliche URL-Synchronisierung für Arbeitsplatz-Komponenten unter /arbeitsplatz/ansicht/{page}.
 *
 * Löst das Browser-Ereignis "rt-workspace-url" mit genau einem Parameter "url" aus.
 * Die URL entsteht immer über OperationsPages::url() (leere Werte und null fallen weg,
 * Sonderfälle wie shifts/ai-intake bleiben dort). Das Empfangsskript in
 * livewire/operations/page-workspace.blade.php schreibt sie per history.replaceState
 * und löst danach "rt-workspace-url-updated" aus (app.js, staff-timeline.js).
 *
 * Wann synchronisiert wird (eingebettet, Mount, Rechteprüfung), entscheidet weiterhin
 * die Komponente in ihrer eigenen syncUrl()-Methode.
 */
trait SyncsWorkspaceUrl
{
    protected function dispatchWorkspaceUrl(string $page, array $parameters = []): void
    {
        $this->dispatch('rt-workspace-url', url: OperationsPages::url($page, $parameters));
    }
}
