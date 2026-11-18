{{--
    Rechte Spalte der Arbeitsfläche (nur ab 1024 px). Auf allen drei Seiten
    gleich aufgebaut: oben der Stand, darunter die Einzelheiten, ganz unten
    der nächste Schritt.

        Wagenliste    Prüfhinweise · Bremshundertstel · weiter zum Bremszettel
        Bremszettel   Bremshundertstel · Rechenweg · Grenzen · weiter zum Meldezettel
        Meldezettel   Stand der drei Blätter · als Excel ausgeben

    Alle Werte kommen aus wagon-list-rules.js (totals, brakeTotals, checks);
    hier wird nichts gerechnet.
--}}
<div class="rt-wl-rail">
    <div class="rt-wl-rail-body">
        <header class="rt-wl-card-head">
            <h2 x-show="desktopSection === 'wagons'">{{ __('app.wagon_checks_title') }}</h2>
            <h2 x-show="desktopSection === 'brakeSheet'" x-cloak>{{ __('app.wagon_result') }}</h2>
            <h2 x-show="desktopSection === 'report'" x-cloak>{{ __('app.wagon_output') }}</h2>
            <small x-show="desktopSection === 'wagons' && checks.length > 0" x-text="checkStatusLabel"></small>
            <small x-show="desktopSection === 'report'" x-cloak>{{ __('app.wagon_output_hint') }}</small>
        </header>

        {{-- Wagenliste: Prüfhinweise --}}
        <div x-show="desktopSection === 'wagons'">
            <p class="rt-wl-checks-empty" x-show="checks.length === 0" :data-tone="completionCount > 0 ? 'ok' : 'empty'">
                <i class="far" :class="completionCount > 0 ? 'fa-check-circle' : 'fa-circle'" aria-hidden="true"></i>
                <span x-text="completionCount > 0 ? @js(__('app.wagon_checks_ok')) : @js(__('app.wagon_checks_empty'))"></span>
            </p>

            <ul class="rt-wl-checks" x-show="checks.length > 0" aria-live="polite">
                <template x-for="check in checks" :key="check.id">
                    <li class="rt-wl-check" :data-tone="check.tone" x-text="check.text"></li>
                </template>
            </ul>

            <p class="rt-wl-footnote">{{ __('app.wagon_checks_excel_note') }}</p>
            <div class="rt-wl-sep" aria-hidden="true"></div>
        </div>

        {{-- Wagenliste und Bremszettel: vorhandene gegen mindestens geforderte Bremshundertstel --}}
        <div class="rt-wl-key" x-show="desktopSection !== 'report'" :data-tone="brakeGauge.tone">
            <p class="rt-wl-label">{{ __('app.available_brake_percentage') }}</p>
            <strong class="rt-wl-key-value" :data-empty="brakeTotals.availablePercentage === null ? 'true' : 'false'">
                <span x-text="brakeTotals.availablePercentage === null ? '—' : brakeTotals.availablePercentage"></span><small x-show="brakeTotals.availablePercentage !== null">%</small>
            </strong>
            <p class="rt-wl-key-note" x-text="brakeGauge.note"></p>

            <div class="rt-wl-bar" aria-hidden="true">
                <span class="rt-wl-bar-base" :style="`width: ${brakeBar.base}%`"></span>
                <span class="rt-wl-bar-surplus" x-show="brakeBar.surplus > 0" :style="`left: ${brakeBar.marker}%; width: ${brakeBar.surplus}%`"></span>
                <span class="rt-wl-bar-deficit" x-show="brakeBar.deficit > 0" :style="`left: ${brakeBar.base}%; width: ${brakeBar.deficit}%`"></span>
            </div>
            <p class="rt-wl-bar-note" x-show="brakeBar.marker !== null" aria-hidden="true">
                <span :style="brakeBar.marker > 78 ? 'right: 0' : (brakeBar.marker < 18 ? 'left: 0' : `left: ${brakeBar.marker}%; transform: translateX(-50%)`)">
                    {{ __('app.wagon_minimum_short') }} <span x-text="brakeTotals.minimumPercentage"></span>
                </span>
            </p>
        </div>

        {{-- Bremszettel: Rechenweg und Grenzen --}}
        <div x-show="desktopSection === 'brakeSheet'" x-cloak>
            <div class="rt-wl-sep" aria-hidden="true"></div>

            <p class="rt-wl-label">{{ __('app.wagon_calculation_path') }} <small>{{ __('app.brake_weight_t') }}</small></p>
            <dl class="rt-wl-rows">
                <div>
                    <dt><i aria-hidden="true"></i>{{ __('app.brake_weight_p') }}</dt>
                    <dd x-text="formatPlain(totals.brakeP, 1)"></dd>
                </div>
                <div>
                    <dt><i aria-hidden="true">+</i>{{ __('app.brake_weight_g') }}</dt>
                    <dd x-text="formatPlain(totals.brakeG, 1)"></dd>
                </div>
                <div x-show="totals.deductionG > 0">
                    <dt><i aria-hidden="true">−</i>G −25 %</dt>
                    <dd x-text="formatPlain(totals.deductionG, 2)"></dd>
                </div>
                <div x-show="totals.deductionP > 0">
                    <dt><i aria-hidden="true">−</i>P −<span x-text="Math.round(totals.pRate * 100)"></span> %</dt>
                    <dd x-text="formatPlain(totals.deductionP, 2)"></dd>
                </div>
                <div x-show="totals.deductionGLength > 0">
                    <dt><i aria-hidden="true">−</i>{{ __('app.wagon_deduction_g_length') }}</dt>
                    <dd x-text="formatPlain(totals.deductionGLength, 2)"></dd>
                </div>
                <div data-kind="total">
                    <dt><i aria-hidden="true">=</i>{{ __('app.wagon_train') }}</dt>
                    <dd x-text="totals.usableBrakeWeight === null ? '—' : formatPlain(totals.usableBrakeWeight, 0)"></dd>
                </div>
                <div>
                    <dt><i aria-hidden="true">+</i>{{ __('app.traction_vehicle') }}</dt>
                    <dd x-text="displayNumber(brakeSheet.tractionBrakeWeight, 1)"></dd>
                </div>
                <div data-kind="total">
                    <dt><i aria-hidden="true">=</i>{{ __('app.whole_train') }}</dt>
                    <dd x-text="displayNumber(brakeTotals.brakeWeight, 1)"></dd>
                </div>
            </dl>
            <p
                class="rt-wl-note"
                x-show="brakeTotals.availablePercentage !== null"
                x-text="`${formatPlain(brakeTotals.brakeWeight, 1)} t ÷ ${formatPlain(brakeTotals.trainWeight, 1)} t = ${brakeTotals.availablePercentage} %`"
            ></p>

            <div class="rt-wl-sep" aria-hidden="true"></div>

            <p class="rt-wl-label">{{ __('app.wagon_limits') }}</p>
            <dl class="rt-wl-rows">
                <div>
                    <dt>{{ __('app.braked_axles') }}</dt>
                    <dd>
                        <span x-text="totals.wagons > 0 ? formatPlain(totals.brakedAxles, 0) : '—'"></span>
                        <small x-show="totals.requiredBrakedAxles > 0">{{ __('app.wagon_required_short') }} <span x-text="totals.requiredBrakedAxles"></span></small>
                    </dd>
                </div>
                <div>
                    <dt>{{ __('app.train_length_m') }}</dt>
                    <dd>
                        <span x-text="displayNumber(brakeTotals.trainLength, 1, true)"></span>
                        <small x-show="Number(brakeSheet.timetableLength) > 0">{{ __('app.wagon_limit_of') }} <span x-text="formatPlain(brakeSheet.timetableLength, 0)"></span></small>
                    </dd>
                </div>
                <div>
                    <dt>{{ __('app.wagon_train') }}, {{ __('app.weight_t') }}</dt>
                    <dd>
                        <span x-text="displayNumber(totals.weightRounded, 0, true)"></span>
                        <small x-show="Number(brakeSheet.timetableLoad) > 0">{{ __('app.wagon_limit_of') }} <span x-text="formatPlain(brakeSheet.timetableLoad, 0)"></span></small>
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Meldezettel: Stand der drei Blätter --}}
        <dl class="rt-wl-outs" x-show="desktopSection === 'report'" x-cloak>
            <div :data-tone="sheetStatus.wagons.tone">
                <i aria-hidden="true"></i>
                <dt>{{ __('app.wagon_list') }}</dt>
                <dd x-text="checkStatusLabel"></dd>
            </div>
            <div :data-tone="sheetStatus.brakeSheet.tone">
                <i aria-hidden="true"></i>
                <dt>{{ __('app.brake_sheet') }}</dt>
                <dd x-text="brakeTotals.availablePercentage === null ? brakeGauge.note : `${brakeTotals.availablePercentage} % · ${brakeGauge.note}`"></dd>
            </div>
            <div :data-tone="sheetStatus.report.tone">
                <i aria-hidden="true"></i>
                <dt>{{ __('app.wagon_report') }}</dt>
                <dd x-text="reportProgressLabel"></dd>
            </div>
        </dl>
    </div>

    {{-- Nächster Schritt: bleibt unten stehen, auch wenn der Inhalt darüber scrollt --}}
    <div class="rt-wl-cta">
        <button type="button" class="rt-wl-next" x-show="desktopSection === 'wagons'" @click="desktopSection = 'brakeSheet'">
            <span>{{ __('app.wagon_next_brake_sheet') }}</span>
            <span class="rt-wl-next-chip" aria-hidden="true"><i class="far fa-arrow-right"></i></span>
        </button>
        <button type="button" class="rt-wl-next" x-show="desktopSection === 'brakeSheet'" x-cloak @click="desktopSection = 'report'">
            <span>{{ __('app.wagon_next_report') }}</span>
            <span class="rt-wl-next-chip" aria-hidden="true"><i class="far fa-arrow-right"></i></span>
        </button>
        <button type="button" class="rt-wl-next" x-show="desktopSection === 'report'" x-cloak @click="exportWorkbook()" :disabled="exporting">
            <span x-text="exporting ? @js(__('app.wagon_exporting')) : @js(__('app.export_excel'))"></span>
            <span class="rt-wl-next-chip" aria-hidden="true">
                <i class="far fa-arrow-to-bottom" x-show="!exporting"></i>
                <i class="far fa-spinner fa-spin" x-show="exporting" x-cloak></i>
            </span>
        </button>
        <button type="button" class="rt-wl-ghost rt-wl-ghost--wide" x-show="desktopSection === 'report'" x-cloak @click="saveAndClose()">
            {{ $labels['saveAndClose'] }}
        </button>
    </div>
</div>
