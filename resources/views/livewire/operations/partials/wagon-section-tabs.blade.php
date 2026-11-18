{{--
    Reiter der Arbeitsfläche im Kopf des Editors (nur ab 1024 px): die drei
    Blätter der Excel-Liste v16_5, jeweils mit ihrem Kurzstand (Fehler oder
    Wagenzahl · Bremshundertstel · erledigte Aufträge). Auf dem Telefon führt
    die geführte Eingabe durch dieselben Abschnitte.
--}}
<nav class="rt-wl-tabs rt-wl-tabs--main" role="tablist" aria-label="{{ __('app.wagon_workspace') }}">
    @foreach ([
        ['id' => 'wagons', 'label' => __('app.wagon_list')],
        ['id' => 'brakeSheet', 'label' => __('app.brake_sheet')],
        ['id' => 'report', 'label' => __('app.wagon_report')],
    ] as $tab)
        <button
            type="button"
            role="tab"
            id="wagon-tab-{{ $tab['id'] }}"
            aria-controls="wagon-panel-{{ $tab['id'] }}"
            :aria-selected="desktopSection === @js($tab['id'])"
            :data-active="desktopSection === @js($tab['id']) ? 'true' : 'false'"
            @click="desktopSection = @js($tab['id'])"
            class="rt-wl-tab"
        >
            <span>{{ $tab['label'] }}</span>
            <small
                x-show="sheetStatus.{{ $tab['id'] }}.text !== ''"
                x-cloak
                :data-tone="sheetStatus.{{ $tab['id'] }}.tone"
                x-text="sheetStatus.{{ $tab['id'] }}.text"
            ></small>
        </button>
    @endforeach
</nav>
