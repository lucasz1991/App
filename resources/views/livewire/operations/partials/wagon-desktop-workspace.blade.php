{{--
    Arbeitsfläche der Wagenliste ab 1024 px.

    AUFBAU — auf allen drei Seiten gleich (Entwurf 5, „ruhige Fassung“):

        Zugzeile  (Zugdaten · Zug als Streifen · Summen)          · shrink-0
        Raster    [ Arbeitsbereich | rechte Spalte ]              · flex-1

    Die Reiter stehen im Kopf des Editors (wagon-section-tabs) und entsprechen
    den Blättern der Excel-Liste v16_5; die Kennungen bleiben 'wagons',
    'brakeSheet' und 'report'. Links steht die Arbeit, rechts immer dieselbe
    schmale Spalte mit Stand und nächstem Schritt (wagon-desktop-rail).

    Die Wagentabelle bekommt die gesamte Resthöhe und scrollt allein;
    Bremszettel und Meldezettel scrollen als Seite. Auf der Wagenliste lässt
    sich die rechte Spalte ausblenden, wenn die Tabelle die volle Breite
    braucht.
--}}
<section class="rt-wl-shell hidden h-full min-h-0 flex-col lg:flex" data-wagon-desktop-workspace>
    {{-- Zugzeile: Zugdaten zum direkten Bearbeiten, der Zug in der Reihung der Liste, Summen --}}
    <section class="rt-wl-strip" aria-labelledby="wagon-meta-title">
        <h2 id="wagon-meta-title" class="sr-only">{{ __('app.train_data') }}</h2>

        <div class="rt-wl-ident">
            <div class="rt-wl-ident-main">
                <label class="rt-wl-inline rt-wl-inline--number">
                    <span>{{ $labels['trainLabel'] }}</span>
                    <input
                        x-model="meta.trainNumber"
                        type="text"
                        autocomplete="off"
                        placeholder="{{ __('app.train_number') }}"
                        aria-label="{{ __('app.train_number') }}"
                        :size="Math.max(6, String(meta.trainNumber || '').length + 1)"
                    >
                </label>
                <input
                    x-model="meta.origin"
                    type="text"
                    autocomplete="off"
                    class="rt-wl-inline-input"
                    placeholder="{{ __('app.from') }}"
                    aria-label="{{ __('app.from') }}"
                    :size="Math.max(5, String(meta.origin || '').length + 1)"
                >
                <i class="far fa-long-arrow-right rt-wl-ident-arrow" aria-hidden="true"></i>
                <input
                    x-model="meta.destination"
                    type="text"
                    autocomplete="off"
                    class="rt-wl-inline-input"
                    placeholder="{{ __('app.to') }}"
                    aria-label="{{ __('app.to') }}"
                    :size="Math.max(5, String(meta.destination || '').length + 1)"
                >
            </div>

            <div class="rt-wl-ident-meta">
                <div class="rt-wl-ident-date">
                    <x-ui.forms.date-field x-model="meta.date" :aria-label="__('app.date')" />
                    <button type="button" class="rt-wl-link" @click="setToday()">{{ __('app.today') }}</button>
                </div>
                <input
                    x-model="meta.customer"
                    type="text"
                    autocomplete="off"
                    class="rt-wl-inline-input"
                    placeholder="{{ __('app.customer') }}"
                    aria-label="{{ __('app.customer') }}"
                    :size="Math.max(7, String(meta.customer || '').length + 1)"
                >
                <input
                    x-model="meta.reference"
                    type="text"
                    autocomplete="off"
                    class="rt-wl-inline-input"
                    placeholder="{{ __('app.reference') }}"
                    aria-label="{{ __('app.reference') }}"
                    :size="Math.max(9, String(meta.reference || '').length + 1)"
                >
            </div>
        </div>

        <div
            class="rt-wl-train"
            role="group"
            aria-label="{{ __('app.wagon_train_strip') }}"
            :data-dense="trainCars.length > 18 ? 'true' : 'false'"
        >
            <span class="rt-wl-loco" aria-hidden="true"><i></i></span>
            <template x-for="car in trainCars" :key="car.index">
                <button
                    type="button"
                    class="rt-wl-car"
                    :data-shape="car.shape"
                    :data-state="car.state"
                    :data-hazard="car.hazard ? 'true' : 'false'"
                    :data-brake="desktopSection === 'brakeSheet' ? car.brake : ''"
                    :data-current="desktopSection === 'wagons' && desktopWagon === car.index ? 'true' : 'false'"
                    :style="`flex-grow: ${car.grow}; max-width: ${(car.grow * 0.3).toFixed(2)}rem`"
                    x-bind:aria-label="@js(__('app.wagons')) + ' ' + (car.index + 1)"
                    @click="selectTrainCar(car.index)"
                >
                    <span x-text="desktopSection === 'brakeSheet' && car.brake === 'G' ? `${car.index + 1} · G` : car.index + 1"></span>
                </button>
            </template>
            <span class="rt-wl-train-empty" x-show="trainCars.length === 0">{{ __('app.wagon_checks_empty') }}</span>
        </div>

        <dl class="rt-wl-sums">
            <div>
                <dd x-text="totals.wagons"></dd>
                <dt>{{ __('app.wagons') }}</dt>
            </div>
            <div>
                <dd x-text="formatPlain(totals.axles, 0)"></dd>
                <dt>{{ __('app.axles') }}</dt>
            </div>
            <div>
                <dd x-text="formatPlain(totals.length, 1)"></dd>
                <dt>m</dt>
            </div>
            <div>
                <dd x-text="formatPlain(totals.totalWeight, 1)"></dd>
                <dt>t</dt>
            </div>
        </dl>
    </section>

    <div class="rt-wagon-desktop-scroll rt-wl-stage min-h-0 flex-1 overflow-hidden">
        <div class="rt-wl-layout" :data-summary="desktopSummaryOpen || desktopSection !== 'wagons' ? 'true' : 'false'">
            <div class="rt-wl-panels">
                {{-- Wagenliste ------------------------------------------------- --}}
                <div
                    x-show.important="desktopSection === 'wagons'"
                    class="rt-wl-panel"
                    role="tabpanel"
                    id="wagon-panel-wagons"
                    aria-labelledby="wagon-tab-wagons"
                >
                    @include('livewire.operations.partials.wagon-sheet-grid')
                </div>

                {{-- Bremszettel ------------------------------------------------ --}}
                <div
                    x-show.important="desktopSection === 'brakeSheet'"
                    x-cloak
                    class="rt-wl-panel rt-wl-panel--scroll"
                    role="tabpanel"
                    id="wagon-panel-brakeSheet"
                    aria-labelledby="wagon-tab-brakeSheet"
                >
                    <div class="rt-wl-columns rt-wl-columns--brake">
                        <section class="rt-wl-card" aria-labelledby="wagon-inputs-title">
                            <header class="rt-wl-card-head">
                                <h2 id="wagon-inputs-title">{{ __('app.wagon_inputs') }}</h2>
                            </header>

                            @include('livewire.operations.partials.wagon-brake-inputs', ['scope' => 'desktop'])

                            @include('livewire.operations.partials.wagon-special-information', [
                                'columns' => 1,
                                'scope' => 'desktop',
                            ])
                        </section>

                        <section class="rt-wl-card rt-wl-card--lead">
                            @include('livewire.operations.partials.wagon-brake-result', ['scope' => 'desktop'])
                        </section>
                    </div>
                </div>

                {{-- Meldezettel ------------------------------------------------ --}}
                <div
                    x-show.important="desktopSection === 'report'"
                    x-cloak
                    class="rt-wl-panel rt-wl-panel--scroll"
                    role="tabpanel"
                    id="wagon-panel-report"
                    aria-labelledby="wagon-tab-report"
                >
                    <div class="rt-wl-columns rt-wl-columns--report">
                        <section class="rt-wl-card" aria-labelledby="wagon-facts-title">
                            <header class="rt-wl-card-head">
                                <h2 id="wagon-facts-title">{{ __('app.wagon_train_facts') }}</h2>
                                <small>{{ __('app.wagon_from_list') }}</small>
                            </header>

                            {{-- Diese Angaben übernimmt der Meldezettel aus der Wagenliste. --}}
                            <dl class="rt-wl-rows rt-wl-rows--facts">
                                <div>
                                    <dt>{{ __('app.train_number') }}</dt>
                                    <dd x-text="meta.trainNumber || '—'"></dd>
                                </div>
                                <div>
                                    <dt>{{ __('app.date') }}</dt>
                                    <dd x-text="formatDate(meta.date)"></dd>
                                </div>
                                <div>
                                    <dt>{{ __('app.from') }}</dt>
                                    <dd x-text="meta.origin || '—'"></dd>
                                </div>
                                <div>
                                    <dt>{{ __('app.to') }}</dt>
                                    <dd x-text="meta.destination || '—'"></dd>
                                </div>
                                <div>
                                    <dt>{{ __('app.first_vehicle_number') }}</dt>
                                    <dd x-text="brakeTotals.firstVehicle || '—'"></dd>
                                </div>
                                <div>
                                    <dt>{{ __('app.last_vehicle_number') }}</dt>
                                    <dd x-text="brakeTotals.lastVehicle || '—'"></dd>
                                </div>
                                <div>
                                    <dt>{{ __('app.dangerous_goods') }}</dt>
                                    <dd x-text="brakeTotals.dangerousGoods === '' ? '—' : (brakeTotals.dangerousGoods === 'yes' ? @js(__('app.yes')) : @js(__('app.no')))"></dd>
                                </div>
                            </dl>

                            @include('livewire.operations.partials.wagon-report-form', [
                                'scope' => 'desktop',
                                'part' => 'train',
                            ])
                        </section>

                        <section class="rt-wl-card rt-wl-card--lead">
                            @include('livewire.operations.partials.wagon-report-form', [
                                'scope' => 'desktop',
                                'part' => 'tasks',
                            ])
                        </section>
                    </div>
                </div>
            </div>

            <aside
                id="wagon-summary-aside"
                x-show="desktopSummaryOpen || desktopSection !== 'wagons'"
                x-cloak
                class="rt-wl-aside rt-wl-card"
                aria-label="{{ __('app.wagon_summary') }}"
            >
                @include('livewire.operations.partials.wagon-desktop-rail')
            </aside>
        </div>
    </div>
</section>
