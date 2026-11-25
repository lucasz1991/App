<?php

namespace App\Livewire\Concerns;

use App\Models\User;

/**
 * Einheitliche Admin-Prüfung für Livewire-Komponenten im Adminbereich.
 *
 * Eine Livewire-Komponente lebt über viele Anfragen; die Prüfung gehört deshalb
 * in jede Aktion (bzw. mount/render/boot), nicht nur in die Route. Verlangt
 * Admin- oder Super-Admin-Rolle und ein aktives Konto.
 */
trait RequiresAdmin
{
    protected function adminActor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isActive() && $actor->isAdmin(), 403);

        return $actor;
    }
}
