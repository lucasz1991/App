<section
    class="rt-wagon-mobile-wizard min-h-0 flex-1 lg:hidden"
    data-wagon-wizard
    data-mobile-wagon-editor
    @keydown="handleMobileWizardKeydown($event)"
>
    <header class="rt-wagon-wizard-progress shrink-0 border-b border-rt-border/70 bg-rt-surface/95 px-3 pb-2.5 pt-3 backdrop-blur-xl dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/95">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">
                    <span x-text="mobileStep + 1"></span>/<span x-text="mobileStepCount"></span>
                    · {{ __('app.wagon_wizard') }}
                </p>
                <h3 class="mt-0.5 truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="mobileStepTitle" aria-live="polite"></h3>
            </div>
            <p class="shrink-0 text-[11px] font-medium text-rt-muted dark:text-rt-dark-muted">
                <i class="far fa-hand-pointer mr-1" aria-hidden="true"></i>{{ __('app.swipe_to_continue') }}
            </p>
        </div>

        <div class="mt-2.5 h-1 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted" aria-hidden="true">
            <span class="rt-wagon-wizard-progress-bar block h-full rounded-full bg-rt-red" :style="`width: ${mobileStepProgress}%`"></span>
        </div>

        <ol class="mt-2 grid grid-cols-8 gap-1" aria-label="{{ __('app.wagon_wizard_steps') }}">
            <template x-for="(step, index) in mobileSteps" :key="step.id">
                <li>
                    <button
                        type="button"
                        @click="goToMobileStep(index)"
                        :aria-current="mobileStep === index ? 'step' : null"
                        :aria-label="`${index + 1}. ${step.label}`"
                        :title="step.label"
                        :data-active="mobileStep === index ? 'true' : 'false'"
                        class="rt-wagon-step-dot flex h-7 w-full items-center justify-center rounded-md text-[10px] font-bold tabular-nums transition"
                    >
                        <span x-text="index + 1"></span>
                    </button>
                </li>
            </template>
        </ol>
    </header>

    <div
        x-show.important="isMobileWagonStep"
        class="rt-wagon-selector shrink-0 border-b border-rt-border/70 bg-rt-canvas px-3 py-2 dark:border-rt-dark-border/70 dark:bg-rt-dark-canvas"
    >
        <div class="flex items-center gap-2">
            <div class="rt-wagon-index-strip flex min-w-0 flex-1 gap-1.5 overflow-x-auto" aria-label="{{ __('app.choose_wagon') }}">
                <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                    <button
                        type="button"
                        @click="showMobileWagon(index)"
                        :data-active="mobileWagon === index ? 'true' : 'false'"
                        :data-filled="isWagonFilled(wagon) ? 'true' : 'false'"
                        class="rt-wagon-index-button relative flex h-10 min-w-10 shrink-0 items-center justify-center rounded-lg text-xs font-bold tabular-nums transition"
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
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-accent text-white shadow-rt-xs transition active:scale-[0.96] disabled:cursor-not-allowed disabled:opacity-40 dark:bg-rt-dark-accent dark:text-slate-950"
                title="{{ __('app.add_wagon') }}"
                aria-label="{{ __('app.add_wagon') }}"
            >
                <i class="far fa-plus" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div
        x-ref="mobilePager"
        class="rt-wagon-mobile-pager min-h-0 flex-1"
        @scroll.passive="syncMobileStepFromScroll($event)"
        @scrollend="settleMobilePager($event.currentTarget)"
        @resize.window.debounce.100ms="realignMobilePager()"
        aria-roledescription="carousel"
        aria-label="{{ __('app.wagon_wizard_steps') }}"
    >
        <section class="rt-wagon-mobile-slide" data-wagon-step="train" :aria-hidden="mobileStep !== 0" :inert="mobileStep !== 0" aria-labelledby="wagon-mobile-step-train">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-route" aria-hidden="true"></i></span>
                    <div>
                        <h3 id="wagon-mobile-step-train">{{ __('app.train_data') }}</h3>
                        <p>{{ __('app.train_data_hint') }}</p>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <label class="{{ $labelClass }}">{{ __('app.train_number') }}<input x-model="meta.trainNumber" type="text" class="{{ $inputClass }}" autocomplete="off"></label>
                    <label class="{{ $labelClass }}">{{ __('app.date') }}<input x-model="meta.date" type="date" class="{{ $inputClass }}"></label>
                    <label class="{{ $labelClass }}">{{ __('app.from') }}<input x-model="meta.origin" type="text" class="{{ $inputClass }}" autocomplete="off"></label>
                    <label class="{{ $labelClass }}">{{ __('app.to') }}<input x-model="meta.destination" type="text" class="{{ $inputClass }}" autocomplete="off"></label>
                    <label class="{{ $labelClass }} col-span-2">{{ __('app.reference') }}<input x-model="meta.reference" type="text" class="{{ $inputClass }}" autocomplete="off"></label>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-2" aria-label="{{ __('app.wagon_totals') }}">
                    <template x-for="item in [
                        { label: @js(__('app.wagons')), value: totals.wagons, suffix: '' },
                        { label: @js(__('app.axles')), value: totals.axles, suffix: '' },
                        { label: @js(__('app.length_over_buffers')), value: formatNumber(totals.length), suffix: ' m' },
                        { label: @js(__('app.total_weight')), value: formatNumber(totals.totalWeight), suffix: ' t' },
                        { label: @js(__('app.brake_weight_g')), value: formatNumber(totals.brakeG), suffix: ' t' },
                        { label: @js(__('app.brake_weight_p')), value: formatNumber(totals.brakeP), suffix: ' t' },
                    ]" :key="item.label">
                        <div class="rt-wagon-mobile-metric min-w-0 rounded-xl p-2.5">
                            <p class="truncate text-[9px] font-bold uppercase tracking-[0.05em] opacity-60" x-text="item.label"></p>
                            <p class="mt-1 truncate text-base font-bold tabular-nums"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="identity" :aria-hidden="mobileStep !== 1" :inert="mobileStep !== 1" aria-labelledby="wagon-mobile-step-identity">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-wagon-number" x-text="mobileWagon + 1"></span>
                    <div class="min-w-0 flex-1">
                        <h3 id="wagon-mobile-step-identity">{{ __('app.identification') }}</h3>
                        <p class="truncate" x-text="wagonNumber(wagons[mobileWagon]) || @js(__('app.wagon_not_filled'))"></p>
                    </div>
                    <button type="button" @click="clearWagon(mobileWagon)" class="rt-wagon-mobile-icon-action text-red-500 dark:text-red-300" title="{{ __('app.clear_wagon') }}" aria-label="{{ __('app.clear_wagon') }}"><i class="far fa-trash-alt" aria-hidden="true"></i></button>
                </div>

                <fieldset class="rt-wagon-fieldset mt-4 rounded-xl p-3">
                    <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.wagon_number') }}</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">1+2<input x-model="wagons[mobileWagon].number12" inputmode="numeric" maxlength="2" class="{{ $inputClass }}"></label>
                        <label class="{{ $labelClass }}">3+4<input x-model="wagons[mobileWagon].number34" inputmode="numeric" maxlength="2" class="{{ $inputClass }}"></label>
                        <label class="{{ $labelClass }}">5–8<input x-model="wagons[mobileWagon].number58" inputmode="numeric" maxlength="4" class="{{ $inputClass }}"></label>
                        <label class="{{ $labelClass }}">9–11<input x-model="wagons[mobileWagon].number911" inputmode="numeric" maxlength="3" class="{{ $inputClass }}"></label>
                        <label class="{{ $labelClass }}">12 · {{ __('app.expected_check_digit') }}<input x-model="wagons[mobileWagon].checkDigit" inputmode="numeric" maxlength="1" class="{{ $inputClass }}"></label>
                        <div class="rt-wagon-validation-state mt-1 flex min-h-11 items-center gap-2 rounded-lg px-3 text-xs font-semibold" :data-state="checkState(wagons[mobileWagon])">
                            <i class="far" :class="checkState(wagons[mobileWagon]) === 'valid' ? 'fa-check-circle' : (checkState(wagons[mobileWagon]) === 'invalid' ? 'fa-exclamation-circle' : 'fa-circle-dashed')" aria-hidden="true"></i>
                            <span x-show="checkState(wagons[mobileWagon]) === 'valid'">{{ __('app.wagon_number_valid') }}</span>
                            <span x-show="checkState(wagons[mobileWagon]) === 'invalid'">{{ __('app.expected_check_digit') }}: <span x-text="expectedCheckDigit(wagons[mobileWagon])"></span></span>
                            <span x-show="checkState(wagons[mobileWagon]) === 'incomplete'">{{ __('app.wagon_not_filled') }}</span>
                        </div>
                    </div>
                </fieldset>

                <label class="mt-4 block {{ $labelClass }}">{{ __('app.category') }}<input x-model="wagons[mobileWagon].category" class="{{ $inputClass }}" autocomplete="off"></label>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="vehicle" :aria-hidden="mobileStep !== 2" :inert="mobileStep !== 2" aria-labelledby="wagon-mobile-step-vehicle">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-weight-hanging" aria-hidden="true"></i></span>
                    <div><h3 id="wagon-mobile-step-vehicle">{{ __('app.axles_dimensions') }}</h3><p>{{ __('app.wagon_step_vehicle_hint') }}</p></div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <label class="{{ $labelClass }}">{{ __('app.axles_empty') }}<x-ui.forms.number-input min="0" x-model="wagons[mobileWagon].axlesEmpty" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.axles_loaded') }}<x-ui.forms.number-input min="0" x-model="wagons[mobileWagon].axlesLoaded" class="mt-1" /></label>
                    <label class="{{ $labelClass }} col-span-2">{{ __('app.length_m') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].length" class="mt-1" /></label>
                </div>

                <fieldset class="rt-wagon-fieldset mt-4 rounded-xl p-3">
                    <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.weights_and_brakes') }}</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.wagon_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].wagonWeight" class="mt-1" /></label>
                        <label class="{{ $labelClass }}">{{ __('app.load_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].loadWeight" class="mt-1" /></label>
                        <div class="rt-wagon-calculated col-span-2 rounded-xl p-3">
                            <span class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-65">{{ __('app.total_weight') }}</span>
                            <strong class="mt-1 block text-2xl tabular-nums"><span x-text="formatNumber(totalWeight(wagons[mobileWagon]))"></span> t</strong>
                        </div>
                    </div>
                </fieldset>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="brakes" :aria-hidden="mobileStep !== 3" :inert="mobileStep !== 3" aria-labelledby="wagon-mobile-step-brakes">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-gauge-high" aria-hidden="true"></i></span>
                    <div><h3 id="wagon-mobile-step-brakes">{{ __('app.brakes') }}</h3><p>{{ __('app.wagon_step_brakes_hint') }}</p></div>
                </div>

                <div class="mt-4">
                    <span class="{{ $labelClass }}">{{ __('app.brake_type') }}</span>
                    <div class="mt-1 grid grid-cols-4 rounded-xl border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control">
                        @foreach (['' => '—', 'K' => 'K', 'L' => 'L', 'LL' => 'LL'] as $value => $optionLabel)
                            <button type="button" @click="wagons[mobileWagon].brakeType = @js($value)" :data-active="wagons[mobileWagon].brakeType === @js($value) ? 'true' : 'false'" class="rt-wagon-choice min-h-10 rounded-lg px-2 text-xs font-semibold transition">{{ $optionLabel }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <label class="{{ $labelClass }}">{{ __('app.brake_weight_g') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].brakeG" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.brake_weight_p') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[mobileWagon].brakeP" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.parking_brake_kn') }}<x-ui.forms.number-input min="0" step="0.1" :decimals="1" x-model="wagons[mobileWagon].parkingBrake" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.maximum_speed') }}<x-ui.forms.number-input min="0" x-model="wagons[mobileWagon].maxSpeed" class="mt-1" /></label>
                </div>

                <label class="rt-wagon-check-card mt-4 flex min-h-14 items-center gap-3 rounded-xl px-3 py-2 text-sm font-semibold">
                    <input x-model="wagons[mobileWagon].discBrake" type="checkbox" class="h-5 w-5 rounded border-rt-border text-rt-accent focus:ring-rt-accent/35 dark:border-rt-dark-border">
                    <span><strong class="block">{{ __('app.disc_brake') }}</strong><small class="mt-0.5 block font-normal opacity-65">{{ __('app.wagon_disc_brake_hint') }}</small></span>
                </label>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="route" :aria-hidden="mobileStep !== 4" :inert="mobileStep !== 4" aria-labelledby="wagon-mobile-step-route">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-location-dot" aria-hidden="true"></i></span>
                    <div><h3 id="wagon-mobile-step-route">{{ __('app.route_and_notes') }}</h3><p>{{ __('app.wagon_step_route_hint') }}</p></div>
                </div>

                <div class="mt-4 grid gap-3">
                    <label class="{{ $labelClass }}">{{ __('app.shipping_station') }}<input x-model="wagons[mobileWagon].shippingStation" class="{{ $inputClass }}" autocomplete="off"></label>
                    <label class="{{ $labelClass }}">{{ __('app.destination_station') }}<input x-model="wagons[mobileWagon].destinationStation" class="{{ $inputClass }}" autocomplete="off"></label>
                    <label class="{{ $labelClass }}">{{ __('app.remark') }}<textarea x-model="wagons[mobileWagon].remark" rows="3" class="{{ $inputClass }} resize-none"></textarea></label>
                </div>

                <div class="rt-wagon-route-preview mt-4 rounded-xl p-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-60">{{ __('app.route') }}</p>
                    <p class="mt-1 flex items-center gap-2 text-sm font-semibold"><span class="truncate" x-text="wagons[mobileWagon].shippingStation || '—'"></span><i class="far fa-arrow-right shrink-0 opacity-45" aria-hidden="true"></i><span class="truncate" x-text="wagons[mobileWagon].destinationStation || '—'"></span></p>
                </div>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="calculation" :aria-hidden="mobileStep !== 5" :inert="mobileStep !== 5" aria-labelledby="wagon-mobile-step-calculation">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-calculator" aria-hidden="true"></i></span>
                    <div><h3 id="wagon-mobile-step-calculation">{{ __('app.brake_calculation') }}</h3><p>{{ __('app.wagon_step_calculation_hint') }}</p></div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <label class="{{ $labelClass }}">{{ __('app.weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="brakeSheet.tractionWeight" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.brake_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="brakeSheet.tractionBrakeWeight" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.axles') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.tractionAxles" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.braked_axles') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.brakedAxles" class="mt-1" /></label>
                    <label class="{{ $labelClass }} col-span-2">{{ __('app.minimum_brake_percentage') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.minimumBrakePercentage" class="mt-1" /></label>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <div class="rt-wagon-mobile-metric rounded-xl p-3"><p class="text-[9px] font-bold uppercase tracking-[0.05em] opacity-60">{{ __('app.available') }}</p><strong class="mt-1 block text-xl tabular-nums"><span x-text="brakeTotals.availablePercentage"></span> %</strong></div>
                    <div class="rt-wagon-mobile-metric rounded-xl p-3"><p class="text-[9px] font-bold uppercase tracking-[0.05em] opacity-60">{{ __('app.missing') }}</p><strong class="mt-1 block text-xl tabular-nums" :class="brakeTotals.missingPercentage > 0 ? 'text-red-600 dark:text-red-300' : 'text-emerald-600 dark:text-emerald-300'"><span x-text="brakeTotals.missingPercentage"></span> %</strong></div>
                </div>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="special" :aria-hidden="mobileStep !== 6" :inert="mobileStep !== 6" aria-labelledby="wagon-mobile-step-special">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-shield-check" aria-hidden="true"></i></span>
                    <div><h3 id="wagon-mobile-step-special">{{ __('app.special_information') }}</h3><p>{{ __('app.wagon_step_special_hint') }}</p></div>
                </div>

                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ([
                        'nbuepBrake' => __('app.nbuep_brake'),
                        'emergencyBrakeBridge' => __('app.emergency_brake_bridge'),
                        'passengerFeatureHzee' => __('app.passenger_feature_hzee'),
                        'passengerFeatureNOe' => __('app.passenger_feature_noe'),
                        'passengerFeatureTb0' => __('app.passenger_feature_tb0'),
                        'passengerFeatureOZub' => __('app.passenger_feature_ozub'),
                        'passengerFeatureOther' => __('app.passenger_feature_other'),
                        'dangerousGoods' => __('app.dangerous_goods'),
                        'epBrake' => __('app.ep_brake'),
                    ] as $field => $label)
                        <div class="rt-wagon-special-row rounded-xl p-2.5">
                            <span class="block text-xs font-semibold leading-4">{{ $label }}</span>
                            <div class="mt-2 grid grid-cols-3 rounded-lg border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control">
                                @foreach (['' => '—', 'no' => __('app.no'), 'yes' => __('app.yes')] as $value => $optionLabel)
                                    <button type="button" @click="brakeSheet.{{ $field }} = @js($value)" :data-active="brakeSheet.{{ $field }} === @js($value) ? 'true' : 'false'" class="rt-wagon-choice min-h-9 rounded-md px-1 text-[11px] font-semibold transition">{{ $optionLabel }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="{{ $labelClass }}">{{ __('app.lower_vehicle_speed') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.lowerVehicleSpeed" class="mt-1" /></label>
                    <label class="{{ $labelClass }}">{{ __('app.issued_by_name') }}<input x-model="brakeSheet.issuerName" class="{{ $inputClass }}"></label>
                </div>
            </div>
        </section>

        <section class="rt-wagon-mobile-slide" data-wagon-step="review" :aria-hidden="mobileStep !== 7" :inert="mobileStep !== 7" aria-labelledby="wagon-mobile-step-review">
            <div class="rt-wagon-mobile-slide-scroll">
                <div class="rt-wagon-mobile-section-head">
                    <span class="rt-wagon-mobile-section-icon"><i class="far fa-clipboard-check" aria-hidden="true"></i></span>
                    <div><h3 id="wagon-mobile-step-review">{{ __('app.review_and_finish') }}</h3><p>{{ __('app.wagon_step_review_hint') }}</p></div>
                </div>

                <div class="rt-wagon-review-train mt-4 rounded-2xl p-4">
                    <p class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-60">{{ __('app.train_data') }}</p>
                    <div class="mt-2 flex items-start justify-between gap-3">
                        <div class="min-w-0"><strong class="block truncate text-xl" x-text="meta.trainNumber || @js(__('app.wagon_not_filled'))"></strong><p class="mt-1 truncate text-sm opacity-70"><span x-text="meta.origin || '—'"></span> → <span x-text="meta.destination || '—'"></span></p></div>
                        <span class="shrink-0 rounded-lg bg-rt-surface-muted px-2.5 py-1.5 text-xs font-bold tabular-nums dark:bg-rt-dark-surface-muted"><span x-text="completionCount"></span>/<span x-text="visibleCount"></span></span>
                    </div>
                </div>

                <dl class="rt-wagon-review-list mt-3 divide-y divide-rt-border/70 rounded-2xl px-4 dark:divide-rt-dark-border/70">
                    <div class="flex items-center justify-between gap-4 py-3"><dt>{{ __('app.total_weight') }}</dt><dd><span x-text="formatNumber(brakeTotals.trainWeight)"></span> t</dd></div>
                    <div class="flex items-center justify-between gap-4 py-3"><dt>{{ __('app.brake_weight') }}</dt><dd><span x-text="formatNumber(brakeTotals.brakeWeight)"></span> t</dd></div>
                    <div class="flex items-center justify-between gap-4 py-3"><dt>{{ __('app.axles') }}</dt><dd x-text="brakeTotals.axles"></dd></div>
                    <div class="flex items-center justify-between gap-4 py-3"><dt>{{ __('app.available_brake_percentage') }}</dt><dd><span x-text="brakeTotals.availablePercentage"></span> %</dd></div>
                    <div class="flex items-center justify-between gap-4 py-3"><dt>{{ __('app.last_vehicle_number') }}</dt><dd class="max-w-[60%] truncate" x-text="brakeTotals.lastVehicle || '—'"></dd></div>
                </dl>

                <button
                    type="button"
                    @click="exportWorkbook()"
                    :disabled="exporting"
                    class="mt-4 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-4 text-sm font-semibold text-emerald-700 shadow-rt-xs transition active:scale-[0.98] disabled:cursor-wait disabled:opacity-65 dark:border-emerald-500/35 dark:bg-emerald-500/10 dark:text-emerald-300"
                >
                    <i class="far" :class="exporting ? 'fa-spinner fa-spin' : 'fa-file-excel'" aria-hidden="true"></i>
                    <span x-text="exporting ? @js(__('app.wagon_exporting')) : @js(__('app.export_excel'))"></span>
                </button>
            </div>
        </section>
    </div>

    <footer class="rt-wagon-wizard-footer shrink-0 border-t border-rt-border/70 bg-rt-surface/95 px-3 pt-2.5 shadow-[0_-8px_24px_-18px_rgba(15,23,42,0.45)] backdrop-blur-xl dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/95">
        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 pb-[max(0.65rem,env(safe-area-inset-bottom))]">
            <button type="button" @click="previousMobileStep()" :disabled="mobileStep === 0" class="rt-wagon-wizard-action justify-self-start" aria-label="{{ __('app.previous') }}">
                <i class="far fa-arrow-left" aria-hidden="true"></i><span>{{ __('app.previous') }}</span>
            </button>
            <span class="min-w-16 text-center text-[11px] font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted"><span x-text="mobileStep + 1"></span> / <span x-text="mobileStepCount"></span></span>
            <button x-show.important="mobileStep < mobileStepCount - 1" type="button" @click="nextMobileStep()" class="rt-wagon-wizard-action rt-wagon-wizard-action-primary justify-self-end">
                <span>{{ __('app.next') }}</span><i class="far fa-arrow-right" aria-hidden="true"></i>
            </button>
            <button x-show.important="mobileStep === mobileStepCount - 1" type="button" @click="saveAndClose()" class="rt-wagon-wizard-action rt-wagon-wizard-action-primary justify-self-end">
                <i class="far fa-check" aria-hidden="true"></i><span>{{ $labels['saveAndClose'] }}</span>
            </button>
        </div>
    </footer>
</section>
