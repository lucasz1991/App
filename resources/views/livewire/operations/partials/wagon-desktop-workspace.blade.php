<section class="hidden h-full min-h-0 flex-col lg:flex" data-wagon-desktop-workspace>
    <nav class="rt-wagon-section-nav shrink-0 border-b border-rt-border/70 bg-rt-surface/95 px-5 py-2 backdrop-blur-xl dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/95" aria-label="{{ __('app.wagon_workspace') }}">
        <div class="mx-auto flex max-w-[100rem] items-center gap-4">
            <div class="inline-flex rounded-xl border border-rt-border bg-rt-surface-muted p-1 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="desktopSection === 'wagons'"
                    :data-active="desktopSection === 'wagons' ? 'true' : 'false'"
                    @click="desktopSection = 'wagons'"
                    class="rt-wagon-section-tab inline-flex min-h-9 items-center gap-2 rounded-lg px-4 text-sm font-semibold transition"
                >
                    <i class="fad fa-train" aria-hidden="true"></i>
                    {{ __('app.wagon_list') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="desktopSection === 'brakeSheet'"
                    :data-active="desktopSection === 'brakeSheet' ? 'true' : 'false'"
                    @click="desktopSection = 'brakeSheet'"
                    class="rt-wagon-section-tab inline-flex min-h-9 items-center gap-2 rounded-lg px-4 text-sm font-semibold transition"
                >
                    <i class="fad fa-clipboard-check" aria-hidden="true"></i>
                    {{ __('app.brake_sheet') }}
                </button>
            </div>

        </div>
    </nav>

    <div class="rt-wagon-desktop-scroll min-h-0 flex-1 overflow-hidden">
        <div class="mx-auto h-full min-h-0 w-full max-w-[100rem] px-5 py-4">
            <div x-show.important="desktopSection === 'wagons'" class="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-3" role="tabpanel">
                <section class="rt-wagon-workspace rounded-xl px-4 py-3" aria-labelledby="wagon-meta-title">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <h2 id="wagon-meta-title" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.train_data') }}</h2>
                            <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.train_data_hint') }}</p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-5 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.train_number') }}
                            <input x-model="meta.trainNumber" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.date') }}
                            <input x-model="meta.date" type="date" class="{{ $inputClass }}">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.from') }}
                            <input x-model="meta.origin" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.to') }}
                            <input x-model="meta.destination" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.reference') }}
                            <input x-model="meta.reference" type="text" class="{{ $inputClass }}" autocomplete="off">
                        </label>
                    </div>
                </section>

                <section class="rt-wagon-summary-band grid grid-cols-6 divide-x divide-rt-border/70 overflow-hidden rounded-xl dark:divide-rt-dark-border/70" aria-label="{{ __('app.wagon_totals') }}">
                    <template x-for="item in [
                        { label: @js(__('app.wagons')), value: totals.wagons, suffix: '' },
                        { label: @js(__('app.axles')), value: totals.axles, suffix: '' },
                        { label: @js(__('app.length_over_buffers')), value: formatNumber(totals.length), suffix: ' m' },
                        { label: @js(__('app.total_weight')), value: formatNumber(totals.totalWeight), suffix: ' t' },
                        { label: @js(__('app.brake_weight_g')), value: formatNumber(totals.brakeG), suffix: ' t' },
                        { label: @js(__('app.brake_weight_p')), value: formatNumber(totals.brakeP), suffix: ' t' },
                    ]" :key="item.label">
                        <div class="min-w-0 px-3 py-2.5">
                            <p class="text-[11px] font-medium leading-4 opacity-65" x-text="item.label"></p>
                            <p class="mt-0.5 text-base font-bold tabular-nums"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </section>

                @include('livewire.operations.partials.wagon-sheet-grid', [
                    'sheetInput' => $sheetInput,
                    'inputClass' => $inputClass,
                    'labelClass' => $labelClass,
                ])
            </div>

            <div x-show.important="desktopSection === 'brakeSheet'" x-cloak class="h-full space-y-4 overflow-y-auto overscroll-contain pr-1" role="tabpanel">
                <section class="grid grid-cols-4 gap-3" aria-label="{{ __('app.brake_sheet_summary') }}">
                    <template x-for="item in [
                        { label: @js(__('app.total_weight')), value: formatNumber(brakeTotals.trainWeight), suffix: ' t' },
                        { label: @js(__('app.brake_weight')), value: formatNumber(brakeTotals.brakeWeight), suffix: ' t' },
                        { label: @js(__('app.axles')), value: brakeTotals.axles, suffix: '' },
                        { label: @js(__('app.available_brake_percentage')), value: brakeTotals.availablePercentage, suffix: ' %' },
                    ]" :key="item.label">
                        <div class="rt-wagon-total rounded-xl p-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-65" x-text="item.label"></p>
                            <p class="mt-1 text-2xl font-bold tabular-nums"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </section>

                <section class="rt-wagon-workspace rounded-2xl p-5 shadow-rt-sm">
                    <div class="flex items-end justify-between gap-4 border-b border-rt-border/70 pb-4 dark:border-rt-dark-border/70">
                        <div>
                            <h2 class="text-xl font-semibold tracking-tight">{{ __('app.brake_sheet') }}</h2>
                            <p class="mt-1 text-sm opacity-65"><span x-text="meta.trainNumber || '—'"></span> · <span x-text="meta.origin || '—'"></span> → <span x-text="meta.destination || '—'"></span></p>
                        </div>
                        <p class="text-xs font-semibold opacity-65"><span x-text="meta.date"></span></p>
                    </div>

                    <div class="mt-4 grid gap-4 xl:grid-cols-2">
                        <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                            <legend class="px-1 text-sm font-semibold">{{ __('app.traction_vehicle') }}</legend>
                            <div class="mt-2 grid grid-cols-3 gap-3">
                                <label class="{{ $labelClass }}">{{ __('app.weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="brakeSheet.tractionWeight" class="mt-1" /></label>
                                <label class="{{ $labelClass }}">{{ __('app.brake_weight_t') }}<x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="brakeSheet.tractionBrakeWeight" class="mt-1" /></label>
                                <label class="{{ $labelClass }}">{{ __('app.axles') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.tractionAxles" class="mt-1" /></label>
                            </div>
                        </fieldset>

                        <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                            <legend class="px-1 text-sm font-semibold">{{ __('app.brake_calculation') }}</legend>
                            <div class="mt-2 grid grid-cols-3 gap-3">
                                <label class="{{ $labelClass }}">{{ __('app.minimum_brake_percentage') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.minimumBrakePercentage" class="mt-1" /></label>
                                <div class="rt-wagon-calculated rounded-lg p-3"><span class="text-[10px] font-bold uppercase opacity-65">{{ __('app.available') }}</span><strong class="mt-1 block text-lg"><span x-text="brakeTotals.availablePercentage"></span> %</strong></div>
                                <div class="rt-wagon-calculated rounded-lg p-3"><span class="text-[10px] font-bold uppercase opacity-65">{{ __('app.missing') }}</span><strong class="mt-1 block text-lg" :class="brakeTotals.missingPercentage > 0 ? 'text-red-600 dark:text-red-300' : 'text-emerald-600 dark:text-emerald-300'"><span x-text="brakeTotals.missingPercentage"></span> %</strong></div>
                            </div>
                        </fieldset>

                        <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                            <legend class="px-1 text-sm font-semibold">{{ __('app.freight_train_data') }}</legend>
                            <dl class="mt-2 divide-y divide-rt-border/70 text-sm dark:divide-rt-dark-border/70">
                                <div class="flex justify-between gap-4 py-2"><dt class="opacity-65">{{ __('app.last_vehicle_number') }}</dt><dd class="text-right font-semibold" x-text="brakeTotals.lastVehicle || '—'"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="opacity-65">{{ __('app.brakes_count') }}</dt><dd class="font-semibold" x-text="totals.brakeCount"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="opacity-65">{{ __('app.disc_brakes_count') }}</dt><dd class="font-semibold" x-text="totals.discBrakes"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="opacity-65">{{ __('app.plastic_brakes_count') }}</dt><dd class="font-semibold" x-text="totals.plasticBrakes"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="opacity-65">{{ __('app.length_over_buffers') }}</dt><dd class="font-semibold"><span x-text="formatNumber(totals.length)"></span> m</dd></div>
                            </dl>
                            <label class="mt-3 block {{ $labelClass }}">{{ __('app.braked_axles') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.brakedAxles" class="mt-1" /></label>
                        </fieldset>

                        <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                            <legend class="px-1 text-sm font-semibold">{{ __('app.special_information') }}</legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
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
                                    <div>
                                        <span class="{{ $labelClass }}">{{ $label }}</span>
                                        <div class="mt-1 grid grid-cols-3 rounded-xl border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control">
                                            @foreach (['' => '—', 'no' => __('app.no'), 'yes' => __('app.yes')] as $value => $optionLabel)
                                                <button type="button" @click="brakeSheet.{{ $field }} = @js($value)" :data-active="brakeSheet.{{ $field }} === @js($value) ? 'true' : 'false'" class="rt-wagon-choice min-h-9 rounded-lg px-2 text-xs font-semibold transition">{{ $optionLabel }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                                <label class="{{ $labelClass }}">{{ __('app.lower_vehicle_speed') }}<x-ui.forms.number-input min="0" x-model="brakeSheet.lowerVehicleSpeed" class="mt-1" /></label>
                                <label class="{{ $labelClass }} sm:col-span-2">{{ __('app.issued_by_name') }}<input x-model="brakeSheet.issuerName" class="{{ $inputClass }}"></label>
                            </div>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </div>
</section>
