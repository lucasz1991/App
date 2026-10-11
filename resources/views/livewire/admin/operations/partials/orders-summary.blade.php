@php
    $orderMetrics = [
        ['key' => 'active', 'count' => $openCount, 'label' => 'Aktiv', 'description' => 'aktive Aufträge'],
        ['key' => 'upcoming', 'count' => $startsSoonCount, 'label' => 'Demnächst', 'description' => 'demnächst beginnende Aufträge'],
        ['key' => 'running', 'count' => $inProgressCount, 'label' => 'Laufend', 'description' => 'laufende Aufträge'],
        ['key' => 'unplanned', 'count' => $withoutShiftsCount, 'label' => 'Ohne Schichten', 'description' => 'Aufträge ohne Schichten'],
    ];
    // Only the narrow-screen display is abbreviated; assistive text and the
    // disclosure always retain the exact, unfiltered all-bestand counts.
    $compactOrderCount = static function (int $count): string {
        if ($count >= 1_000_000) {
            return (string) floor($count / 1_000_000).'M';
        }

        return $count >= 1_000 ? (string) floor($count / 1_000).'k' : (string) $count;
    };
    $orderMetricsLabel = collect($orderMetrics)->map(fn ($metric) => number_format($metric['count'], 0, ',', '.').' '.$metric['description'])->implode(', ');
@endphp

<section class="rt-disposition-summary rt-orders-summary" aria-label="Aufträge im gesamten Bestand" data-orders-summary>
    <div class="rt-orders-summary__desktop" data-orders-summary-desktop>
        @foreach($orderMetrics as $metric)
            <div class="rt-disposition-summary__item" role="group" aria-label="{{ $metric['count'] }} {{ $metric['description'] }}" data-orders-kpi="{{ $metric['key'] }}">
                <span class="rt-orders-summary__dot" aria-hidden="true"></span>
                <span class="rt-disposition-summary__value">{{ number_format($metric['count'], 0, ',', '.') }}</span>
                <span class="rt-disposition-summary__label">{{ $metric['label'] }}</span>
            </div>
        @endforeach
    </div>

    <x-ui.dropdown.anchor-dropdown
        align="left" width="64" :offset="6" :max-height="320"
        dropdown-id="orders-kpis-{{ $this->getId() }}" layer-group="operations-orders"
        content-role="dialog" content-label="Aufträge im gesamten Bestand"
        dropdown-classes="rt-orders-kpi-dropdown" content-classes="rt-orders-kpi-panel"
        data-orders-summary-mobile
    >
        <x-slot:trigger>
            <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="rt-orders-kpi-trigger"
                aria-label="Auftragskennzahlen anzeigen: {{ $orderMetricsLabel }}" title="Auftragskennzahlen anzeigen" data-orders-kpi-trigger>
                <span class="rt-orders-kpi-trigger__counts" aria-hidden="true">
                    @foreach($orderMetrics as $metric)
                        <span class="rt-orders-kpi-trigger__item" data-orders-kpi="{{ $metric['key'] }}">
                            <span @class(['rt-orders-kpi-trigger__value', 'rt-orders-kpi-trigger__value--compact' => $metric['count'] >= 1_000])>{{ $compactOrderCount($metric['count']) }}</span>
                            <span class="rt-orders-summary__dot"></span>
                        </span>
                    @endforeach
                </span>
            </x-ui.buttons.button-basic>
        </x-slot:trigger>
        <x-slot:content>
        <div data-orders-kpi-content>
            <p class="rt-orders-kpi-panel__title">Aufträge im gesamten Bestand</p>
            <dl>
                @foreach($orderMetrics as $metric)
                    <div class="rt-orders-kpi-panel__item" data-orders-kpi="{{ $metric['key'] }}">
                        <dt><span class="rt-orders-summary__dot" aria-hidden="true"></span>{{ $metric['label'] }}</dt>
                        <dd>{{ number_format($metric['count'], 0, ',', '.') }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
</section>
