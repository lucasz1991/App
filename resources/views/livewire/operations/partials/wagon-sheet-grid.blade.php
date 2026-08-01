@php
    $quickGridStyle = 'grid-template-columns: 3.5rem 4rem 4rem 5rem 5rem 3.5rem 7rem 5rem 5rem 5rem 6rem 6rem 6rem 6rem 6rem 6rem 6rem 5rem 6rem 9rem 9rem 12rem 3rem;';
    $quickHeaderClass = 'flex min-h-11 items-center border-b border-r border-rt-border px-2 text-[10px] font-bold uppercase tracking-[0.06em] text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted';
@endphp

<section class="rt-wagon-sheet-shell hidden overflow-hidden rounded-2xl shadow-rt-sm lg:block" aria-labelledby="wagon-sheet-title">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70">
        <div class="min-w-0">
            <h2 id="wagon-sheet-title" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.wagon_overview') }}</h2>
            <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted" x-text="desktopTableMode === 'quick' ? @js(__('app.quick_entry_hint')) : @js(__('app.wagon_cards_hint'))"></p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-xl border border-rt-border bg-rt-surface-muted p-1 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted" role="group" aria-label="{{ __('app.spreadsheet_view') }}">
                <button
                    type="button"
                    @click="desktopTableMode = 'overview'"
                    :aria-pressed="desktopTableMode === 'overview'"
                    :class="desktopTableMode === 'overview'
                        ? 'bg-rt-surface text-rt-accent shadow-rt-xs dark:bg-rt-dark-surface dark:text-rt-dark-accent'
                        : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text'"
                    class="inline-flex min-h-9 items-center gap-2 rounded-lg px-3 text-xs font-semibold transition"
                >
                    <i class="far fa-list" aria-hidden="true"></i>
                    {{ __('app.wagon_overview') }}
                </button>
                <button
                    type="button"
                    @click="desktopTableMode = 'quick'"
                    :aria-pressed="desktopTableMode === 'quick'"
                    :class="desktopTableMode === 'quick'
                        ? 'bg-rt-surface text-rt-accent shadow-rt-xs dark:bg-rt-dark-surface dark:text-rt-dark-accent'
                        : 'text-rt-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:text-rt-dark-text'"
                    class="inline-flex min-h-9 items-center gap-2 rounded-lg px-3 text-xs font-semibold transition"
                >
                    <i class="far fa-table" aria-hidden="true"></i>
                    {{ __('app.quick_entry') }}
                </button>
            </div>

            <button
                type="button"
                @click="addWagon(); desktopWagon = visibleCount - 1"
                :disabled="visibleCount >= 40"
                class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rt-accent px-3.5 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:brightness-95 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-accent/35 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-rt-dark-accent dark:text-slate-950"
            >
                <i class="far fa-plus" aria-hidden="true"></i>
                {{ __('app.add_wagon') }}
            </button>
        </div>
    </div>

    {{-- Standardansicht: Wagen links waehlen, Details rechts ohne breites Tabellen-Panning bearbeiten. --}}
    <div
        x-show.important="desktopTableMode === 'overview'"
        class="grid min-h-0 grid-cols-[minmax(19rem,0.72fr)_minmax(0,1.28fr)]"
        data-wagon-desktop-overview
    >
        <div class="max-h-[64dvh] min-h-[30rem] overflow-y-auto border-r border-rt-border/70 bg-rt-surface-muted/45 p-3 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/25">
            <div
                class="space-y-2"
                role="listbox"
                aria-label="{{ __('app.wagons') }}"
                :aria-activedescendant="`wagon-overview-option-${desktopWagon}`"
            >
                <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                    <button
                        type="button"
                        role="option"
                        :id="`wagon-overview-option-${index}`"
                        :aria-selected="desktopWagon === index"
                        x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (index + 1) + ': ' + (wagonNumber(wagon) || @js(__('app.wagon_empty')))"
                        @click="desktopWagon = index"
                        :class="desktopWagon === index
                            ? 'border-rt-accent bg-rt-surface shadow-rt-sm ring-1 ring-rt-accent/15 dark:border-rt-dark-accent dark:bg-rt-dark-surface'
                            : 'border-rt-border bg-rt-surface/75 hover:border-rt-accent/45 hover:bg-rt-surface dark:border-rt-dark-border dark:bg-rt-dark-surface/65 dark:hover:border-rt-dark-accent/45 dark:hover:bg-rt-dark-surface'"
                        class="group block w-full rounded-xl border p-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/40"
                    >
                        <span class="flex min-w-0 items-start gap-3">
                            <span
                                :class="desktopWagon === index
                                    ? 'bg-rt-accent text-white dark:bg-rt-dark-accent dark:text-slate-950'
                                    : 'bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted'"
                                class="flex h-9 min-w-9 shrink-0 items-center justify-center rounded-lg text-xs font-bold tabular-nums transition"
                                x-text="index + 1"
                            ></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex min-w-0 items-center justify-between gap-2">
                                    <strong class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="wagonNumber(wagon) || (@js(__('app.wagons')) + ' ' + (index + 1))"></strong>
                                    <span
                                        x-show="!isWagonFilled(wagon)"
                                        class="shrink-0 rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300"
                                    >{{ __('app.wagon_empty') }}</span>
                                    <span
                                        x-show="isWagonFilled(wagon) && checkState(wagon) === 'valid' && wagon.category && wagon.length && (wagon.wagonWeight || wagon.loadWeight)"
                                        class="shrink-0 rounded-md bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                                    >{{ __('app.wagon_complete') }}</span>
                                    <span
                                        x-show="isWagonFilled(wagon) && !(checkState(wagon) === 'valid' && wagon.category && wagon.length && (wagon.wagonWeight || wagon.loadWeight))"
                                        class="shrink-0 rounded-md bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"
                                    >{{ __('app.wagon_not_filled') }}</span>
                                </span>
                                <span class="mt-1 flex min-w-0 items-center gap-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                                    <span class="truncate" x-text="wagon.category || '—'"></span>
                                    <span aria-hidden="true">·</span>
                                    <span class="shrink-0 tabular-nums" x-text="formatNumber(totalWeight(wagon)) + ' t'"></span>
                                </span>
                                <span class="mt-1 block truncate text-[11px] text-rt-soft dark:text-rt-dark-soft" x-text="(wagon.shippingStation || '—') + ' → ' + (wagon.destinationStation || '—')"></span>
                            </span>
                            <i class="far fa-chevron-right mt-2 text-xs text-rt-soft transition group-hover:translate-x-0.5 group-hover:text-rt-accent dark:text-rt-dark-soft dark:group-hover:text-rt-dark-accent" aria-hidden="true"></i>
                        </span>
                    </button>
                </template>
            </div>
        </div>

        <div class="max-h-[64dvh] min-h-[30rem] overflow-y-auto bg-rt-surface p-4 dark:bg-rt-dark-surface" data-wagon-detail-inspector>
            <div class="flex items-start justify-between gap-4 border-b border-rt-border/70 pb-4 dark:border-rt-dark-border/70">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.08em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.edit_wagon_details') }}</p>
                    <h3 class="mt-1 truncate text-lg font-semibold text-rt-text dark:text-rt-dark-text">
                        {{ __('app.selected_wagon') }} <span class="tabular-nums" x-text="desktopWagon + 1"></span>
                    </h3>
                    <p class="mt-1 truncate text-xs text-rt-muted dark:text-rt-dark-muted" x-text="wagonNumber(wagons[desktopWagon]) || @js(__('app.wagon_empty'))"></p>
                </div>
                <button
                    type="button"
                    @click="clearWagon(desktopWagon)"
                    x-bind:aria-label="@js(__('app.clear_wagon')) + ': ' + @js(__('app.selected_wagon')) + ' ' + (desktopWagon + 1)"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-red-500 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400/45 dark:text-red-300 dark:hover:bg-red-500/10"
                    title="{{ __('app.clear_wagon') }}"
                >
                    <i class="far fa-trash-alt" aria-hidden="true"></i>
                </button>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                    <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.identification') }}</legend>
                    <div class="mt-2 grid grid-cols-[1fr_1fr_1.25fr_1.25fr_0.8fr] gap-2">
                        <label class="{{ $labelClass }}">1+2
                            <input x-model="wagons[desktopWagon].number12" inputmode="numeric" maxlength="2" class="{{ $inputClass }}" x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (desktopWagon + 1) + ', 1+2'">
                        </label>
                        <label class="{{ $labelClass }}">3+4
                            <input x-model="wagons[desktopWagon].number34" inputmode="numeric" maxlength="2" class="{{ $inputClass }}" x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (desktopWagon + 1) + ', 3+4'">
                        </label>
                        <label class="{{ $labelClass }}">5–8
                            <input x-model="wagons[desktopWagon].number58" inputmode="numeric" maxlength="4" class="{{ $inputClass }}" x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (desktopWagon + 1) + ', 5–8'">
                        </label>
                        <label class="{{ $labelClass }}">9–11
                            <input x-model="wagons[desktopWagon].number911" inputmode="numeric" maxlength="3" class="{{ $inputClass }}" x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (desktopWagon + 1) + ', 9–11'">
                        </label>
                        <label class="{{ $labelClass }}">12
                            <input
                                x-model="wagons[desktopWagon].checkDigit"
                                inputmode="numeric"
                                maxlength="1"
                                :class="checkState(wagons[desktopWagon]) === 'invalid' && 'border-red-400 text-red-600 ring-2 ring-red-400/20 dark:text-red-300'"
                                class="{{ $inputClass }}"
                                x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (desktopWagon + 1) + ', 12'"
                            >
                        </label>
                    </div>
                    <p x-show="checkState(wagons[desktopWagon]) === 'invalid'" class="mt-2 text-xs font-semibold text-red-600 dark:text-red-300">
                        {{ __('app.expected_check_digit') }}: <span x-text="expectedCheckDigit(wagons[desktopWagon])"></span>
                    </p>
                    <label class="mt-3 block {{ $labelClass }}">{{ __('app.category') }}
                        <input x-model="wagons[desktopWagon].category" class="{{ $inputClass }}">
                    </label>
                </fieldset>

                <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                    <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.axles_dimensions') }}</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.axles_empty') }}
                            <x-ui.forms.number-input min="0" x-model="wagons[desktopWagon].axlesEmpty" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.axles_loaded') }}
                            <x-ui.forms.number-input min="0" x-model="wagons[desktopWagon].axlesLoaded" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.length_m') }}
                            <x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[desktopWagon].length" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.maximum_speed') }}
                            <x-ui.forms.number-input min="0" x-model="wagons[desktopWagon].maxSpeed" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.wagon_weight_t') }}
                            <x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[desktopWagon].wagonWeight" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.load_weight_t') }}
                            <x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[desktopWagon].loadWeight" class="mt-1" />
                        </label>
                        <div class="rt-wagon-calculated col-span-2 rounded-lg p-3">
                            <span class="text-[10px] font-bold uppercase tracking-[0.08em] opacity-65">{{ __('app.total_weight') }}</span>
                            <strong class="mt-1 block text-lg tabular-nums"><span x-text="formatNumber(totalWeight(wagons[desktopWagon]))"></span> t</strong>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                    <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.brakes') }}</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.brake_weight_g') }}
                            <x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[desktopWagon].brakeG" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.brake_weight_p') }}
                            <x-ui.forms.number-input min="0" step="0.01" :decimals="2" x-model="wagons[desktopWagon].brakeP" class="mt-1" />
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.parking_brake_kn') }}
                            <x-ui.forms.number-input min="0" step="0.1" :decimals="1" x-model="wagons[desktopWagon].parkingBrake" class="mt-1" />
                        </label>
                        <label class="flex min-h-11 items-center gap-2 self-end rounded-lg px-3 py-2 text-xs font-semibold text-rt-text dark:text-rt-dark-text">
                            <input x-model="wagons[desktopWagon].discBrake" type="checkbox" class="h-5 w-5 rounded border-rt-border text-rt-accent focus:ring-rt-accent/35 dark:border-rt-dark-border">
                            {{ __('app.disc_brake') }}
                        </label>
                        <div class="col-span-2">
                            <span class="{{ $labelClass }}">{{ __('app.brake_type') }}</span>
                            <div class="mt-1 grid grid-cols-4 rounded-xl border border-rt-border bg-rt-control p-1 dark:border-rt-dark-border dark:bg-rt-dark-control">
                                @foreach (['' => '—', 'K' => 'K', 'L' => 'L', 'LL' => 'LL'] as $value => $optionLabel)
                                    <button
                                        type="button"
                                        @click="wagons[desktopWagon].brakeType = @js($value)"
                                        :aria-pressed="wagons[desktopWagon].brakeType === @js($value)"
                                        :data-active="wagons[desktopWagon].brakeType === @js($value) ? 'true' : 'false'"
                                        class="rt-wagon-choice min-h-9 rounded-lg px-2 text-xs font-semibold transition"
                                    >{{ $optionLabel }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="rt-wagon-fieldset rounded-xl p-4">
                    <legend class="px-1 text-xs font-bold uppercase tracking-[0.08em]">{{ __('app.route_and_notes') }}</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <label class="{{ $labelClass }}">{{ __('app.shipping_station') }}
                            <input x-model="wagons[desktopWagon].shippingStation" class="{{ $inputClass }}">
                        </label>
                        <label class="{{ $labelClass }}">{{ __('app.destination_station') }}
                            <input x-model="wagons[desktopWagon].destinationStation" class="{{ $inputClass }}">
                        </label>
                        <label class="{{ $labelClass }} col-span-2">{{ __('app.remark') }}
                            <textarea x-model="wagons[desktopWagon].remark" rows="3" class="{{ $inputClass }}"></textarea>
                        </label>
                    </div>
                </fieldset>
            </div>
        </div>
    </div>

    {{-- Schnellmodus: volle Excel-nahe Eingabe, aber mit klaren Spaltengruppen. --}}
    <div x-show="desktopTableMode === 'quick'" x-cloak data-wagon-desktop-quick>
        <div class="rt-table-scroll max-h-[64dvh]" data-wagon-sheet role="table" aria-label="{{ __('app.quick_entry') }}">
            <div class="sticky top-0 z-20" style="min-width: 133rem;">
                <div class="rt-wagon-sheet-grid rt-wagon-sheet-grid-header" role="row" style="{{ $quickGridStyle }}">
                    <div role="columnheader" aria-colspan="7" style="grid-column: 1 / span 7;" class="{{ $quickHeaderClass }} min-h-9 bg-slate-100/80 dark:bg-slate-800/80">{{ __('app.identification') }}</div>
                    <div role="columnheader" aria-colspan="7" style="grid-column: 8 / span 7;" class="{{ $quickHeaderClass }} min-h-9 bg-slate-100/80 dark:bg-slate-800/80">{{ __('app.wagons') }}</div>
                    <div role="columnheader" aria-colspan="5" style="grid-column: 15 / span 5;" class="{{ $quickHeaderClass }} min-h-9 bg-slate-100/80 dark:bg-slate-800/80">{{ __('app.brakes') }}</div>
                    <div role="columnheader" aria-colspan="3" style="grid-column: 20 / span 3;" class="{{ $quickHeaderClass }} min-h-9 bg-slate-100/80 dark:bg-slate-800/80">{{ __('app.route') }}</div>
                    <div role="columnheader" style="grid-column: 23;" class="{{ $quickHeaderClass }} min-h-9 bg-slate-100/80 dark:bg-slate-800/80" aria-label="{{ __('app.clear_wagon') }}"></div>
                </div>
                <div class="rt-wagon-sheet-grid rt-wagon-sheet-grid-header" role="row" style="{{ $quickGridStyle }}">
                    @foreach ([
                        __('app.row'), '1+2', '3+4', '5–8', '9–11', '12', __('app.category'),
                        __('app.axles_empty'), __('app.axles_loaded'), __('app.length_m'),
                        __('app.wagon_weight_t'), __('app.load_weight_t'), __('app.total_weight'), __('app.maximum_speed'),
                        __('app.brake_weight_g'), __('app.brake_weight_p'), __('app.brake_type'), __('app.disc_brake'), __('app.parking_brake_kn'),
                        __('app.shipping_station'), __('app.destination_station'), __('app.remark'), '',
                    ] as $column)
                        <div role="columnheader" class="{{ $quickHeaderClass }}">
                            {{ $column }}
                        </div>
                    @endforeach
                </div>
            </div>

            <div role="rowgroup" style="min-width: 133rem;">
                <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                    <div class="rt-wagon-sheet-grid rt-wagon-sheet-grid-row" role="row" style="{{ $quickGridStyle }}">
                        <div role="rowheader" class="rt-wagon-sheet-index sticky left-0 z-10 flex items-center justify-center border-b border-r border-rt-border dark:border-rt-dark-border">
                            <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md px-1 text-xs font-bold tabular-nums" x-text="index + 1"></span>
                        </div>

                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number12" inputmode="numeric" maxlength="2" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', 1+2'" class="{{ $sheetInput }}"></div>
                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number34" inputmode="numeric" maxlength="2" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', 3+4'" class="{{ $sheetInput }}"></div>
                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number58" inputmode="numeric" maxlength="4" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', 5–8'" class="{{ $sheetInput }}"></div>
                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number911" inputmode="numeric" maxlength="3" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', 9–11'" class="{{ $sheetInput }}"></div>
                        <div role="cell">
                            <input
                                data-wagon-cell
                                @keydown.enter.prevent="focusNextCell($event)"
                                x-model="wagon.checkDigit"
                                inputmode="numeric"
                                maxlength="1"
                                x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', 12'"
                                :class="checkState(wagon) === 'invalid' && 'text-red-600 bg-red-50 dark:text-red-300 dark:bg-red-500/10'"
                                class="{{ $sheetInput }}"
                            >
                        </div>
                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.category" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.category'))" class="{{ $sheetInput }}"></div>

                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.axlesEmpty" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.axles_empty'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.axlesLoaded" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.axles_loaded'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" step="0.01" :decimals="2" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.length" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.length_m'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" step="0.01" :decimals="2" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.wagonWeight" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.wagon_weight_t'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" step="0.01" :decimals="2" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.loadWeight" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.load_weight_t'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell" class="flex items-center px-2 text-sm font-bold tabular-nums" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.total_weight'))"><span x-text="formatNumber(totalWeight(wagon))"></span>&nbsp;t</div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.maxSpeed" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.maximum_speed'))" class="{{ $sheetInput }}" /></div>

                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" step="0.01" :decimals="2" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.brakeG" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.brake_weight_g'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" step="0.01" :decimals="2" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.brakeP" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.brake_weight_p'))" class="{{ $sheetInput }}" /></div>
                        <div role="cell" class="p-1">
                            <button
                                type="button"
                                data-wagon-cell
                                @click="cycleBrakeType(wagon)"
                                @keydown.enter.prevent="cycleBrakeType(wagon); focusNextCell($event)"
                                x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.brake_type'))"
                                class="h-10 w-full rounded-md px-2 text-left text-sm font-semibold outline-none transition hover:bg-sky-50 focus:ring-2 focus:ring-inset focus:ring-sky-400/55 dark:hover:bg-sky-500/10"
                                :title="@js(__('app.brake_type_cycle_hint'))"
                            >
                                <span x-text="wagon.brakeType || '—'"></span>
                            </button>
                        </div>
                        <div role="cell" class="flex items-center justify-center">
                            <input
                                data-wagon-cell
                                @keydown.enter.prevent="focusNextCell($event)"
                                x-model="wagon.discBrake"
                                type="checkbox"
                                x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.disc_brake'))"
                                class="h-5 w-5 rounded border-rt-border text-rt-accent focus:ring-rt-accent/35 dark:border-rt-dark-border"
                            >
                        </div>
                        <div role="cell"><x-ui.forms.number-input :stepper="false" min="0" step="0.1" :decimals="1" data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.parkingBrake" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.parking_brake_kn'))" class="{{ $sheetInput }}" /></div>

                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.shippingStation" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.shipping_station'))" class="{{ $sheetInput }}"></div>
                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.destinationStation" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.destination_station'))" class="{{ $sheetInput }}"></div>
                        <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.remark" x-bind:aria-label="@js(__('app.row')) + ' ' + (index + 1) + ', ' + @js(__('app.remark'))" class="{{ $sheetInput }}"></div>
                        <div role="cell" class="flex items-center justify-center">
                            <button
                                type="button"
                                @click="clearWagon(index)"
                                x-bind:aria-label="@js(__('app.clear_wagon')) + ': ' + @js(__('app.row')) + ' ' + (index + 1)"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-500 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400/45 dark:text-red-300 dark:hover:bg-red-500/10"
                                title="{{ __('app.clear_wagon') }}"
                            >
                                <i class="far fa-trash-alt" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</section>
