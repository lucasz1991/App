{{--
    Gefuehrte Eingabe der Wagenliste (unter 1024 px).

    AUFBAU — bewusst vier Geschwister in einer Spalte, alle mit fester Hoehe
    ausser dem Pager:

        header (Schrittleiste)  · shrink-0
        Wagenleiste             · shrink-0, nur in den Wagen-Schritten
        Pager (8 Folien)        · min-h-0 flex-1  <- der einzige, der waechst
        footer (Zurueck/Weiter) · shrink-0

    Die Wagenleiste lag frueher absolut ueber dem Pager; jede betroffene Folie
    musste das mit padding-top: 4.85rem ausgleichen. Jetzt steht sie im Fluss —
    dadurch stimmen die Innenabstaende aller Folien wieder ueberein.

    WICHTIG: .rt-wagon-mobile-wizard braucht height:100% (app.css). Ohne das
    waechst der Wizard ueber die Modalhoehe hinaus, die Folien erben keine
    definite Hoehe mehr, ihre Scrollflaeche kann nicht ueberlaufen — und der
    Inhalt langer Schritte ("Besondere Angaben") ist weder scrollbar noch
    erreichbar, samt Fusszeile ausserhalb des Bildes.
--}}
<section
    class="rt-wagon-mobile-wizard min-h-0 flex-1 lg:hidden"
    data-wagon-wizard
    data-mobile-wagon-editor
    data-no-sidebar-swipe
    @keydown="handleMobileWizardKeydown($event)"
