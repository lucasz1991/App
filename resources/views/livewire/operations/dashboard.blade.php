@section('title', 'Dashboard')
{{--
    Ab hier ist das Dashboard eine individuelle Widget-Sammlung
    (App\Livewire\Dashboard\WidgetGrid) statt fest verdrahteter Abschnitte:
    jede Person waehlt aus den ihr laut Rolle erlaubten Features
    (App\Support\Dashboard\WidgetRegistry) aus, ordnet per Drag&Drop oder
    Pfeiltasten an und stellt zwei Groessen ein - gespeichert in
    dashboard_widget_placements (App\Support\Dashboard\DashboardLayout).
    Cockpit und MyWork bleiben als eigene Seiten/Komponenten bestehen
    (operations.mine, operations.workspace); ihre Kennzahlen liefert hier
    App\Support\Dashboard\WidgetDataProvider aus denselben Modellen neu.
--}}
<x-ui.page :auto-intro="false" :welcome-intro="false" content-class="rt-ops" data-native-dashboard>
    <livewire:dashboard.widget-grid />
</x-ui.page>
