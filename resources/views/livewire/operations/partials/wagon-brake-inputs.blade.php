{{--
    Eingaben zum Bremszettel: arbeitende Triebfahrzeuge, Angaben laut
    Fahrplan und Bremsbauarten. Gemeinsam für Desktop und Telefon.
    Felder und Auswahllisten entsprechen der Excel-Liste v16_5
    (Bremszettel Spalte 5, Wagenliste Zeilen 55 bis 62).
--}}
@php
    $scope = $scope ?? 'desktop';
    $field = 'rt-wl-input rt-wl-input--center';
@endphp

<section class="rt-wagon-card" aria-labelledby="wagon-traction-title-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-train" aria-hidden="true"></i></span>
        <h4 id="wagon-traction-title-{{ $scope }}">{{ __('app.traction_vehicle') }}</h4>
    </header>
    <div class="rt-wagon-card-body rt-wl-fields rt-wl-fields--4">
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.weight_t') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" x-model="brakeSheet.tractionWeight" class="{{ $field }}" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.brake_weight_t') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" x-model="brakeSheet.tractionBrakeWeight" class="{{ $field }}" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.axles') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="40" x-model="brakeSheet.tractionAxles" class="{{ $field }}" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.train_length_m') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" x-model="brakeSheet.tractionLength" class="{{ $field }}" />
        </label>
    </div>
</section>

<section class="rt-wagon-card" aria-labelledby="wagon-timetable-title-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-route" aria-hidden="true"></i></span>
        <h4 id="wagon-timetable-title-{{ $scope }}">{{ __('app.wagon_timetable') }}</h4>
    </header>
    <div class="rt-wagon-card-body">
        <div class="rt-wl-fields">
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.minimum_brake_percentage') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="250" x-model="brakeSheet.minimumBrakePercentage" class="{{ $field }}" />
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.timetable_speed') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="200" x-model="brakeSheet.timetableSpeed" class="{{ $field }}" />
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.timetable_load') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="10000" x-model="brakeSheet.timetableLoad" class="{{ $field }}" />
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.timetable_length') }}</span>
                <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="1000" x-model="brakeSheet.timetableLength" class="{{ $field }}" />
            </label>
        </div>

        <div class="rt-wl-fields mt-3">
            <div class="rt-wl-field">
                <span class="rt-wl-label" id="wagon-position-label-{{ $scope }}">{{ __('app.brake_position') }}</span>
                <div class="rt-wl-chips" role="group" aria-labelledby="wagon-position-label-{{ $scope }}">
                    @foreach (['' => '—', 'R/P' => 'R/P', 'G' => 'G'] as $value => $optionLabel)
                        <button
                            type="button"
                            class="rt-wl-chip"
                            @click="brakeSheet.brakePosition = @js((string) $value)"
                            :aria-pressed="brakeSheet.brakePosition === @js((string) $value)"
                            :data-active="brakeSheet.brakePosition === @js((string) $value) ? 'true' : 'false'"
                        >{{ $optionLabel }}</button>
                    @endforeach
                </div>
            </div>
            <div class="rt-wl-field">
                <span class="rt-wl-label" id="wagon-order-label-{{ $scope }}">{{ __('app.list_order') }}</span>
                <div class="rt-wl-chips" role="group" aria-labelledby="wagon-order-label-{{ $scope }}">
                    @foreach (['head' => __('app.list_order_head'), 'tail' => __('app.list_order_tail')] as $value => $optionLabel)
                        <button
                            type="button"
                            class="rt-wl-chip"
                            @click="brakeSheet.listOrder = @js($value)"
                            :aria-pressed="brakeSheet.listOrder === @js($value)"
                            :data-active="brakeSheet.listOrder === @js($value) ? 'true' : 'false'"
                        >{{ $optionLabel }}</button>
                    @endforeach
                </div>
            </div>
            <div class="rt-wl-field">
                <span class="rt-wl-label" id="wagon-ninety-label-{{ $scope }}">{{ __('app.ninety_percent_rule') }}</span>
                <div class="rt-wl-chips" role="group" aria-labelledby="wagon-ninety-label-{{ $scope }}">
                    @foreach (['no' => __('app.no'), 'yes' => __('app.yes'), 'yes800' => __('app.ninety_percent_yes800')] as $value => $optionLabel)
                        <button
                            type="button"
                            class="rt-wl-chip"
                            @click="brakeSheet.ninetyPercentRule = @js($value)"
                            :aria-pressed="brakeSheet.ninetyPercentRule === @js($value)"
                            :data-active="brakeSheet.ninetyPercentRule === @js($value) ? 'true' : 'false'"
                        >{{ $optionLabel }}</button>
                    @endforeach
                </div>
            </div>
            <div class="rt-wl-field">
                <span class="rt-wl-label" id="wagon-class-label-{{ $scope }}">{{ __('app.route_class') }}</span>
                <div class="rt-wl-chips" role="group" aria-labelledby="wagon-class-label-{{ $scope }}">
                    @foreach (['', 'A', 'B1', 'B2', 'C2', 'C3', 'C4', 'CE', 'D2', 'D3', 'D4', 'E4', 'E5'] as $value)
                        <button
                            type="button"
                            class="rt-wl-chip"
                            @click="brakeSheet.routeClass = @js($value)"
                            :aria-pressed="brakeSheet.routeClass === @js($value)"
                            :data-active="brakeSheet.routeClass === @js($value) ? 'true' : 'false'"
                        >{{ $value === '' ? '—' : $value }}</button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

<section class="rt-wagon-card" aria-labelledby="wagon-brake-types-title-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-cog" aria-hidden="true"></i></span>
        <h4 id="wagon-brake-types-title-{{ $scope }}">{{ __('app.wagon_brake_types') }}</h4>
    </header>
    <div class="rt-wagon-card-body rt-wl-fields">
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.single_release_brakes') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" x-model="brakeSheet.singleReleaseBrakes" class="{{ $field }}" />
        </label>
        <label class="rt-wl-field">
            <span class="rt-wl-label">{{ __('app.matrossow_brakes') }}</span>
            <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" x-model="brakeSheet.matrossowBrakes" class="{{ $field }}" />
        </label>
    </div>
</section>
