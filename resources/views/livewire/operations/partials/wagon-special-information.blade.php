@props([])

@php
    // Besondere Angaben des Bremszettels (Zeilen 21 bis 32) in Sinngruppen.
    // „Gefahrgut“ (Zeile 31) ergibt sich in der v16_5 aus UN-Nummer und
    // Gefahrzettel der Wagen; die Angabe lässt sich hier nur übersteuern.
    $specialGroups = [
        [
            'id' => 'brake-safety',
            'icon' => 'far fa-shield-check',
            'title' => __('app.wagon_group_brake_safety'),
            'fields' => [
                'nbuepBrake' => __('app.nbuep_brake'),
                'emergencyBrakeBridge' => __('app.emergency_brake_bridge'),
                'epBrake' => __('app.ep_brake'),
            ],
        ],
        [
            'id' => 'passenger',
            'icon' => 'far fa-subway',
            'title' => __('app.wagon_group_passenger'),
            'fields' => [
                'passengerFeatureHzee' => __('app.passenger_feature_hzee'),
                'passengerFeatureNOe' => __('app.passenger_feature_noe'),
                'passengerFeatureTb0' => __('app.passenger_feature_tb0'),
                'passengerFeatureOZub' => __('app.passenger_feature_ozub'),
                'passengerFeatureOther' => __('app.passenger_feature_other'),
            ],
        ],
    ];

    // Zwei Antworten je Frage; erneutes Antippen der gewählten Antwort nimmt
    // sie zurück (unbeantwortet bleibt möglich, wie in der Excel-Liste).
    $choices = ['no' => __('app.no'), 'yes' => __('app.yes')];
    $scope = $scope ?? 'mobile';
    $gridClass = ($columns ?? 1) > 1 ? 'rt-wl-form-grid rt-wl-form-grid--2' : 'rt-wl-form-grid';
@endphp

<div class="{{ $gridClass }}">
    @foreach ($specialGroups as $group)
        <section class="rt-wagon-card" aria-labelledby="wagon-special-{{ $group['id'] }}-{{ $scope }}">
            <header class="rt-wagon-card-head">
                <span class="rt-wagon-card-icon"><i class="{{ $group['icon'] }}" aria-hidden="true"></i></span>
                <h4 id="wagon-special-{{ $group['id'] }}-{{ $scope }}">{{ $group['title'] }}</h4>
            </header>

            <div class="rt-wagon-card-body rt-wl-opts rt-wl-opts--flush">
                @foreach ($group['fields'] as $field => $label)
                    <div class="rt-wl-opt" :data-answered="brakeSheet.{{ $field }} ? 'true' : 'false'">
                        <span class="rt-wl-opt-label" id="wagon-special-label-{{ $field }}-{{ $scope }}">{{ $label }}</span>
                        <div class="rt-wl-seg" role="group" aria-labelledby="wagon-special-label-{{ $field }}-{{ $scope }}">
                            @foreach ($choices as $value => $optionLabel)
                                <button
                                    type="button"
                                    @click="brakeSheet.{{ $field }} = brakeSheet.{{ $field }} === @js($value) ? '' : @js($value)"
                                    :aria-pressed="brakeSheet.{{ $field }} === @js($value)"
                                    :data-active="brakeSheet.{{ $field }} === @js($value) ? 'true' : 'false'"
                                    class="rt-wl-seg-item"
                                >{{ $optionLabel }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="rt-wagon-card {{ ($columns ?? 1) > 1 ? 'xl:col-span-2' : '' }}" aria-labelledby="wagon-special-issuer-{{ $scope }}">
        <header class="rt-wagon-card-head">
            <span class="rt-wagon-card-icon"><i class="far fa-signature" aria-hidden="true"></i></span>
            <h4 id="wagon-special-issuer-{{ $scope }}">{{ __('app.wagon_group_issuer') }}</h4>
        </header>

        <div class="rt-wagon-card-body">
            <div class="rt-wl-opts rt-wl-opts--flush">
                <div class="rt-wl-opt">
                    <span class="rt-wl-opt-label" id="wagon-special-label-dangerousGoods-{{ $scope }}">
                        {{ __('app.dangerous_goods') }}
                    </span>
                    <div class="rt-wl-seg" role="group" aria-labelledby="wagon-special-label-dangerousGoods-{{ $scope }}">
                        <button
                            type="button"
                            @click="brakeSheet.dangerousGoods = ''"
                            :aria-pressed="brakeSheet.dangerousGoods === ''"
                            :data-active="brakeSheet.dangerousGoods === '' ? 'true' : 'false'"
                            class="rt-wl-seg-item"
                        >{{ __('app.automatic') }}</button>
                        @foreach (['no' => __('app.no'), 'yes' => __('app.yes')] as $value => $optionLabel)
                            <button
                                type="button"
                                @click="brakeSheet.dangerousGoods = @js($value)"
                                :aria-pressed="brakeSheet.dangerousGoods === @js($value)"
                                :data-active="brakeSheet.dangerousGoods === @js($value) ? 'true' : 'false'"
                                class="rt-wl-seg-item"
                            >{{ $optionLabel }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
            <p class="rt-wl-hint" x-show="brakeSheet.dangerousGoods === '' && totals.wagons > 0">
                {{ __('app.wagon_dangerous_goods_auto') }}:
                <strong x-text="totals.dangerousGoodsWagons > 0 ? @js(__('app.yes')) : @js(__('app.no'))"></strong>
            </p>

            <label class="rt-wl-field rt-wl-field--spaced">
                <span class="rt-wl-label">{{ __('app.issued_by_name') }}</span>
                <input x-model="brakeSheet.issuerName" class="rt-wl-input" autocomplete="off">
            </label>
        </div>
    </section>
</div>
