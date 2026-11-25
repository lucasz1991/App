<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Gate;

/**
 * Einheitliche Akteursauflösung für die Arbeitsbereich-Komponenten.
 *
 * Livewire hält den angemeldeten Nutzer über mehrere Anfragen hinweg nicht
 * frisch; Rechte- oder Statusänderungen müssen aber sofort greifen. Deshalb
 * wird der Nutzer hier für jede Aktion neu geladen und immer abgewiesen, wenn
 * er inaktiv ist (status = false) – auch ohne angegebene Fähigkeit.
 *
 * OperationsAccess::authorize() prüft den Status ebenfalls; der Concern prüft
 * ihn trotzdem vorab, damit auch Aufrufe ohne Fähigkeit sicher sind.
 */
trait ResolvesOperationsActor
{
    /**
     * @param  string|list<string>|null  $ability  eine Fähigkeit (Gate-authorize) oder eine Liste,
     *                                             von der mindestens eine erfüllt sein muss
     * @param  bool  $requireReady  zusätzlich das Operations-Schema verlangen (503, wenn es fehlt)
     */
    protected function freshActor(string|array|null $ability = null, bool $requireReady = true): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $actor = $user->fresh();
        abort_unless($actor instanceof User && $actor->isActive(), 403);

        if (is_string($ability)) {
            OperationsAccess::authorize($actor, $ability);
        } elseif (is_array($ability)) {
            abort_unless($ability !== [] && Gate::forUser($actor)->any($ability), 403);
        }

        if ($requireReady) {
            OperationsAccess::requireReady();
        }

        return $actor;
    }
}
