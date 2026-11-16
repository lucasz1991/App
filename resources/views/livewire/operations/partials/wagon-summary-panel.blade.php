{{--
    Zugübersicht: Summen der Wagenliste, Bremsrechnung mit Abzügen und die
    Prüfhinweise. Desktop als Seitenleiste, mobil als Karten im Abschnitt
    „Prüfen und abschließen“. Alle Werte kommen aus wagon-list-rules.js und
    entsprechen den Formeln der Excel-Liste v16_5.
--}}
@php
    $sectionClass = $sectionClass ?? 'rt-wl-aside-section';
    $scope = $scope ?? 'desktop';
@endphp

<section class="{{ $sectionClass }}" aria-labelledby="wagon-summary-title-{{ $scope }}">
    <h3 id="wagon-summary-title-{{ $scope }}" class="rt-wl-aside-title">{{ __('app.wagon_summary') }}</h3>

    <dl class="rt-wl-kpis">
        <div class="rt-wl-kpi">
            <dt>{{ __('app.wagons') }}</dt>
            <dd x-text="totals.wagons"></dd>
        </div>
        <div class="rt-wl-kpi">
            <dt>{{ __('app.axles') }}</dt>
            <dd x-text="formatPlain(totals.axles, 0)"></dd>
        </div>
        <div class="rt-wl-kpi">
            <dt>{{ __('app.length_over_buffers') }}</dt>
            <dd><span x-text="formatPlain(totals.length, 1)"></span> <small>m</small></dd>
        </div>
        <div class="rt-wl-kpi">
            <dt>{{ __('app.total_weight') }}</dt>
            <dd><span x-text="formatPlain(totals.totalWeight, 1)"></span> <small>t</small></dd>
        </div>
    </dl>
</section>

<section class="{{ $sectionClass }}" aria-labelledby="wagon-brake-title-{{ $scope }}">
    <h3 id="wagon-brake-title-{{ $scope }}" class="rt-wl-aside-title">{{ __('app.brake_calculation') }}</h3>

    <dl class="rt-wl-lines">
        <div>
            <dt>{{ __('app.brake_weight_g') }}</dt>
            <dd><span x-text="formatPlain(totals.brakeG, 1)"></span> t</dd>
        </div>
        <div>
            <dt>{{ __('app.brake_weight_p') }}</dt>
            <dd><span x-text="formatPlain(totals.brakeP, 1)"></span> t</dd>
        </div>
        <div data-kind="minus" x-show="totals.deductionG > 0">
            <dt>G −25 %</dt>
            <dd>− <span x-text="formatPlain(totals.deductionG, 2)"></span> t</dd>
        </div>
        <div data-kind="minus" x-show="totals.deductionP > 0">
            <dt>P −<span x-text="Math.round(totals.pRate * 100)"></span> %</dt>
            <dd>− <span x-text="formatPlain(totals.deductionP, 2)"></span> t</dd>
        </div>
        <div data-kind="minus" x-show="totals.deductionGLength > 0">
            <dt>{{ __('app.wagon_deduction_g_length') }}</dt>
            <dd>− <span x-text="formatPlain(totals.deductionGLength, 2)"></span> t</dd>
        </div>
        <div data-kind="total">
            <dt>{{ __('app.usable_brake_weight') }}</dt>
            <dd x-text="totals.usableBrakeWeight === null ? '—' : formatPlain(totals.usableBrakeWeight, 0) + ' t'"></dd>
        </div>
    </dl>

    <div class="rt-wl-gauge" :data-tone="brakeGauge.tone">
        <div class="rt-wl-gauge-head">
            <div>
                <p class="rt-wl-label">{{ __('app.available_brake_percentage') }}</p>
                <strong class="rt-wl-gauge-value" x-text="brakeTotals.availablePercentage === null ? '—' : brakeTotals.availablePercentage + ' %'"></strong>
            </div>
            <span class="rt-wl-gauge-note" x-text="brakeGauge.note"></span>
        </div>
        <div class="rt-wl-gauge-track" aria-hidden="true">
            <span class="rt-wl-gauge-bar" :style="`transform: scaleX(${brakeGauge.ratio})`"></span>
        </div>
    </div>
</section>

<section class="{{ $sectionClass }}" aria-labelledby="wagon-checks-title-{{ $scope }}">
    <h3 id="wagon-checks-title-{{ $scope }}" class="rt-wl-aside-title">
        {{ __('app.wagon_checks_title') }}
        <span class="rt-wl-count" x-show="checks.length > 0" x-text="checks.length"></span>
    </h3>

    <p class="rt-wl-checks-empty" x-show="checks.length === 0" :data-tone="completionCount > 0 ? 'ok' : 'empty'">
        <i class="far" :class="completionCount > 0 ? 'fa-check-circle' : 'fa-circle'" aria-hidden="true"></i>
        <span x-text="completionCount > 0 ? @js(__('app.wagon_checks_ok')) : @js(__('app.wagon_checks_empty'))"></span>
    </p>

    <ul class="rt-wl-checks" x-show="checks.length > 0" aria-live="polite">
        <template x-for="check in checks" :key="check.id">
            <li class="rt-wl-check" :data-tone="check.tone">
                <i
                    class="far"
                    :class="check.tone === 'error' ? 'fa-exclamation-circle' : (check.tone === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle')"
                    aria-hidden="true"
                ></i>
                <span x-text="check.text"></span>
            </li>
        </template>
    </ul>

    <p class="rt-wl-footnote">{{ __('app.wagon_checks_excel_note') }}</p>
</section>
