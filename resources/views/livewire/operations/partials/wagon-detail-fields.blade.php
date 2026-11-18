{{--
    Alle Angaben EINES Wagens als Formular — gemeinsam für die Einzelansicht
    am Desktop und das Wagen-Modal am Telefon.

    $w        Alpine-Ausdruck für den Wagen, z. B. wagons[mobileWagon]
    $scope    desktop | mobile (für eindeutige IDs)
    $anchors  true im Wagen-Modal: Die vier Abschnitte tragen dann die
              Anker identity, vehicle, brakes und route. Das ist der Vertrag
              mit dem Assistenten (ASSISTANT_STEP_IDS) — nicht umbenennen.
--}}
@php
    $anchors = $anchors ?? false;
    $scope = $scope ?? 'desktop';
    $anchor = static fn (string $id): string => $anchors ? ' data-wagon-anchor="'.$id.'"' : '';
    $decimalField = 'rt-wl-input rt-wl-input--center';
@endphp

{{-- Wagennummer und Gattung --}}
<section class="rt-wagon-card"{!! $anchor('identity') !!}>
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-hashtag" aria-hidden="true"></i></span>
        <h4>{{ __('app.wagon_number') }}</h4>
        <span class="rt-wl-state" :data-state="checkState({{ $w }})">
            <span x-show="checkState({{ $w }}) === 'valid'">{{ __('app.wagon_number_valid') }}</span>
            <span x-show="checkState({{ $w }}) === 'invalid'">{{ __('app.expected_check_digit') }}: <span class="tabular-nums" x-text="expectedCheckDigit({{ $w }})"></span></span>
            <span x-show="checkState({{ $w }}) === 'incomplete'">{{ __('app.wagon_number_incomplete') }}</span>
        </span>
    </header>
    <div class="rt-wagon-card-body">
        <div class="rt-wagon-uic" data-wagon-number>
            @foreach ([
                ['number12', '1+2', 2],
                ['number34', '3+4', 2],
                ['number58', '5–8', 4],
                ['number911', '9–11', 3],
                ['checkDigit', '12', 1],
            ] as [$field, $label, $max])
                <label class="rt-wagon-uic-part {{ $field === 'checkDigit' ? 'rt-wagon-uic-part--check' : '' }}"><span>{{ $label }}</span>
                    <input
                        x-model="{{ $w }}.{{ $field }}"
                        data-wagon-number-part
                        inputmode="numeric"
                        maxlength="{{ $max }}"
                        autocomplete="off"
                        @input="advanceNumberField($event)"
                        @paste="handleNumberPaste($event, {{ $w }})"
                        @if ($field === 'checkDigit')
                            :placeholder="expectedCheckDigit({{ $w }}) ?? ''"
                        @endif
                        class="{{ $decimalField }}"
                    >
                </label>
            @endforeach
        </div>

        <label class="rt-wl-field mt-3">
            <span class="rt-wl-label">
                {{ __('app.category') }}
                <span class="font-medium" x-show="{{ $w }}.categoryAuto && {{ $w }}.category === {{ $w }}.categoryAuto">{{ __('app.wagon_category_auto') }}</span>
            </span>
            <input x-model="{{ $w }}.category" class="rt-wl-input" autocomplete="off">
        </label>
    </div>
</section>

