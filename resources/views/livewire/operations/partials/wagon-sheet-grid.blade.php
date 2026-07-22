<section class="rt-wagon-sheet-shell hidden overflow-hidden rounded-2xl shadow-rt-sm lg:block" aria-labelledby="wagon-sheet-title">
    <div class="flex items-center justify-between gap-4 border-b border-rt-border/70 px-4 py-3 dark:border-rt-dark-border/70">
        <div>
            <h2 id="wagon-sheet-title" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.spreadsheet_view') }}</h2>
            <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.excel_workflow_hint') }}</p>
        </div>
        <button
            type="button"
            @click="addWagon()"
            :disabled="visibleCount >= 40"
            class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rt-accent px-3.5 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:brightness-95 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-accent/35 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-rt-dark-accent dark:text-slate-950"
        >
            <i class="far fa-plus" aria-hidden="true"></i>
            {{ __('app.add_wagon') }}
        </button>
    </div>

    <div class="rt-table-scroll max-h-[64dvh]" data-wagon-sheet role="table" aria-label="{{ __('app.wagon_list') }}">
        <div class="rt-wagon-sheet-grid rt-wagon-sheet-grid-header sticky top-0 z-20 min-w-[156rem]" role="row">
            @foreach ([
                __('app.row'), '1+2', '3+4', '5–8', '9–11', '12', __('app.category'),
                __('app.axles_empty'), __('app.axles_loaded'), __('app.length_m'),
                __('app.wagon_weight_t'), __('app.load_weight_t'), __('app.total_weight'),
                __('app.brake_weight_g'), __('app.brake_weight_p'), __('app.shipping_station'),
                __('app.destination_station'), __('app.brake_type'), __('app.disc_brake'),
                __('app.parking_brake_kn'), __('app.maximum_speed'), __('app.remark'), '',
            ] as $column)
                <div role="columnheader" class="flex min-h-12 items-center border-b border-r border-rt-border px-2 text-[10px] font-bold uppercase tracking-[0.06em] text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">
                    {{ $column }}
                </div>
            @endforeach
        </div>

        <div class="min-w-[156rem]" role="rowgroup">
            <template x-for="(wagon, index) in wagons.slice(0, visibleCount)" :key="index">
                <div class="rt-wagon-sheet-grid rt-wagon-sheet-grid-row" role="row">
                    <div role="rowheader" class="rt-wagon-sheet-index sticky left-0 z-10 flex items-center justify-center border-b border-r border-rt-border dark:border-rt-dark-border">
                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md px-1 text-xs font-bold tabular-nums" x-text="index + 1"></span>
                    </div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number12" inputmode="numeric" maxlength="2" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number34" inputmode="numeric" maxlength="2" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number58" inputmode="numeric" maxlength="4" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.number911" inputmode="numeric" maxlength="3" class="{{ $sheetInput }}"></div>
                    <div role="cell">
                        <input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.checkDigit" inputmode="numeric" maxlength="1" :class="checkState(wagon) === 'invalid' && 'text-red-600 bg-red-50 dark:text-red-300 dark:bg-red-500/10'" class="{{ $sheetInput }}">
                    </div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.category" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.axlesEmpty" type="number" min="0" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.axlesLoaded" type="number" min="0" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.length" type="number" min="0" step="0.01" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.wagonWeight" type="number" min="0" step="0.01" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.loadWeight" type="number" min="0" step="0.01" class="{{ $sheetInput }}"></div>
                    <div role="cell" class="flex items-center px-2 text-sm font-bold tabular-nums"><span x-text="formatNumber(totalWeight(wagon))"></span>&nbsp;t</div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.brakeG" type="number" min="0" step="0.01" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.brakeP" type="number" min="0" step="0.01" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.shippingStation" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.destinationStation" class="{{ $sheetInput }}"></div>
                    <div role="cell" class="p-1">
                        <button type="button" data-wagon-cell @click="cycleBrakeType(wagon)" @keydown.enter.prevent="cycleBrakeType(wagon); focusNextCell($event)" class="h-10 w-full rounded-md px-2 text-left text-sm font-semibold outline-none transition hover:bg-sky-50 focus:ring-2 focus:ring-inset focus:ring-sky-400/55 dark:hover:bg-sky-500/10" :title="@js(__('app.brake_type_cycle_hint'))">
                            <span x-text="wagon.brakeType || '—'"></span>
                        </button>
                    </div>
                    <div role="cell" class="flex items-center justify-center"><input x-model="wagon.discBrake" type="checkbox" class="h-5 w-5 rounded border-rt-border text-rt-accent focus:ring-rt-accent/35 dark:border-rt-dark-border"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.parkingBrake" type="number" min="0" step="0.1" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.maxSpeed" type="number" min="0" class="{{ $sheetInput }}"></div>
                    <div role="cell"><input data-wagon-cell @keydown.enter.prevent="focusNextCell($event)" x-model="wagon.remark" class="{{ $sheetInput }}"></div>
                    <div role="cell" class="flex items-center justify-center">
                        <button type="button" @click="clearWagon(index)" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-500 transition hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10" title="{{ __('app.clear_wagon') }}">
                            <i class="far fa-trash-alt" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</section>
