<div
    x-data="wagonListPrototype(@js([
        'storageKey' => $storageKey,
        'locale' => app()->getLocale() === 'de' ? 'de-DE' : 'en-GB',
        'resetTitle' => __('app.wagon_reset_title'),
        'resetText' => __('app.wagon_reset_text'),
        'resetConfirm' => __('app.reset'),
        'cancel' => __('app.cancel'),
        'notSaved' => __('app.wagon_not_saved'),
        'exportUrl' => auth()->user()->usesAdminLayout()
            ? route('admin.operations.wagon-list.export')
            : route('operations.wagon-list.export'),
        'exportSuccess' => __('app.wagon_export_success'),
        'exportError' => __('app.wagon_export_error'),
    ]))"
    class="min-w-0"
    data-wagon-list-prototype
>
    @php
        $inputClass = 'rt-ui-control rt-wagon-input mt-1 block min-h-11 w-full rounded-lg border border-rt-border bg-rt-control px-3 py-2 text-base text-rt-text shadow-rt-xs outline-none transition focus:border-rt-accent focus:ring-2 focus:ring-rt-accent/20 sm:text-sm';
        $labelClass = 'text-xs font-semibold text-rt-muted dark:text-rt-dark-muted';
        $sheetInput = 'rt-wagon-sheet-input h-10 w-full min-w-0 border-0 bg-transparent px-2 text-sm tabular-nums text-rt-text outline-none focus:bg-sky-50 focus:ring-2 focus:ring-inset focus:ring-sky-400/55 dark:text-rt-dark-text dark:focus:bg-sky-500/10';
    @endphp

    <x-ui.page
        :title="__('app.wagon_list')"
        :eyebrow="__('app.operational_control')"
        :description="__('app.wagon_list_description')"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    @click="exportWorkbook()"
                    :disabled="exporting"
                    class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rt-red px-3.5 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark hover:shadow-rt-glow active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/35 disabled:cursor-wait disabled:opacity-65"
                >
                    <i class="far fa-file-excel" x-show="!exporting" aria-hidden="true"></i>
                    <i class="far fa-spinner fa-spin" x-show="exporting" x-cloak aria-hidden="true"></i>
                    <span x-text="exporting ? @js(__('app.wagon_exporting')) : @js(__('app.export_excel'))"></span>
                </button>
                <button
                    type="button"
                    @click="resetDraft()"
                    class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-rt-border bg-rt-surface px-3 py-2 text-sm font-semibold text-rt-muted shadow-rt-xs transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-red-400/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:border-red-500/40 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                >
                    <i class="far fa-undo" aria-hidden="true"></i>
                    {{ __('app.reset_draft') }}
                </button>
            </div>
        </x-slot:actions>

        <div class="rt-wagon-notice flex flex-col gap-3 rounded-xl border p-3 text-sm shadow-rt-xs sm:flex-row sm:items-center sm:justify-between" data-wagon-demo-notice>
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                    <i class="far fa-flask" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="font-semibold">{{ __('app.demo_preview') }}</p>
                    <p class="mt-0.5 max-w-3xl text-xs leading-5 opacity-80">{{ __('app.wagon_demo_notice') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2 text-xs font-semibold">
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                {{ __('app.locally_saved') }}:
                <span x-text="formatSavedAt()"></span>
            </div>
        </div>

        <x-ui.accordion.tabs
            :tabs="[
                'wagons' => ['label' => __('app.wagon_list'), 'icon' => 'fad fa-train'],
                'brakeSheet' => ['label' => __('app.brake_sheet'), 'icon' => 'fad fa-clipboard-check'],
            ]"
            default="wagons"
            persist-key="operations.wagon-list.tabs"
        >
            <x-ui.accordion.tab-panel for="wagons" panel-class="space-y-4">
                <section class="rt-wagon-workspace rounded-2xl p-4 shadow-rt-sm sm:p-5" aria-labelledby="wagon-meta-title">
                    <div class="flex flex-col gap-3 border-b border-rt-border/70 pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-rt-dark-border/70">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="far fa-route" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h2 id="wagon-meta-title" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.train_data') }}</h2>
                                <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.train_data_hint') }}</p>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2 self-start rounded-lg bg-rt-surface-muted px-3 py-2 text-xs font-semibold text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                            <i class="far fa-keyboard" aria-hidden="true"></i>
                            {{ __('app.enter_advances_cell') }}
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
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
                        <label class="{{ $labelClass }} col-span-2 lg:col-span-1">{{ __('app.reference') }}
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
                        <div class="rt-wagon-total min-w-0 rounded-xl p-3">
                            <p class="truncate text-[10px] font-bold uppercase tracking-[0.08em] opacity-65" x-text="item.label"></p>
                            <p class="mt-1 text-lg font-bold tabular-nums"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </section>

                {{-- Desktop: vertraute Excel-Zeilen mit fester Spaltenreihenfolge. --}}
                @include('livewire.operations.partials.wagon-sheet-grid', ['sheetInput' => $sheetInput])

                {{-- Mobile/Tablet: immer nur ein Wagen, gleiche Reihenfolge wie die Excel-Zeile. --}}
                <section class="space-y-3 lg:hidden" data-mobile-wagon-editor>
                    <div class="rt-wagon-mobile-nav rounded-xl p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold">{{ __('app.wagons') }}</h2>
                                <p class="mt-0.5 text-xs opacity-70">
                                    <span x-text="completionCount"></span>/<span x-text="visibleCount"></span>
                                    {{ __('app.wagons_filled') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="addWagon()"
                                :disabled="visibleCount >= 40"
                                class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rt-accent px-3 py-2 text-xs font-semibold text-white transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-rt-dark-accent dark:text-slate-950"
                            >
                                <i class="far fa-plus" aria-hidden="true"></i>
                                {{ __('app.add_wagon') }}
                            </button>
                        </div>

                        <div class="rt-wagon-index-strip mt-3 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.wagons') }}">
                            <template x-for="(_, index) in wagons.slice(0, visibleCount)" :key="index">
                                <button
                                    type="button"
                                    @click="showMobileWagon(index)"
                                    :data-active="mobileWagon === index ? 'true' : 'false'"
                                    class="rt-wagon-index-button flex h-10 min-w-10 shrink-0 items-center justify-center rounded-lg text-xs font-bold tabular-nums transition"
                                >
                                    <span x-text="index + 1"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <article
                        class="rt-wagon-mobile-card overflow-hidden rounded-2xl shadow-rt-sm"
                        @touchstart.passive="wagonTouchStart($event)"
                        @touchend.passive="wagonTouchEnd($event)"
                        @touchcancel.passive="cancelWagonSwipe()"
                        data-wagon-swipe
                    >
                        <header class="flex items-center gap-3 border-b border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-sm font-bold text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent" x-text="mobileWagon + 1"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold" x-text="wagonNumber(wagons[mobileWagon]) || @js(__('app.wagon_not_filled'))"></p>
                                <p class="mt-0.5 truncate text-xs opacity-65">
                                    <span x-text="wagons[mobileWagon].category || '—'"></span>
                                    · <span x-text="formatNumber(totalWeight(wagons[mobileWagon])) + ' t'"></span>
                                </p>
                            </div>
                            <button type="button" @click="clearWagon(mobileWagon)" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-red-500 transition hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10" title="{{ __('app.clear_wagon') }}">
                                <i class="far fa-trash-alt" aria-hidden="true"></i>
                            </button>
                        </header>

                        <div class="space-y-4 p-4">
                            <fieldset class="rt-wagon-fieldset rounded-xl p-3">
                                <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.identification') }}</legend>
                                <div class="mt-2 grid grid-cols-[1fr_1fr_1.25fr_1.25fr_0.8fr] gap-1.5">
                                    <label class="{{ $labelClass }}">1+2<input x-model="wagons[mobileWagon].number12" inputmode="numeric" maxlength="2" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">3+4<input x-model="wagons[mobileWagon].number34" inputmode="numeric" maxlength="2" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">5–8<input x-model="wagons[mobileWagon].number58" inputmode="numeric" maxlength="4" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">9–11<input x-model="wagons[mobileWagon].number911" inputmode="numeric" maxlength="3" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">12<input x-model="wagons[mobileWagon].checkDigit" inputmode="numeric" maxlength="1" class="{{ $inputClass }}"></label>
                                </div>
                                <p x-show="checkState(wagons[mobileWagon]) === 'invalid'" class="mt-2 text-xs font-semibold text-red-600 dark:text-red-300">
                                    {{ __('app.expected_check_digit') }}:
                                    <span x-text="expectedCheckDigit(wagons[mobileWagon])"></span>
                                </p>
                                <div class="mt-3 grid grid-cols-2 gap-3">
                                    <label class="{{ $labelClass }}">{{ __('app.category') }}<input x-model="wagons[mobileWagon].category" class="{{ $inputClass }}"></label>
                                    <div>
                                        <span class="{{ $labelClass }}">{{ __('app.brake_type') }}</span>
                                        <div class="mt-1 grid grid-cols-4 rounded-xl border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control">
                                            @foreach (['' => '—', 'K' => 'K', 'L' => 'L', 'LL' => 'LL'] as $value => $optionLabel)
                                                <button
                                                    type="button"
                                                    @click="wagons[mobileWagon].brakeType = @js($value)"
                                                    :data-active="wagons[mobileWagon].brakeType === @js($value) ? 'true' : 'false'"
                                                    class="rt-wagon-choice min-h-9 rounded-lg px-2 text-xs font-semibold transition"
                                                >{{ $optionLabel }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset class="rt-wagon-fieldset rounded-xl p-3">
                                <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.axles_dimensions') }}</legend>
                                <div class="mt-2 grid grid-cols-2 gap-3">
                                    <label class="{{ $labelClass }}">{{ __('app.axles_empty') }}<input x-model="wagons[mobileWagon].axlesEmpty" type="number" min="0" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">{{ __('app.axles_loaded') }}<input x-model="wagons[mobileWagon].axlesLoaded" type="number" min="0" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }} col-span-2">{{ __('app.length_m') }}<input x-model="wagons[mobileWagon].length" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                </div>
                            </fieldset>

                            <fieldset class="rt-wagon-fieldset rounded-xl p-3">
                                <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.weights_and_brakes') }}</legend>
                                <div class="mt-2 grid grid-cols-2 gap-3">
                                    <label class="{{ $labelClass }}">{{ __('app.wagon_weight_t') }}<input x-model="wagons[mobileWagon].wagonWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">{{ __('app.load_weight_t') }}<input x-model="wagons[mobileWagon].loadWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                    <div class="rt-wagon-calculated rounded-lg p-3">
                                        <span class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-65">{{ __('app.total_weight') }}</span>
                                        <strong class="mt-1 block text-lg tabular-nums"><span x-text="formatNumber(totalWeight(wagons[mobileWagon]))"></span> t</strong>
                                    </div>
                                    <label class="flex min-h-11 items-center gap-2 self-end rounded-lg px-3 py-2 text-xs font-semibold">
                                        <input x-model="wagons[mobileWagon].discBrake" type="checkbox" class="h-5 w-5 rounded border-rt-border text-rt-accent focus:ring-rt-accent/35 dark:border-rt-dark-border">
                                        {{ __('app.disc_brake') }}
                                    </label>
                                    <label class="{{ $labelClass }}">{{ __('app.brake_weight_g') }}<input x-model="wagons[mobileWagon].brakeG" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">{{ __('app.brake_weight_p') }}<input x-model="wagons[mobileWagon].brakeP" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                </div>
                            </fieldset>

                            <fieldset class="rt-wagon-fieldset rounded-xl p-3">
                                <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.route_and_notes') }}</legend>
                                <div class="mt-2 grid grid-cols-2 gap-3">
                                    <label class="{{ $labelClass }}">{{ __('app.shipping_station') }}<input x-model="wagons[mobileWagon].shippingStation" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">{{ __('app.destination_station') }}<input x-model="wagons[mobileWagon].destinationStation" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">{{ __('app.parking_brake_kn') }}<input x-model="wagons[mobileWagon].parkingBrake" type="number" min="0" step="0.1" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }}">{{ __('app.maximum_speed') }}<input x-model="wagons[mobileWagon].maxSpeed" type="number" min="0" class="{{ $inputClass }}"></label>
                                    <label class="{{ $labelClass }} col-span-2">{{ __('app.remark') }}<textarea x-model="wagons[mobileWagon].remark" rows="2" class="{{ $inputClass }}"></textarea></label>
                                </div>
                            </fieldset>
                        </div>

                        <footer class="rt-wagon-mobile-footer sticky bottom-0 grid grid-cols-2 gap-2 border-t border-rt-border/70 p-3 dark:border-rt-dark-border/70">
                            <button type="button" @click="previousMobileWagon()" :disabled="mobileWagon === 0" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-rt-border px-3 text-sm font-semibold transition disabled:opacity-35 dark:border-rt-dark-border">
                                <i class="far fa-arrow-left" aria-hidden="true"></i>
                                {{ __('app.previous') }}
                            </button>
                            <button type="button" @click="nextMobileWagon()" :disabled="mobileWagon >= visibleCount - 1" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-rt-accent px-3 text-sm font-semibold text-white transition disabled:opacity-35 dark:bg-rt-dark-accent dark:text-slate-950">
                                {{ __('app.next') }}
                                <i class="far fa-arrow-right" aria-hidden="true"></i>
                            </button>
                        </footer>
                    </article>
                </section>
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel for="brakeSheet" panel-class="space-y-4">
                <section class="grid grid-cols-2 gap-2 xl:grid-cols-4" aria-label="{{ __('app.brake_sheet_summary') }}">
                    <template x-for="item in [
                        { label: @js(__('app.total_weight')), value: formatNumber(brakeTotals.trainWeight), suffix: ' t' },
                        { label: @js(__('app.brake_weight')), value: formatNumber(brakeTotals.brakeWeight), suffix: ' t' },
                        { label: @js(__('app.axles')), value: brakeTotals.axles, suffix: '' },
                        { label: @js(__('app.available_brake_percentage')), value: brakeTotals.availablePercentage, suffix: ' %' },
                    ]" :key="item.label">
                        <div class="rt-wagon-total rounded-xl p-3 sm:p-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-65" x-text="item.label"></p>
                            <p class="mt-1 text-xl font-bold tabular-nums sm:text-2xl"><span x-text="item.value"></span><span x-text="item.suffix"></span></p>
                        </div>
                    </template>
                </section>

                <section class="rt-wagon-workspace rounded-2xl p-4 shadow-rt-sm sm:p-5">
                    <div class="flex flex-col gap-2 border-b border-rt-border/70 pb-4 sm:flex-row sm:items-end sm:justify-between dark:border-rt-dark-border/70">
                        <div>
                            <h2 class="text-xl font-semibold tracking-tight">{{ __('app.brake_sheet') }}</h2>
                            <p class="mt-1 text-sm opacity-65"><span x-text="meta.trainNumber || '—'"></span> · <span x-text="meta.origin || '—'"></span> → <span x-text="meta.destination || '—'"></span></p>
                        </div>
                        <p class="text-xs font-semibold opacity-65"><span x-text="meta.date"></span></p>
                    </div>

                    <div class="mt-4 grid gap-4 xl:grid-cols-2">
                        <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                            <legend class="px-1 text-sm font-semibold">{{ __('app.traction_vehicle') }}</legend>
                            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                                <label class="{{ $labelClass }}">{{ __('app.weight_t') }}<input x-model="brakeSheet.tractionWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                <label class="{{ $labelClass }}">{{ __('app.brake_weight_t') }}<input x-model="brakeSheet.tractionBrakeWeight" type="number" min="0" step="0.01" class="{{ $inputClass }}"></label>
                                <label class="{{ $labelClass }}">{{ __('app.axles') }}<input x-model="brakeSheet.tractionAxles" type="number" min="0" class="{{ $inputClass }}"></label>
                            </div>
                        </fieldset>

                        <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                            <legend class="px-1 text-sm font-semibold">{{ __('app.brake_calculation') }}</legend>
                            <div class="mt-2 grid grid-cols-3 gap-3">
                                <label class="{{ $labelClass }}">{{ __('app.minimum_brake_percentage') }}<input x-model="brakeSheet.minimumBrakePercentage" type="number" min="0" class="{{ $inputClass }}"></label>
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
                            <label class="mt-3 block {{ $labelClass }}">{{ __('app.braked_axles') }}<input x-model="brakeSheet.brakedAxles" type="number" min="0" class="{{ $inputClass }}"></label>
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
                                                <button
                                                    type="button"
                                                    @click="brakeSheet.{{ $field }} = @js($value)"
                                                    :data-active="brakeSheet.{{ $field }} === @js($value) ? 'true' : 'false'"
                                                    class="rt-wagon-choice min-h-9 rounded-lg px-2 text-xs font-semibold transition"
                                                >{{ $optionLabel }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                                <label class="{{ $labelClass }}">{{ __('app.lower_vehicle_speed') }}<input x-model="brakeSheet.lowerVehicleSpeed" type="number" min="0" class="{{ $inputClass }}"></label>
                                <label class="{{ $labelClass }} sm:col-span-2">{{ __('app.issued_by_name') }}<input x-model="brakeSheet.issuerName" class="{{ $inputClass }}"></label>
                            </div>
                        </fieldset>
                    </div>
                </section>
            </x-ui.accordion.tab-panel>
        </x-ui.accordion.tabs>
    </x-ui.page>
</div>
