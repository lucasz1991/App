{{--
    Arbeitsfläche der Wagenliste ab 1024 px.

    AUFBAU — die Reiter entsprechen den Blättern der Excel-Liste v16_5:

        Reiterleiste (Wagenliste · Bremszettel · Meldezettel)   · shrink-0
        Raster  [ Arbeitsbereich | Seitenleiste ]               · flex-1

    Der Arbeitsbereich der Wagenliste besteht aus der einzeiligen
    Zugdatenleiste und der Wagentabelle; die Tabelle bekommt die gesamte
    Resthöhe und scrollt allein. Die Seitenleiste (Summen, Bremsrechnung,
    Prüfung) gilt für alle drei Reiter und lässt sich über die Statusanzeige
    einklappen, wenn die Tabelle die volle Breite braucht.
--}}
<section class="hidden h-full min-h-0 flex-col lg:flex" data-wagon-desktop-workspace>
    <nav class="rt-wagon-section-nav rt-wl-tabbar shrink-0" aria-label="{{ __('app.wagon_workspace') }}">
        <div class="rt-wl-tabs" role="tablist">
            @foreach ([
                ['id' => 'wagons', 'icon' => 'fad fa-train', 'label' => __('app.wagon_list')],
                ['id' => 'brakeSheet', 'icon' => 'fad fa-clipboard-check', 'label' => __('app.brake_sheet')],
                ['id' => 'report', 'icon' => 'fad fa-file-signature', 'label' => __('app.wagon_report')],
            ] as $tab)
                <button
                    type="button"
                    role="tab"
                    :aria-selected="desktopSection === @js($tab['id'])"
                    :data-active="desktopSection === @js($tab['id']) ? 'true' : 'false'"
                    @click="desktopSection = @js($tab['id'])"
                    class="rt-wagon-section-tab rt-wl-tab"
                >
                    <i class="{{ $tab['icon'] }}" aria-hidden="true"></i>
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        <button
            type="button"
            class="rt-wl-status"
            :data-tone="checkSummary.tone"
            :aria-expanded="desktopSummaryOpen ? 'true' : 'false'"
            aria-controls="wagon-summary-aside"
            title="{{ __('app.wagon_summary') }}"
            @click="desktopSummaryOpen = !desktopSummaryOpen"
        >
            <span class="rt-wl-status-dot" aria-hidden="true"></span>
            <span x-text="checkStatusLabel"></span>
            <i class="far text-[10px] opacity-70" :class="desktopSummaryOpen ? 'fa-chevron-right' : 'fa-chevron-left'" aria-hidden="true"></i>
        </button>
    </nav>

    <div class="rt-wagon-desktop-scroll rt-wl-stage min-h-0 flex-1 overflow-hidden">
        <div class="rt-wl-layout" :data-summary="desktopSummaryOpen ? 'true' : 'false'">
            <div class="rt-wl-panels">
                {{-- Wagenliste ------------------------------------------------- --}}
                <div x-show.important="desktopSection === 'wagons'" class="rt-wl-panel" role="tabpanel">
                    <section class="rt-wl-box rt-wl-trainbar" aria-labelledby="wagon-meta-title">
                        <h2 id="wagon-meta-title" class="sr-only">{{ __('app.train_data') }}</h2>

                        <label class="rt-wl-field">
                            <span class="rt-wl-label">{{ __('app.train_number') }}</span>
                            <input x-model="meta.trainNumber" type="text" class="rt-wl-input" autocomplete="off">
                        </label>
                        <div class="rt-wl-field">
                            <span class="rt-wl-label">
                                {{ __('app.date') }}
                                <button type="button" class="rt-wl-link" @click="setToday()">{{ __('app.today') }}</button>
                            </span>
                            <x-ui.forms.date-field x-model="meta.date" :aria-label="__('app.date')" />
                        </div>
                        <label class="rt-wl-field">
                            <span class="rt-wl-label">{{ __('app.from') }}</span>
                            <input x-model="meta.origin" type="text" class="rt-wl-input" autocomplete="off">
                        </label>
                        <label class="rt-wl-field">
                            <span class="rt-wl-label">{{ __('app.to') }}</span>
                            <input x-model="meta.destination" type="text" class="rt-wl-input" autocomplete="off">
                        </label>
                        <label class="rt-wl-field">
                            <span class="rt-wl-label">{{ __('app.reference') }}</span>
                            <input x-model="meta.reference" type="text" class="rt-wl-input" autocomplete="off">
                        </label>
                        <label class="rt-wl-field">
                            <span class="rt-wl-label">{{ __('app.customer') }}</span>
                            <input x-model="meta.customer" type="text" class="rt-wl-input" autocomplete="off">
                        </label>
                    </section>

                    @include('livewire.operations.partials.wagon-sheet-grid')
                </div>

                {{-- Bremszettel ------------------------------------------------ --}}
                <div x-show.important="desktopSection === 'brakeSheet'" x-cloak class="rt-wl-panel rt-wl-panel--scroll" role="tabpanel">
                    <div class="rt-wl-form-grid rt-wl-form-grid--2">
                        <div class="rt-wl-form-grid content-start">
                            @include('livewire.operations.partials.wagon-brake-inputs', ['scope' => 'desktop'])
                        </div>
                        <div class="rt-wl-form-grid content-start">
                            @include('livewire.operations.partials.wagon-brake-result', ['scope' => 'desktop'])
                        </div>
                    </div>

                    @include('livewire.operations.partials.wagon-special-information', [
                        'columns' => 2,
                        'scope' => 'desktop',
                    ])
                </div>

                {{-- Meldezettel ------------------------------------------------ --}}
                <div x-show.important="desktopSection === 'report'" x-cloak class="rt-wl-panel rt-wl-panel--scroll" role="tabpanel">
                    <div class="rt-wl-form-grid rt-wl-form-grid--2">
                        @include('livewire.operations.partials.wagon-report-form', ['scope' => 'desktop'])
                    </div>
                </div>
            </div>

            <aside
                id="wagon-summary-aside"
                x-show="desktopSummaryOpen"
                x-cloak
                class="rt-wl-aside"
                aria-label="{{ __('app.wagon_summary') }}"
            >
                @include('livewire.operations.partials.wagon-summary-panel', [
                    'scope' => 'desktop',
                    'sectionClass' => 'rt-wl-aside-section',
                ])
            </aside>
        </div>
    </div>
</section>