>
    <header class="rt-wagon-wizard-progress shrink-0" data-wagon-motion="header">
        <div class="rt-wagon-wizard-progress-inner">
            <span class="rt-wagon-step-counter">
                <span x-text="mobileStep + 1"></span><span class="opacity-45">/</span><span x-text="mobileStepCount"></span>
            </span>

            <ol
                x-ref="mobileStepRail"
                class="rt-wagon-step-rail"
                aria-label="{{ __('app.wagon_wizard_steps') }}"
            >
                <template x-for="(step, index) in mobileSteps" :key="step.id">
                    <li class="shrink-0">
                        <button
                            type="button"
                            @click="goToMobileStep(index)"
                            :aria-current="mobileStep === index ? 'step' : null"
                            :aria-label="`${index + 1}. ${step.label}`"
                            :title="step.label"
                            :data-active="mobileStep === index ? 'true' : 'false'"
                            :data-done="index < mobileStep ? 'true' : 'false'"
                            :data-mobile-step-index="index"
                            class="rt-wagon-step-chip"
                        >
                            <span class="rt-wagon-step-number" x-text="index + 1"></span>
                            <span class="whitespace-nowrap" x-text="step.label"></span>
                        </button>
                    </li>
                </template>
            </ol>
        </div>

        <span class="sr-only" aria-live="polite" x-text="mobileStepTitle"></span>
        <div class="rt-wagon-wizard-progress-track" aria-hidden="true">
            <span
                x-ref="mobileProgressBar"
                class="rt-wagon-wizard-progress-bar"
                :style="`transform: scaleX(${mobileStepProgress / 100})`"
            ></span>
        </div>
    </header>

    {{-- Wagenleiste: nur in den vier Schritten, die genau EINEN Wagen betreffen. --}}
    <div
        x-show.important="isMobileWagonStep"
        x-cloak
        class="rt-wagon-context-bar shrink-0"
        aria-label="{{ __('app.choose_wagon') }}"
    >
        <div class="rt-wagon-context-bar-inner">
            <p class="rt-wagon-context-title">
                <i class="far fa-train-track" aria-hidden="true"></i>
                <span x-text="`{{ __('app.wagons') }} ${mobileWagon + 1}/${visibleCount}`"></span>
            </p>

            <div x-ref="mobileWagonRail" class="rt-wagon-index-strip" role="group" aria-label="{{ __('app.choose_wagon') }}">
                <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                    <button
                        type="button"
                        @click="showMobileWagon(index)"
                        :data-active="mobileWagon === index ? 'true' : 'false'"
                        :data-state="wagonStatus(wagon)"
                        :data-wagon-index="index"
                        :aria-current="mobileWagon === index ? 'true' : null"
                        class="rt-wagon-index-button"
                        :aria-label="`${@js(__('app.wagons'))} ${index + 1}`"
                    >
                        <span x-text="index + 1"></span>
                        <span class="rt-wagon-index-state" aria-hidden="true"></span>
                    </button>
                </template>
            </div>

            <button
                type="button"
                @click="addWagon()"
                :disabled="visibleCount >= 40"
                class="rt-wagon-context-add"
                title="{{ __('app.add_wagon') }}"
                aria-label="{{ __('app.add_wagon') }}"
            >
                <i class="far fa-plus" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div
        x-ref="mobilePager"
        class="rt-wagon-mobile-pager relative min-h-0 flex-1"
        @scroll.passive="syncMobileStepFromScroll($event)"
        @scrollend="settleMobilePager($event.currentTarget)"
        @resize.window.debounce.100ms="realignMobilePager()"
        role="region"
        aria-roledescription="carousel"
        aria-label="{{ __('app.wagon_wizard_steps') }}"
    >
        {{-- 1 · Zugdaten ------------------------------------------------ --}}
        <section
            id="wagon-mobile-panel-train"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="1 / 8 · {{ __('app.train_data') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="train"
            data-wagon-step-index="0"
            :aria-hidden="mobileStep !== 0"
            :inert="mobileStep !== 0"
            aria-labelledby="wagon-mobile-step-train"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-route" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-train">{{ __('app.train_data') }}</h3>
                        <p>{{ __('app.train_data_hint') }}</p>
                    </div>
                    <span class="rt-wagon-scope">{{ __('app.wagon_scope_train') }}</span>
                </div>

                {{-- Orientierung: erklaert die Reihenfolge und wo die Wagenliste steht. --}}
                <section class="rt-wagon-flow" aria-labelledby="wagon-flow-title">
                    <p class="rt-wagon-flow-eyebrow" id="wagon-flow-title">{{ __('app.wagon_flow_title') }}</p>
                    <ol class="rt-wagon-flow-list">
                        <li><span>1</span>{{ __('app.wagon_flow_step_train') }}</li>
                        <li><span>2</span>{{ __('app.wagon_flow_step_wagons') }}</li>
                        <li><span>3</span>{{ __('app.wagon_flow_step_sheet') }}</li>
                        <li><span>4</span>{{ __('app.wagon_flow_step_review') }}</li>
                    </ol>
                    <p class="rt-wagon-flow-hint">{{ __('app.wagon_flow_hint') }}</p>
                </section>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-train" aria-hidden="true"></i></span>
                        <h4>{{ __('app.train_data') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.train_number') }}
                            <input x-model="meta.trainNumber" type="text" class="{{ $inputClass }}" autocomplete="off" inputmode="numeric">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.date') }}
                            <x-ui.forms.date-field x-model="meta.date" class="mt-1" :aria-label="__('app.date')" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.from') }}
                            <input x-model="meta.origin" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.to') }}
                            <input x-model="meta.destination" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                        <label class="{{ $labelClass }} col-span-2">{{ __('app.reference') }}
                            <input x-model="meta.reference" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                    </div>
                </section>

                <section class="rt-wagon-card" aria-label="{{ __('app.wagon_totals') }}">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-calculator-simple" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h4>{{ __('app.wagon_totals') }}</h4>
                            <p class="rt-wagon-card-hint">{{ __('app.wagon_totals_auto') }}</p>
                        </div>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-2">
                        <template x-for="item in [
                            { label: @js(__('app.wagons')), value: totals.wagons, suffix: '' },
                            { label: @js(__('app.axles')), value: totals.axles, suffix: '' },
                            { label: @js(__('app.length_over_buffers')), value: formatNumber(totals.length), suffix: ' m' },
                            { label: @js(__('app.total_weight')), value: formatNumber(totals.totalWeight), suffix: ' t' },
                            { label: @js(__('app.brake_weight_g')), value: formatNumber(totals.brakeG), suffix: ' t' },
                            { label: @js(__('app.brake_weight_p')), value: formatNumber(totals.brakeP), suffix: ' t' },
                        ]" :key="item.label">
                            <div class="rt-wagon-metric">
                                <p class="rt-wagon-metric-label" x-text="item.label"></p>
                                <p class="rt-wagon-metric-value"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                            </div>
                        </template>
                    </div>
                </section>
            </div>
        </section>

        {{-- 2 · Identifikation ------------------------------------------ --}}
        <section
            id="wagon-mobile-panel-identity"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="2 / 8 · {{ __('app.identification') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="identity"
            data-wagon-step-index="1"
            :aria-hidden="mobileStep !== 1"
            :inert="mobileStep !== 1"
            aria-labelledby="wagon-mobile-step-identity"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-number-badge" x-text="mobileWagon + 1"></span>
                    <div class="min-w-0 flex-1">
                        <h3 id="wagon-mobile-step-identity">{{ __('app.identification') }}</h3>
                        <p class="truncate" x-text="wagonNumber(wagons[mobileWagon]) || @js(__('app.wagon_not_filled'))"></p>
                    </div>
                    <button
                        type="button"
                        @click="clearWagon(mobileWagon)"
                        class="rt-wagon-icon-action rt-wagon-icon-action--danger"
                        title="{{ __('app.clear_wagon') }}"
                        aria-label="{{ __('app.clear_wagon') }}"
                    >
                        <i class="far fa-trash-alt" aria-hidden="true"></i>
                    </button>
                </div>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-hashtag" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h4>{{ __('app.wagon_number') }}</h4>
                            <p class="rt-wagon-card-hint">{{ __('app.wagon_number_hint') }}</p>
                        </div>
                    </header>

                    <div class="rt-wagon-card-body">
                        <div class="rt-wagon-uic">
                            <label class="rt-wagon-uic-part"><span>1+2</span>
                                <input x-model="wagons[mobileWagon].number12" inputmode="numeric" maxlength="2" class="{{ $inputClass }}">
                            </label>
                            <label class="rt-wagon-uic-part"><span>3+4</span>
                                <input x-model="wagons[mobileWagon].number34" inputmode="numeric" maxlength="2" class="{{ $inputClass }}">
                            </label>
                            <label class="rt-wagon-uic-part"><span>5–8</span>
                                <input x-model="wagons[mobileWagon].number58" inputmode="numeric" maxlength="4" class="{{ $inputClass }}">
                            </label>
                            <label class="rt-wagon-uic-part"><span>9–11</span>
                                <input x-model="wagons[mobileWagon].number911" inputmode="numeric" maxlength="3" class="{{ $inputClass }}">
                            </label>
                            <label class="rt-wagon-uic-part rt-wagon-uic-part--check"><span>12</span>
                                <input x-model="wagons[mobileWagon].checkDigit" inputmode="numeric" maxlength="1" class="{{ $inputClass }}">
                            </label>
                        </div>

                        <div class="rt-wagon-validation-state mt-3" :data-state="checkState(wagons[mobileWagon])">
                            <i class="far" :class="checkState(wagons[mobileWagon]) === 'valid' ? 'fa-check-circle' : (checkState(wagons[mobileWagon]) === 'invalid' ? 'fa-exclamation-circle' : 'fa-circle-dashed')" aria-hidden="true"></i>
                            <span x-show="checkState(wagons[mobileWagon]) === 'valid'">{{ __('app.wagon_number_valid') }}</span>
                            <span x-show="checkState(wagons[mobileWagon]) === 'invalid'">{{ __('app.expected_check_digit') }}: <span class="tabular-nums" x-text="expectedCheckDigit(wagons[mobileWagon])"></span></span>
                            <span x-show="checkState(wagons[mobileWagon]) === 'incomplete'">{{ __('app.wagon_not_filled') }}</span>
                        </div>
                    </div>
                </section>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-tag" aria-hidden="true"></i></span>
                        <h4>{{ __('app.category') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body">
                        <input x-model="wagons[mobileWagon].category" class="{{ $inputClass }} !mt-0" autocomplete="off" aria-label="{{ __('app.category') }}">
                    </div>
                </section>
            </div>
        </section>

        {{-- 3 · Achsen und Masse ---------------------------------------- --}}
        <section
            id="wagon-mobile-panel-vehicle"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="3 / 8 · {{ __('app.axles_dimensions') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="vehicle"
            data-wagon-step-index="2"
            :aria-hidden="mobileStep !== 2"
            :inert="mobileStep !== 2"
            aria-labelledby="wagon-mobile-step-vehicle"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-weight-hanging" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-vehicle">{{ __('app.axles_dimensions') }}</h3>
                        <p>{{ __('app.wagon_step_vehicle_hint') }}</p>
                    </div>
                    <span class="rt-wagon-scope rt-wagon-scope--wagon"><span x-text="mobileWagon + 1"></span></span>
                </div>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-ruler-horizontal" aria-hidden="true"></i></span>
                        <h4>{{ __('app.axles_dimensions') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.axles_empty') }}<x-ui.forms.number-input min="0" x-model="wagons[mobileWagon].axlesEmpty" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.axles_loaded') }}<x-ui.forms.number-input min="0" x-model="wagons[mobileWagon].axlesLoaded" class="mt-1" /></label>
                        <label class="{{ $labelClass }} col-span-2">{{ __('app.length_m') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].length" class="mt-1" /></label>
                    </div>
                </section>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-scale-balanced" aria-hidden="true"></i></span>
                        <h4>{{ __('app.weights_and_brakes') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.wagon_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].wagonWeight" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.load_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].loadWeight" class="mt-1" /></label>
                        <div class="rt-wagon-calculated col-span-2">
                            <span class="rt-wagon-metric-label">{{ __('app.total_weight') }}</span>
                            <strong class="rt-wagon-calculated-value"><span x-text="formatNumber(totalWeight(wagons[mobileWagon]))"></span> t</strong>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        {{-- 4 · Bremsen -------------------------------------------------- --}}
        <section
            id="wagon-mobile-panel-brakes"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="4 / 8 · {{ __('app.brakes') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="brakes"
            data-wagon-step-index="3"
            :aria-hidden="mobileStep !== 3"
            :inert="mobileStep !== 3"
            aria-labelledby="wagon-mobile-step-brakes"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-gauge-high" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-brakes">{{ __('app.brakes') }}</h3>
                        <p>{{ __('app.wagon_step_brakes_hint') }}</p>
                    </div>
                    <span class="rt-wagon-scope rt-wagon-scope--wagon"><span x-text="mobileWagon + 1"></span></span>
                </div>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-sliders" aria-hidden="true"></i></span>
                        <h4>{{ __('app.brake_type') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body">
                        <div class="rt-wagon-segment rt-wagon-segment--wide" role="group" aria-label="{{ __('app.brake_type') }}">
                            @foreach (['' => '—', 'K' => 'K', 'L' => 'L', 'LL' => 'LL'] as $value => $optionLabel)
                                <button
                                    type="button"
                                    @click="wagons[mobileWagon].brakeType = @js($value)"
                                    :aria-pressed="wagons[mobileWagon].brakeType === @js($value)"
                                    :data-active="wagons[mobileWagon].brakeType === @js($value) ? 'true' : 'false'"
                                    class="rt-wagon-choice"
                                >{{ $optionLabel }}</button>
                            @endforeach
                        </div>

                        <label class="rt-wagon-check-card mt-3">
                            <input x-model="wagons[mobileWagon].discBrake" type="checkbox" class="h-5 w-5 shrink-0 rounded border-rt-border text-rt-accent focus:ring-rt-accent/35 dark:border-rt-dark-border">
                            <span class="min-w-0">
                                <strong class="block">{{ __('app.disc_brake') }}</strong>
                                <small class="mt-0.5 block font-normal opacity-65">{{ __('app.wagon_disc_brake_hint') }}</small>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-weight-scale" aria-hidden="true"></i></span>
                        <h4>{{ __('app.weights_and_brakes') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.brake_weight_g') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].brakeG" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.brake_weight_p') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].brakeP" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.parking_brake_kn') }}<x-ui.forms.number-input min="0" step="0.1" :decimals="1" x-model="wagons[mobileWagon].parkingBrake" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.maximum_speed') }}<x-ui.forms.number-input min="0" x-model="wagons[mobileWagon].maxSpeed" class="mt-1" /></label>
                    </div>
                </section>
            </div>
        </section>

        {{-- 5 · Laufweg und Hinweise ------------------------------------- --}}
        <section
            id="wagon-mobile-panel-route"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="5 / 8 · {{ __('app.route_and_notes') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="route"
            data-wagon-step-index="4"
            :aria-hidden="mobileStep !== 4"
            :inert="mobileStep !== 4"
            aria-labelledby="wagon-mobile-step-route"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-location-dot" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-route">{{ __('app.route_and_notes') }}</h3>
                        <p>{{ __('app.wagon_step_route_hint') }}</p>
                    </div>
                    <span class="rt-wagon-scope rt-wagon-scope--wagon"><span x-text="mobileWagon + 1"></span></span>
                </div>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-map-location-dot" aria-hidden="true"></i></span>
                        <h4>{{ __('app.route') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.shipping_station') }}<input x-model="wagons[mobileWagon].shippingStation" class="{{ $inputClass }}" autocomplete="off"></label>
                        <label class="{{ $labelClass }}">{{ __('app.destination_station') }}<input x-model="wagons[mobileWagon].destinationStation" class="{{ $inputClass }}" autocomplete="off"></label>

                        <div class="rt-wagon-route-preview">
                            <span class="truncate" x-text="wagons[mobileWagon].shippingStation || '—'"></span>
                            <i class="far fa-arrow-right shrink-0 opacity-45" aria-hidden="true"></i>
                            <span class="truncate" x-text="wagons[mobileWagon].destinationStation || '—'"></span>
                        </div>
                    </div>
                </section>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-note-sticky" aria-hidden="true"></i></span>
                        <h4>{{ __('app.remark') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body">
                        <textarea x-model="wagons[mobileWagon].remark" rows="4" class="{{ $inputClass }} !mt-0 resize-none" aria-label="{{ __('app.remark') }}"></textarea>
                    </div>
                </section>
            </div>
        </section>

        {{-- 6 · Bremsberechnung ------------------------------------------ --}}
        <section
            id="wagon-mobile-panel-calculation"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="6 / 8 · {{ __('app.brake_calculation') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="calculation"
            data-wagon-step-index="5"
            :aria-hidden="mobileStep !== 5"
            :inert="mobileStep !== 5"
            aria-labelledby="wagon-mobile-step-calculation"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-calculator" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-calculation">{{ __('app.brake_calculation') }}</h3>
                        <p>{{ __('app.wagon_step_calculation_hint') }}</p>
                    </div>
                    <span class="rt-wagon-scope">{{ __('app.wagon_scope_train') }}</span>
                </div>

                <section class="rt-wagon-card">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-train-tram" aria-hidden="true"></i></span>
                        <h4>{{ __('app.traction_vehicle') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="brakeSheet.tractionWeight" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.brake_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="brakeSheet.tractionBrakeWeight" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.axles') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.tractionAxles" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.braked_axles') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.brakedAxles" class="mt-1" /></label>
                        <label class="{{ $labelClass }} col-span-2">{{ __('app.minimum_brake_percentage') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.minimumBrakePercentage" class="mt-1" /></label>
                    </div>
                </section>

                <section class="rt-wagon-card" aria-label="{{ __('app.brake_sheet_summary') }}">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-percent" aria-hidden="true"></i></span>
                        <h4>{{ __('app.available_brake_percentage') }}</h4>
                    </header>
                    <div class="rt-wagon-card-body grid grid-cols-2 gap-2">
                        <div class="rt-wagon-metric">
                            <p class="rt-wagon-metric-label">{{ __('app.available') }}</p>
                            <p class="rt-wagon-metric-value"><span x-text="brakeTotals.availablePercentage"></span> %</p>
                        </div>
                        <div class="rt-wagon-metric" :data-tone="brakeTotals.missingPercentage > 0 ? 'warn' : 'ok'">
                            <p class="rt-wagon-metric-label">{{ __('app.missing') }}</p>
                            <p class="rt-wagon-metric-value"><span x-text="brakeTotals.missingPercentage"></span> %</p>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        {{-- 7 · Besondere Angaben ---------------------------------------- --}}
        <section
            id="wagon-mobile-panel-special"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="7 / 8 · {{ __('app.special_information') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="special"
            data-wagon-step-index="6"
            :aria-hidden="mobileStep !== 6"
            :inert="mobileStep !== 6"
            aria-labelledby="wagon-mobile-step-special"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-shield-check" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-special">{{ __('app.special_information') }}</h3>
                        <p>{{ __('app.wagon_step_special_hint') }}</p>
                    </div>
                    <span class="rt-wagon-scope rt-wagon-scope--count">
                        <span aria-hidden="true" x-text="`${specialAnsweredCount}/${specialFieldCount}`"></span>
                        <span class="sr-only" x-text="@js(__('app.wagon_special_progress', ['answered' => '%a', 'total' => '%t'])).replace('%a', specialAnsweredCount).replace('%t', specialFieldCount)"></span>
                    </span>
                </div>

                @include('livewire.operations.partials.wagon-special-information', [
                    'inputClass' => $inputClass,
                    'labelClass' => $labelClass,
                    'columns' => 1,
                    'scope' => 'mobile',
                ])
            </div>
        </section>

        {{-- 8 · Pruefen und abschliessen --------------------------------- --}}
        <section
            id="wagon-mobile-panel-review"
            role="group"
            aria-roledescription="{{ __('app.wagon_wizard') }}"
            aria-label="8 / 8 · {{ __('app.review_and_finish') }}"
            class="rt-wagon-mobile-slide"
            data-wagon-step="review"
            data-wagon-step-index="7"
            :aria-hidden="mobileStep !== 7"
            :inert="mobileStep !== 7"
            aria-labelledby="wagon-mobile-step-review"
        >
            <div class="rt-wagon-mobile-slide-scroll" data-wagon-motion="panel">
                <div class="rt-wagon-step-head">
                    <span class="rt-wagon-step-icon"><i class="far fa-clipboard-check" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 id="wagon-mobile-step-review">{{ __('app.review_and_finish') }}</h3>
                        <p>{{ __('app.wagon_step_review_hint') }}</p>
                    </div>
                </div>

                <section class="rt-wagon-review-train">
                    <p class="rt-wagon-flow-eyebrow">{{ __('app.train_data') }}</p>
                    <div class="mt-2 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <strong class="block truncate text-xl" x-text="meta.trainNumber || @js(__('app.wagon_not_filled'))"></strong>
                            <p class="mt-1 truncate text-sm opacity-70">
                                <span x-text="meta.origin || '—'"></span> → <span x-text="meta.destination || '—'"></span>
                            </p>
                            <p class="mt-0.5 text-xs tabular-nums opacity-55" x-text="formatDate(meta.date)"></p>
                        </div>
                        <span class="rt-wagon-review-count"><span x-text="completionCount"></span>/<span x-text="visibleCount"></span></span>
                    </div>
                </section>

                {{-- Die vollstaendige Wagenliste. Frueher standen hier nur
                     nummerierte Kacheln — dass das die erfassten Wagen sind,
                     war daran nicht zu erkennen. --}}
                <section class="rt-wagon-card" aria-labelledby="wagon-review-list-title">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-list-ol" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <h4 id="wagon-review-list-title">{{ __('app.wagon_review_list_title') }}</h4>
                            <p class="rt-wagon-card-hint">{{ __('app.wagon_review_list_hint') }}</p>
                        </div>
                    </header>

                    <div class="rt-wagon-card-body">
                        <ol class="rt-wagon-review-list" x-ref="reviewWagonList">
                            <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                                <li data-wagon-motion-item>
                                    <button
                                        type="button"
                                        @click="openWagonForEditing(index)"
                                        class="rt-wagon-review-item"
                                        :data-state="wagonStatus(wagon)"
                                        :aria-label="`${@js(__('app.wagon_open'))}: ${@js(__('app.wagons'))} ${index + 1}`"
                                    >
                                        <span class="rt-wagon-review-item-index" x-text="index + 1"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="rt-wagon-review-item-title" x-text="wagonNumber(wagon) || `${@js(__('app.wagons'))} ${index + 1}`"></span>
                                            <span class="rt-wagon-review-item-meta">
                                                <span x-show="!isWagonFilled(wagon)">{{ __('app.wagon_missing_entries') }}</span>
                                                <template x-if="isWagonFilled(wagon)">
                                                    <span>
                                                        <span x-text="wagon.category || '—'"></span>
                                                        <span aria-hidden="true">·</span>
                                                        <span class="tabular-nums" x-text="`${formatNumber(totalWeight(wagon))} t`"></span>
                                                    </span>
                                                </template>
                                            </span>
                                            <span class="rt-wagon-review-item-route" x-show="wagonRouteLabel(wagon)" x-text="wagonRouteLabel(wagon)"></span>
                                        </span>
                                        <span class="rt-wagon-review-item-state" aria-hidden="true"></span>
                                        <i class="far fa-chevron-right rt-wagon-review-item-chevron" aria-hidden="true"></i>
                                    </button>
                                </li>
                            </template>
                        </ol>

                        <button
                            type="button"
                            @click="addWagonAndOpen()"
                            :disabled="visibleCount >= 40"
                            class="rt-wagon-review-add"
                        >
                            <i class="far fa-plus" aria-hidden="true"></i>
                            <span>{{ __('app.add_wagon') }}</span>
                        </button>
                        <p class="rt-wagon-card-hint mt-2" x-text="visibleCount >= 40 ? @js(__('app.wagon_limit_reached')) : @js(__('app.wagon_add_hint'))"></p>
                    </div>
                </section>

                <section class="rt-wagon-card" aria-label="{{ __('app.brake_sheet_summary') }}">
                    <header class="rt-wagon-card-head">
                        <span class="rt-wagon-card-icon"><i class="far fa-square-poll-vertical" aria-hidden="true"></i></span>
                        <h4>{{ __('app.brake_sheet_summary') }}</h4>
                    </header>
                    <dl class="rt-wagon-review-facts">
                        <div><dt>{{ __('app.total_weight') }}</dt><dd><span x-text="formatNumber(brakeTotals.trainWeight)"></span> t</dd></div>
                        <div><dt>{{ __('app.brake_weight') }}</dt><dd><span x-text="formatNumber(brakeTotals.brakeWeight)"></span> t</dd></div>
                        <div><dt>{{ __('app.axles') }}</dt><dd x-text="brakeTotals.axles"></dd></div>
                        <div><dt>{{ __('app.available_brake_percentage') }}</dt><dd><span x-text="brakeTotals.availablePercentage"></span> %</dd></div>
                        <div><dt>{{ __('app.last_vehicle_number') }}</dt><dd class="max-w-[60%] truncate" x-text="brakeTotals.lastVehicle || '—'"></dd></div>
                    </dl>
                </section>

                <button
                    type="button"
                    @click="exportWorkbook()"
                    :disabled="exporting"
                    class="rt-wagon-export-button"
                >
                    <i class="far" :class="exporting ? 'fa-spinner fa-spin' : 'fa-file-excel'" aria-hidden="true"></i>
                    <span x-text="exporting ? @js(__('app.wagon_exporting')) : @js(__('app.export_excel'))"></span>
                </button>
            </div>
        </section>
    </div>

    <footer class="rt-wagon-wizard-footer shrink-0" data-wagon-motion="footer">
        <div class="rt-wagon-wizard-footer-inner">
            <button type="button" @click="previousMobileStep()" :disabled="mobileStep === 0" class="rt-wagon-wizard-action justify-self-start" aria-label="{{ __('app.previous') }}">
                <i class="far fa-arrow-left" aria-hidden="true"></i><span>{{ __('app.previous') }}</span>
            </button>

            <p class="rt-wagon-wizard-footer-title" x-text="mobileStepTitle" aria-hidden="true"></p>

            <button x-show.important="mobileStep < mobileStepCount - 1" type="button" @click="nextMobileStep()" class="rt-wagon-wizard-action rt-wagon-wizard-action-primary justify-self-end">
                <span>{{ __('app.next') }}</span><i class="far fa-arrow-right" aria-hidden="true"></i>
            </button>
            <button x-show.important="mobileStep === mobileStepCount - 1" type="button" @click="saveAndClose()" class="rt-wagon-wizard-action rt-wagon-wizard-action-primary justify-self-end">
                <i class="far fa-check" aria-hidden="true"></i><span>{{ $labels['saveAndClose'] }}</span>
            </button>
        </div>
    </footer>
</section>
