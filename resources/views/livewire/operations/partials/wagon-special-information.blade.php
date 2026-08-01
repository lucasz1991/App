@props([])

@php
    // Besondere Angaben in drei Sinngruppen statt einer neunzeiligen Liste.
    // Vorher stand alles ungeordnet untereinander — auf dem Telefon laenger
    // als der sichtbare Bereich und dadurch schwer zu ueberblicken.
    $specialGroups = [
        [
            'id' => 'brake-safety',
            'icon' => 'far fa-shield-check',
            'title' => __('app.wagon_group_brake_safety'),
            'fields' => [
                'nbuepBrake' => __('app.nbuep_brake'),
                'emergencyBrakeBridge' => __('app.emergency_brake_bridge'),
                'epBrake' => __('app.ep_brake'),
                'dangerousGoods' => __('app.dangerous_goods'),
            ],
        ],
        [
            'id' => 'passenger',
            'icon' => 'far fa-train-subway',
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

    $choices = ['' => '—', 'no' => __('app.no'), 'yes' => __('app.yes')];
    $gridClass = ($columns ?? 1) > 1 ? 'grid gap-3 xl:grid-cols-2' : 'grid gap-3';
@endphp

<div class="{{ $gridClass }}">
    @foreach ($specialGroups as $group)
        <section class="rt-wagon-card" aria-labelledby="wagon-special-{{ $group['id'] }}-{{ $scope ?? 'mobile' }}">
            <header class="rt-wagon-card-head">
                <span class="rt-wagon-card-icon"><i class="{{ $group['icon'] }}" aria-hidden="true"></i></span>
                <h4 id="wagon-special-{{ $group['id'] }}-{{ $scope ?? 'mobile' }}">{{ $group['title'] }}</h4>
            </header>

            <div class="rt-wagon-card-body rt-wagon-answer-list">
                @foreach ($group['fields'] as $field => $label)
                    <div class="rt-wagon-answer-row" :data-answered="brakeSheet.{{ $field }} ? 'true' : 'false'">
                        <span class="rt-wagon-answer-label" id="wagon-special-label-{{ $field }}-{{ $scope ?? 'mobile' }}">{{ $label }}</span>
                        <div class="rt-wagon-segment" role="group" aria-labelledby="wagon-special-label-{{ $field }}-{{ $scope ?? 'mobile' }}">
                            @foreach ($choices as $value => $optionLabel)
                                <button
                                    type="button"
                                    @click="brakeSheet.{{ $field }} = @js($value)"
                                    :aria-pressed="brakeSheet.{{ $field }} === @js($value)"
                                    :data-active="brakeSheet.{{ $field }} === @js($value) ? 'true' : 'false'"
                                    class="rt-wagon-choice"
                                >{{ $optionLabel }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="rt-wagon-card {{ ($columns ?? 1) > 1 ? 'xl:col-span-2' : '' }}" aria-labelledby="wagon-special-issuer-{{ $scope ?? 'mobile' }}">
        <header class="rt-wagon-card-head">
            <span class="rt-wagon-card-icon"><i class="far fa-signature" aria-hidden="true"></i></span>
            <h4 id="wagon-special-issuer-{{ $scope ?? 'mobile' }}">{{ __('app.wagon_group_issuer') }}</h4>
        </header>

        <div class="rt-wagon-card-body grid gap-3 sm:grid-cols-2">
            <label class="{{ $labelClass }}">{{ __('app.lower_vehicle_speed') }}
                <x-ui.forms.number-input min="0" x-model="brakeSheet.lowerVehicleSpeed" class="mt-1" />
            </label>
            <label class="{{ $labelClass }}">{{ __('app.issued_by_name') }}
                <input x-model="brakeSheet.issuerName" class="{{ $inputClass }}" autocomplete="off">
            </label>
        </div>
    </section>
</div>
