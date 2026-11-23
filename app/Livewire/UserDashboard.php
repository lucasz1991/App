<?php

namespace App\Livewire;

use App\Livewire\Concerns\RendersOperationsDashboard;
use Livewire\Component;

/**
 * Dashboard unter /dashboard fuer alle Zielgruppen ausser Admin
 * (Verwaltung, Mitarbeiter ohne eigenen Arbeitstag als Startseite, Gaeste).
 * Inhalt ist immer das Widget-Raster, siehe RendersOperationsDashboard.
 */
class UserDashboard extends Component
{
    use RendersOperationsDashboard;

    public function mount(): void
    {
        if (auth()->user()->usesAdminLayout()) {
            $this->redirectRoute('admin.dashboard', navigate: true);

            return;
        }

        if (auth()->user()->usesPersonalWorkspaceAsHome()) {
            $this->redirectRoute('operations.mine', request()->query(), navigate: true);
        }
    }

    public function render()
    {
        return $this->renderOperationsDashboard('user');
    }
}
