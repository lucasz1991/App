<div
    x-data="wagonListPrototype(@js([
        'storageKey' => $storageKey,
        'locale' => app()->getLocale() === 'de' ? 'de-DE' : 'en-GB',
        'resetTitle' => __('app.wagon_reset_title'),
        'resetText' => __('app.wagon_reset_text'),
        'resetConfirm' => __('app.reset'),
        'cancel' => __('app.cancel'),
        'notSaved' => __('app.wagon_not_saved'),
    ]))"
    class="min-w-0"
    data-wagon-list-prototype
>
    @php
        $inputClass = 'mt-1 block min-h-11 w-full rounded-lg border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:focus:border-sky-400';
        $labelClass = 'text-xs font-semibold text-slate-600 dark:text-slate-300';
    @endphp

    <x-ui.page
        :title="__('app.wagon_list')"
        :eyebrow="__('app.operational_control')"
        :description="__('app.wagon_list_description')"
    >
        <x-slot:actions>
            <button
                type="button"
                @click="resetDraft()"
                class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-red-400/40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-red-500/40 dark:hover:bg-red-500/10 dark:hover:text-red-300"
            >
                <i class="far fa-undo" aria-hidden="true"></i>
                {{ __('app.reset_draft') }}
            </button>
        </x-slot:actions>

        <div class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 shadow-rt-xs sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-100" data-wagon-demo-notice>
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-200">
                    <i class="far fa-flask" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="font-semibold">{{ __('app.demo_preview') }}</p>
                    <p class="mt-0.5 max-w-3xl text-xs leading-5 text-amber-800 dark:text-amber-200/80">{{ __('app.wagon_demo_notice') }}</p>
                </div>
            </div>
            <p class="shrink-0 text-xs font-medium text-amber-800 dark:text-amber-200">
                {{ __('app.locally_saved') }}: <span x-text="formatSavedAt()"></span>
            </p>
        </div>

        <x-ui.accordion.tabs
            :tabs="[
                'wagons' => ['label' => __('app.wagon_list'), 'icon' => 'fad fa-train'],
                'brakeSheet' => ['label' => __('app.brake_sheet'), 'icon' => 'fad fa-clipboard-check'],
            ]"
            default="wagons"
            persist-key="operations.wagon-list.tabs"
        >
            <x-ui.accordion.tab-panel for="wagons" panel-class="space-y-5">
                <section class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" aria-labelledby="wagon-meta-title">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                            <i class="far fa-route" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 id="wagon-meta-title" class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.train_data') }}</h2>
                            <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.train_data_hint') }}</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
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

                <section class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6" aria-label="{{ __('app.wagon_totals') }}">
                    <template x-for="item in [
                        { label: @js(__('app.wagons')), value: totals.wagons, suffix: '' },
                        { label: @js(__('app.axles')), value: totals.axles, suffix: '' },
                        { label: @js(__('app.length_over_buffers')), value: formatNumber(totals.length), suffix: ' m' },
                        { label: @js(__('app.total_weight')), value: formatNumber(totals.totalWeight), suffix: ' t' },
                        { label: @js(__('app.brake_weight_g')), value: formatNumber(totals.brakeG), suffix: ' t' },
                        { label: @js(__('app.brake_weight_p')), value: formatNumber(totals.brakeP), suffix: ' t' },
                    ]" :key="item.label">
                        <div class="min-w-0 rounded-xl bg-rt-surface p-3 shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                            <p class="truncate text-[10px] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted" x-text="item.label"></p>
                            <p class="mt-1 text-lg font-bold tabular-nums text-rt-text dark:text-white"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </section>

                <section class="space-y-3" aria-labelledby="wagons-title">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="wagons-title" class="text-lg font-semibold tracking-tight text-rt-text dark:text-white">{{ __('app.wagons') }}</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.wagon_cards_hint') }}</p>
                        </div>
                        <button
                            type="button"
                            @click="addWagon()"
                            :disabled="visibleCount >= 40"
                            class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-sky-700 px-3.5 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-sky-800 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-sky-400/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-sky-600 dark:hover:bg-sky-500"
                        >
                            <i class="far fa-plus" aria-hidden="true"></i>
                            {{ __('app.add_wagon') }}
                        </button>
                    </div>

                    <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                        <article
                            class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 transition dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
                            :data-wagon-index="index"
                        >
                            <button
                                type="button"
                                @click="openWagon = openWagon === index ? null : index"
                                class="flex w-full items-center gap-3 p-4 text-left transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-sky-400/50 dark:hover:bg-slate-800/50"
                                :aria-expanded="openWagon === index"
                            >
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-sm font-bold tabular-nums text-slate-700 dark:bg-slate-800 dark:text-slate-200" x-text="index + 1"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-rt-text dark:text-white" x-text="wagonNumber(wagon) || @js(__('app.wagon_not_filled'))"></span>
                                    <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                        <span x-text="wagon.category || '—'"></span>
                                        <span aria-hidden="true"> · </span>
                                        <span x-text="formatNumber(totalWeight(wagon)) + ' t'"></span>
                                    </span>
                                </span>
                                <span
                                    x-show="checkState(wagon) !== 'incomplete'"
                                    class="hidden rounded-md px-2 py-1 text-[10px] font-semibold sm:inline-flex"
                                    :class="checkState(wagon) === 'valid' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'"
                                    x-text="checkState(wagon) === 'valid' ? @js(__('app.wagon_number_valid')) : @js(__('app.wagon_number_invalid'))"
                                ></span>
                                <i class="far fa-chevron-down shrink-0 text-slate-400 transition-transform" :class="openWagon === index && 'rotate-180'" aria-hidden="true"></i>
                            </button>

                            <div x-show="openWagon === index" x-collapse class="border-t border-rt-border/60 p-4 sm:p-5 dark:border-rt-dark-border/60">
                                <div class="grid gap-5 xl:grid-cols-2">
                                    <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                                        <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.identification') }}</legend>
                                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-5">
                                            <label class="{{ $labelClass }}">1+2<input x-model="wagon.number12" inputmode="numeric" maxlength="2" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">3+4<input x-model="wagon.number34" inputmode="numeric" maxlength="2" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">5–8<input x-model="wagon.number58" inputmode="numeric" maxlength="4" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">9–11<input x-model="wagon.number911" inputmode="numeric" maxlength="3" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">12<input x-model="wagon.checkDigit" inputmode="numeric" maxlength="1" class="{{ $inputClass }}"></label>
                                        </div>
                                        <p
                                            x-show="checkState(wagon) === 'invalid'"
                                            class="mt-2 text-xs font-medium text-red-600 dark:text-red-300"
                                        >{{ __('app.expected_check_digit') }}: <span x-text="expectedCheckDigit(wagon)"></span></p>
                                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                            <label class="{{ $labelClass }}">{{ __('app.category') }}<input x-model="wagon.category" type="text" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.brake_type') }}
                                                <select x-model="wagon.brakeType" class="{{ $inputClass }}">
                                                    <option value="">{{ __('app.please_select') }}</option>
                                                    <option value="K">K</option><option value="L">L</option><option value="LL">LL</option>
                                                </select>
                                            </label>
                                        </div>
                                    </fieldset>

                                    <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                                        <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.axles_dimensions') }}</legend>
                                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                                            <label class="{{ $labelClass }}">{{ __('app.axles_empty') }}<input x-model="wagon.axlesEmpty" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.axles_loaded') }}<input x-model="wagon.axlesLoaded" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.length_m') }}<input x-model="wagon.length" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                        </div>
                                    </fieldset>

                                    <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                                        <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.weights_and_brakes') }}</legend>
                                        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            <label class="{{ $labelClass }}">{{ __('app.wagon_weight_t') }}<input x-model="wagon.wagonWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.load_weight_t') }}<input x-model="wagon.loadWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                            <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('app.total_weight') }}</span>
                                                <strong class="mt-2 block text-lg tabular-nums text-slate-900 dark:text-white"><span x-text="formatNumber(totalWeight(wagon))"></span> t</strong>
                                            </div>
                                            <label class="{{ $labelClass }}">{{ __('app.brake_weight_g') }}<input x-model="wagon.brakeG" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.brake_weight_p') }}<input x-model="wagon.brakeP" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                            <label class="flex min-h-11 items-center gap-3 self-end rounded-lg bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700">
                                                <input x-model="wagon.discBrake" type="checkbox" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                                                {{ __('app.disc_brake') }}
                                            </label>
                                        </div>
                                    </fieldset>

                                    <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                                        <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.route_and_notes') }}</legend>
                                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                            <label class="{{ $labelClass }}">{{ __('app.shipping_station') }}<input x-model="wagon.shippingStation" type="text" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.destination_station') }}<input x-model="wagon.destinationStation" type="text" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.parking_brake_kn') }}<input x-model="wagon.parkingBrake" type="number" min="0" step="0.1" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }}">{{ __('app.maximum_speed') }}<input x-model="wagon.maxSpeed" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                                            <label class="{{ $labelClass }} sm:col-span-2">{{ __('app.remark') }}<textarea x-model="wagon.remark" rows="2" class="{{ $inputClass }}"></textarea></label>
                                        </div>
                                    </fieldset>
                                </div>

                                <div class="mt-4 flex justify-end">
                                    <button type="button" @click="clearWagon(index)" class="inline-flex items-center gap-2 text-xs font-semibold text-red-600 transition hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-400/40 dark:text-red-300 dark:hover:text-red-200">
                                        <i class="far fa-trash-alt" aria-hidden="true"></i>{{ __('app.clear_wagon') }}
                                    </button>
                                </div>
                            </div>
                        </article>
                    </template>
                </section>
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel for="brakeSheet" panel-class="space-y-5">
                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('app.brake_sheet_summary') }}">
                    <template x-for="item in [
                        { label: @js(__('app.total_weight')), value: formatNumber(brakeTotals.trainWeight), suffix: ' t' },
                        { label: @js(__('app.brake_weight')), value: formatNumber(brakeTotals.brakeWeight), suffix: ' t' },
                        { label: @js(__('app.axles')), value: brakeTotals.axles, suffix: '' },
                        { label: @js(__('app.available_brake_percentage')), value: brakeTotals.availablePercentage, suffix: ' %' },
                    ]" :key="item.label">
                        <div class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                            <p class="text-xs font-semibold text-rt-muted dark:text-rt-dark-muted" x-text="item.label"></p>
                            <p class="mt-2 text-2xl font-bold tabular-nums text-rt-text dark:text-white"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </section>

                <section class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <div class="flex flex-col gap-2 border-b border-rt-border/60 pb-4 sm:flex-row sm:items-end sm:justify-between dark:border-rt-dark-border/60">
                        <div>
                            <h2 class="text-xl font-semibold tracking-tight text-rt-text dark:text-white">{{ __('app.brake_sheet') }}</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted"><span x-text="meta.trainNumber || '—'"></span> · <span x-text="meta.origin || '—'"></span> → <span x-text="meta.destination || '—'"></span></p>
                        </div>
                        <p class="text-xs font-medium text-rt-muted dark:text-rt-dark-muted"><span x-text="meta.date"></span></p>
                    </div>

                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                        <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                            <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.traction_vehicle') }}</legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                                <label class="{{ $labelClass }}">{{ __('app.weight_t') }}<input x-model="brakeSheet.tractionWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                <label class="{{ $labelClass }}">{{ __('app.brake_weight_t') }}<input x-model="brakeSheet.tractionBrakeWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                <label class="{{ $labelClass }}">{{ __('app.axles') }}<input x-model="brakeSheet.tractionAxles" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                            </div>
                        </fieldset>

                        <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                            <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.brake_calculation') }}</legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                                <label class="{{ $labelClass }}">{{ __('app.minimum_brake_percentage') }}<input x-model="brakeSheet.minimumBrakePercentage" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                                <div class="rounded-lg bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"><span class="text-xs text-slate-500 dark:text-slate-400">{{ __('app.available') }}</span><strong class="mt-2 block text-lg tabular-nums dark:text-white"><span x-text="brakeTotals.availablePercentage"></span> %</strong></div>
                                <div class="rounded-lg bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"><span class="text-xs text-slate-500 dark:text-slate-400">{{ __('app.missing') }}</span><strong class="mt-2 block text-lg tabular-nums" :class="brakeTotals.missingPercentage > 0 ? 'text-red-600 dark:text-red-300' : 'text-emerald-600 dark:text-emerald-300'"><span x-text="brakeTotals.missingPercentage"></span> %</strong></div>
                            </div>
                        </fieldset>

                        <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                            <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.freight_train_data') }}</legend>
                            <dl class="mt-2 divide-y divide-slate-200 text-sm dark:divide-slate-700">
                                <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500 dark:text-slate-400">{{ __('app.last_vehicle_number') }}</dt><dd class="text-right font-semibold dark:text-white" x-text="brakeTotals.lastVehicle || '—'"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500 dark:text-slate-400">{{ __('app.brakes_count') }}</dt><dd class="font-semibold dark:text-white" x-text="totals.brakeCount"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500 dark:text-slate-400">{{ __('app.disc_brakes_count') }}</dt><dd class="font-semibold dark:text-white" x-text="totals.discBrakes"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500 dark:text-slate-400">{{ __('app.plastic_brakes_count') }}</dt><dd class="font-semibold dark:text-white" x-text="totals.plasticBrakes"></dd></div>
                                <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500 dark:text-slate-400">{{ __('app.length_over_buffers') }}</dt><dd class="font-semibold dark:text-white"><span x-text="formatNumber(totals.length)"></span> m</dd></div>
                            </dl>
                            <label class="mt-4 block {{ $labelClass }}">{{ __('app.braked_axles') }}<input x-model="brakeSheet.brakedAxles" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                        </fieldset>

                        <fieldset class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/45">
                            <legend class="px-1 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.special_information') }}</legend>
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
                                    <label class="{{ $labelClass }}">{{ $label }}
                                        <select x-model="brakeSheet.{{ $field }}" class="{{ $inputClass }}">
                                            <option value="">{{ __('app.please_select') }}</option>
                                            <option value="no">{{ __('app.no') }}</option>
                                            <option value="yes">{{ __('app.yes') }}</option>
                                        </select>
                                    </label>
                                @endforeach
                                <label class="{{ $labelClass }}">{{ __('app.lower_vehicle_speed') }}<input x-model="brakeSheet.lowerVehicleSpeed" type="number" min="0" step="1" class="{{ $inputClass }}"></label>
                                <label class="{{ $labelClass }} sm:col-span-2">{{ __('app.issued_by_name') }}<input x-model="brakeSheet.issuerName" type="text" class="{{ $inputClass }}"></label>
                            </div>
                        </fieldset>
                    </div>
                </section>
            </x-ui.accordion.tab-panel>
        </x-ui.accordion.tabs>
    </x-ui.page>
</div>
