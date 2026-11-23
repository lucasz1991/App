@section('title', 'Dashboard')
{{--
    Ab hier ist das Dashboard eine individuelle Widget-Sammlung
    (App\Livewire\Dashboard\WidgetGrid) statt fest verdrahteter Abschnitte:
    jede Person waehlt aus den ihr laut Rolle erlaubten Features
    (App\Support\Dashboard\WidgetRegistry) aus, ordnet per Drag&Drop oder
    Pfeiltasten an und stellt zwei Groessen ein - gespeichert in
    dashboard_widget_placements (App\Support\Dashboard\DashboardLayout).
    MyWork bleibt als eigene Seite bestehen (operations.mine);
    ihre Kennzahlen liefert hier
    App\Support\Dashboard\WidgetDataProvider aus denselben Modellen neu.

    Diese View ist das EINZIGE Dashboard - fuer Admin, Verwaltung,
    Mitarbeiter und Gaeste, mit und ohne Operations-Schema
    (App\Livewire\Concerns\RendersOperationsDashboard). Gaeste und
    Umgebungen ohne Schema unterscheiden sich nur in der Widget-Auswahl,
    die WidgetRegistry trifft.
--}}
<x-ui.page :auto-intro="false" :welcome-intro="false" content-class="rt-ops" data-native-dashboard>
    {{-- Bewusst ohne Titel und Aktionen: dadurch entfaellt der ganze
         Seitenkopf (Zurueck- und Info-Knopf) - das Dashboard beginnt direkt
         mit den Widgets. --}}
    @if ($showGuestWelcome ?? false)
        <x-ui.welcome-intro
            :initially-open="\App\Support\PageViews::firstVisit(auth()->user(), \App\Support\WelcomeIntroCatalog::TRACKING_KEY)"
        />
    @endif
    <livewire:dashboard.widget-grid />
</x-ui.page>
