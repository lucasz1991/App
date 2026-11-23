<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RendersOperationsDashboard;
use Livewire\Component;

/**
 * Admin-Dashboard unter /administrator/dashboard. Inhalt ist dasselbe
 * Widget-Raster wie fuer alle anderen Zielgruppen
 * (siehe RendersOperationsDashboard) - nur im Admin-Layoutbereich.
 */
class Dashboard extends Component
{
    use RendersOperationsDashboard;

    public function render()
    {
        // Die Route traegt bereits role:admin — diese Pruefung schuetzt den
        // Fall, dass die Komponente anderswo eingebunden wird.
        abort_unless(auth()->user()?->isAdmin(), 403);

        return $this->renderOperationsDashboard('admin');
    }
}
