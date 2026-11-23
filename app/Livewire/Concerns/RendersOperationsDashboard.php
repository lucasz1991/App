<?php

namespace App\Livewire\Concerns;

/**
 * Gemeinsamer Render-Weg fuer UserDashboard und Admin\Dashboard.
 *
 * Es gibt genau ein Dashboard: das Widget-Raster (Livewire
 * dashboard.widget-grid). Welche Widgets eine Person sieht, entscheidet
 * allein WidgetRegistry - nach Rechten, nach Zielgruppe (Gaeste sehen nur
 * persoenliche Informationen) und danach, ob das Operations-Schema
 * eingespielt ist (operations_*-Widgets fallen ohne Schema still weg).
 * Dadurch braucht es fuer Gaeste und fuer Umgebungen ohne Operations-Schema
 * keine eigenen Ausweich-Dashboards mehr.
 */
trait RendersOperationsDashboard
{
    /**
     * @param  'user'|'admin'  $area  Layoutbereich (Sidebar/Topbar) fuer layouts.master
     */
    protected function renderOperationsDashboard(string $area)
    {
        return view('livewire.operations.dashboard', [
            // Gaeste hatten ihr Welcome-Intro bisher im eigenen
            // Gaeste-Dashboard; das bleibt ihnen erhalten.
            'showGuestWelcome' => auth()->user()?->dashboardAudience() === 'guest',
        ])->layout('layouts.master', ['area' => $area]);
    }
}