{{-- Achsen, Länge, Gewichte --}}
<section class="rt-wagon-card"{!! $anchor('vehicle') !!}>
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-ruler-horizontal" aria-hidden="true"></i></span>
        <h4>{{ __('app.axles_dimensions') }}</h4>
    </header>
    <div class="rt-wagon-card-body rt-wl-fields">
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.axles') }}</span>
            <x-ui.forms.number-input min="0" max="32" x-model="{{ $w }}.axles" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.length_m') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.01" :decimals="2" x-model="{{ $w }}.length" class="{{ $decimalField }}" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.wagon_weight_t') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.01" :decimals="2" x-model="{{ $w }}.wagonWeight" class="{{ $decimalField }}" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.load_weight_t') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.01" :decimals="2" x-model="{{ $w }}.loadWeight" class="{{ $decimalField }}" />
        </label>
        <div class="rt-wagon-calculated rt-wl-span-all">
            <span class="rt-wagon-metric-label">{{ __('app.total_weight') }}</span>
            <strong class="rt-wagon-calculated-value"><span x-text="formatPlain(totalWeight({{ $w }}), 2)"></span> t</strong>
        </div>
        <div class="rt-wl-field rt-wl-span-all">
            <span class="rt-wl-label" id="wagon-speed-label-{{ $scope }}">{{ __('app.maximum_speed') }}</span>
            <div class="rt-wl-chips" role="group" aria-labelledby="wagon-speed-label-{{ $scope }}">
                @foreach (['80', '90', '100', '120'] as $speed)
                    <button
                        type="button"
                        class="rt-wl-chip"
                        @click="{{ $w }}.maxSpeed = String({{ $w }}.maxSpeed) === @js($speed) ? '' : @js($speed)"
                        :aria-pressed="String({{ $w }}.maxSpeed) === @js($speed)"
                        :data-active="String({{ $w }}.maxSpeed) === @js($speed) ? 'true' : 'false'"
                    >{{ $speed }}</button>
                @endforeach
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="200" x-model="{{ $w }}.maxSpeed" :aria-label="__('app.maximum_speed')" class="{{ $decimalField }} !mt-0 !w-20" />
            </div>
        </div>
    </div>
</section>

{{-- Bremssohle und Bremsgewichte --}}
<section class="rt-wagon-card"{!! $anchor('brakes') !!}>
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-tachometer-alt" aria-hidden="true"></i></span>
        <h4>{{ __('app.brakes') }}</h4>
    </header>
    <div class="rt-wagon-card-body">
        <span class="rt-wl-label" id="wagon-sole-label-{{ $scope }}">{{ __('app.brake_sole') }}</span>
        <div class="rt-wl-chips" role="group" aria-labelledby="wagon-sole-label-{{ $scope }}">
            @foreach (['' => '—', 'K' => 'K', 'L' => 'L', 'LL' => 'LL', 'D' => 'D', 'F' => 'F'] as $value => $optionLabel)
                <button
                    type="button"
                    class="rt-wl-chip"
                    @click="{{ $w }}.brakeType = @js((string) $value)"
                    :aria-pressed="{{ $w }}.brakeType === @js((string) $value)"
                    :data-active="{{ $w }}.brakeType === @js((string) $value) ? 'true' : 'false'"
                >{{ $optionLabel }}</button>
            @endforeach
        </div>
        <p class="rt-wl-hint">{{ __('app.brake_sole_legend') }}</p>

        <div class="rt-wl-fields rt-wl-fields--3 mt-3">
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.brake_weight_g') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" x-model="{{ $w }}.brakeG" class="{{ $decimalField }}" />
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.brake_weight_p') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" x-model="{{ $w }}.brakeP" class="{{ $decimalField }}" />
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.parking_brake_kn') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" x-model="{{ $w }}.parkingBrake" class="{{ $decimalField }}" />
            </label>
        </div>
        <p class="rt-wl-hint">{{ __('app.wagon_brake_weight_hint') }}</p>
    </div>
</section>

{{-- Laufweg, Gefahrgut, Bemerkung --}}
<section class="rt-wagon-card"{!! $anchor('route') !!}>
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-map-marked-alt" aria-hidden="true"></i></span>
        <h4>{{ __('app.route_and_notes') }}</h4>
    </header>
    <div class="rt-wagon-card-body rt-wl-fields">
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.shipping_station') }}</span>
            <input x-model="{{ $w }}.shippingStation" class="rt-wl-input" autocomplete="off">
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.destination_station') }}</span>
            <input x-model="{{ $w }}.destinationStation" class="rt-wl-input" autocomplete="off">
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.un_number') }}</span>
            <input x-model="{{ $w }}.unNumber" class="rt-wl-input" maxlength="39" autocomplete="off" placeholder="1202">
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.hazard_label') }}</span>
            <input x-model="{{ $w }}.hazardLabel" class="rt-wl-input" maxlength="20" autocomplete="off" placeholder="3">
        </label>
        <label class="rt-wl-field rt-wl-span-all">
            <span class="rt-wl-label">{{ __('app.remark') }}</span>
            <textarea x-model="{{ $w }}.remark" rows="2" maxlength="255" class="rt-wl-input"></textarea>
        </label>
    </div>
</section>
