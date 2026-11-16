{{--
    Wagen der Liste — Desktop.

    Standard ist die Tabelle mit der Spaltenfolge der Excel-Liste v16_5;
    „Einzeln“ zeigt denselben Wagen als Formular. Beide arbeiten auf
    denselben Daten.

    TABELLE: Laufende Nummer und Wagennummer stehen beim seitlichen Scrollen
    fest, Kopf und Summenzeile beim senkrechten. Enter springt ins nächste
    Feld, Pfeil hoch/runter in dieselbe Spalte der Nachbarzeile
    (handleSheetKeydown). Eine eingefügte zwölfstellige Nummer verteilt sich
    auf die fünf Felder.
--}}
@php
    $rowLabel = static fn (string $fieldLabel): string =>
        ((string) \Illuminate\Support\Js::from(__('app.row')))
        ." + ' ' + (index + 1) + ', ' + "
        .((string) \Illuminate\Support\Js::from($fieldLabel));

    $numberParts = [
        ['field' => 'number12', 'label' => '1+2', 'max' => 2],
        ['field' => 'number34', 'label' => '3+4', 'max' => 2],
        ['field' => 'number58', 'label' => '5–8', 'max' => 4],
        ['field' => 'number911', 'label' => '9–11', 'max' => 3],
        ['field' => 'checkDigit', 'label' => '12', 'max' => 1],
    ];
@endphp

<section class="rt-wagon-sheet-shell rt-wl-box rt-wl-sheet-shell" aria-labelledby="wagon-sheet-title">
    <div class="rt-wl-toolbar">
        <div class="flex min-w-0 items-center gap-2.5">
            <h2 id="wagon-sheet-title">{{ __('app.wagons') }}</h2>
            <span class="rt-wl-count" title="{{ __('app.wagons_filled') }}"><span x-text="completionCount"></span> / <span x-text="visibleCount"></span></span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="rt-wl-tabs" role="group" aria-label="{{ __('app.spreadsheet_view') }}">
                <button
                    type="button"
                    @click="desktopTableMode = 'quick'"
                    :aria-pressed="desktopTableMode === 'quick'"
                    :data-active="desktopTableMode === 'quick' ? 'true' : 'false'"
                    class="rt-wagon-section-tab rt-wl-tab"
                >
                    <i class="far fa-table" aria-hidden="true"></i>
                    {{ __('app.wagon_table_view') }}
                </button>
                <button
                    type="button"
                    @click="desktopTableMode = 'overview'"
                    :aria-pressed="desktopTableMode === 'overview'"
                    :data-active="desktopTableMode === 'overview' ? 'true' : 'false'"
                    class="rt-wagon-section-tab rt-wl-tab"
                >
                    <i class="far fa-list" aria-hidden="true"></i>
                    {{ __('app.wagon_single_view') }}
                </button>
            </div>

            <button type="button" class="rt-wl-ghost" @click="reverseWagons()" :disabled="completionCount < 2">
                <i class="far fa-sort-alt" aria-hidden="true"></i>
                {{ __('app.wagon_reverse_order') }}
            </button>

            <button
                type="button"
                class="rt-wl-primary"
                @click="addWagon(); desktopWagon = visibleCount - 1"
                :disabled="visibleCount >= 40"
            >
                <i class="far fa-plus" aria-hidden="true"></i>
                {{ __('app.add_wagon') }}
            </button>
        </div>
    </div>

    {{-- Tabelle ------------------------------------------------------------ --}}
    <div
        x-show.important="desktopTableMode === 'quick'"
        class="rt-wl-sheet"
        data-wagon-desktop-quick
        data-wagon-sheet
        role="table"
        aria-label="{{ __('app.wagon_list') }}"
        @keydown="handleSheetKeydown($event)"
    >
        <div class="rt-wl-head" role="rowgroup">
            <div class="rt-wl-grid" role="row">
                <div role="columnheader" class="rt-wl-th rt-wl-th--group rt-wl-stick rt-wl-stick-0" aria-hidden="true"></div>
                <div role="columnheader" aria-colspan="5" style="grid-column: span 5;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge rt-wl-stick rt-wl-stick-1">{{ __('app.wagon_number') }}</div>
                <div role="columnheader" aria-colspan="3" style="grid-column: span 3;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge">{{ __('app.wagon_group_vehicle') }}</div>
                <div role="columnheader" aria-colspan="3" style="grid-column: span 3;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge">{{ __('app.wagon_group_weights') }}</div>
                <div role="columnheader" aria-colspan="2" style="grid-column: span 2;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge">{{ __('app.wagon_group_brake_weight') }}</div>
                <div role="columnheader" aria-colspan="2" style="grid-column: span 2;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge">{{ __('app.route') }}</div>
                <div role="columnheader" aria-colspan="3" style="grid-column: span 3;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge">{{ __('app.wagon_group_brake') }}</div>
                <div role="columnheader" aria-colspan="2" style="grid-column: span 2;" class="rt-wl-th rt-wl-th--group rt-wl-th--edge">{{ __('app.wagon_group_dangerous') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--group" aria-hidden="true"></div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--group" aria-hidden="true"></div>
            </div>

            <div class="rt-wl-grid" role="row">
                <div role="columnheader" class="rt-wl-th rt-wl-stick rt-wl-stick-0">{{ __('app.wagon_col_position') }}</div>
                @foreach ($numberParts as $position => $part)
                    <div role="columnheader" class="rt-wl-th rt-wl-stick rt-wl-stick-{{ $position + 1 }} {{ $loop->last ? 'rt-wl-th--edge' : '' }}">{{ $part['label'] }}</div>
                @endforeach
                <div role="columnheader" class="rt-wl-th">{{ __('app.category') }}</div>
                <div role="columnheader" class="rt-wl-th">{{ __('app.axles') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--edge">{{ __('app.wagon_col_length') }}</div>
                <div role="columnheader" class="rt-wl-th">{{ __('app.wagon_col_wagon_weight') }}</div>
                <div role="columnheader" class="rt-wl-th">{{ __('app.wagon_col_load_weight') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--edge">{{ __('app.wagon_col_total') }}</div>
                <div role="columnheader" class="rt-wl-th">G</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--edge">P</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--left">{{ __('app.shipping_station') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--left rt-wl-th--edge">{{ __('app.destination_station') }}</div>
                <div role="columnheader" class="rt-wl-th" title="{{ __('app.brake_sole_legend') }}">{{ __('app.wagon_col_sole') }}</div>
                <div role="columnheader" class="rt-wl-th">{{ __('app.wagon_col_parking') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--edge">{{ __('app.wagon_col_speed') }}</div>
                <div role="columnheader" class="rt-wl-th">{{ __('app.un_number') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--edge">{{ __('app.hazard_label') }}</div>
                <div role="columnheader" class="rt-wl-th rt-wl-th--left">{{ __('app.remark') }}</div>
                <div role="columnheader" class="rt-wl-th" aria-label="{{ __('app.actions') }}"></div>
            </div>
        </div>

        <div role="rowgroup">
            <template x-for="(wagon, index) in visibleWagons" :key="index">
                <div class="rt-wl-grid rt-wl-row" role="row" data-wagon-row>
                    <div role="rowheader" class="rt-wl-td rt-wl-index rt-wl-stick rt-wl-stick-0" :title="missingFieldsText(wagon)">
                        <span class="rt-wl-dot" :data-state="wagonStatus(wagon)" aria-hidden="true"></span>
                        <span x-text="index + 1"></span>
                    </div>

                    @foreach ($numberParts as $position => $part)
                        <div role="cell" class="rt-wl-td rt-wl-stick rt-wl-stick-{{ $position + 1 }} {{ $loop->last ? 'rt-wl-td--edge' : '' }}">
                            <input
                                data-wagon-cell
                                data-wagon-column="{{ $part['field'] }}"
                                data-wagon-number-part
                                x-model="wagon.{{ $part['field'] }}"
                                inputmode="numeric"
                                maxlength="{{ $part['max'] }}"
                                autocomplete="off"
                                @input="advanceNumberField($event)"
                                @paste="handleNumberPaste($event, wagon)"
                                @if ($part['field'] === 'checkDigit')
                                    :data-invalid="checkState(wagon) === 'invalid' ? 'true' : 'false'"
                                    :placeholder="expectedCheckDigit(wagon) ?? ''"
                                    :title="checkState(wagon) === 'invalid' ? @js(__('app.expected_check_digit')) + ': ' + expectedCheckDigit(wagon) : ''"
                                @endif
                                x-bind:aria-label="{{ $rowLabel($part['label']) }}"
                                class="rt-wl-cell rt-wl-cell--center"
                            >
                        </div>
                    @endforeach

                    <div role="cell" class="rt-wl-td">
                        <input
                            data-wagon-cell
                            data-wagon-column="category"
                            x-model="wagon.category"
                            autocomplete="off"
                            :data-auto="wagon.categoryAuto && wagon.category === wagon.categoryAuto ? 'true' : 'false'"
                            x-bind:aria-label="{{ $rowLabel(__('app.category')) }}"
                            class="rt-wl-cell"
                        >
                    </div>
                    <div role="cell" class="rt-wl-td">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="32" data-wagon-cell data-wagon-column="axles" x-model="wagon.axles" :x-bind:aria-label="$rowLabel(__('app.axles'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>
                    <div role="cell" class="rt-wl-td rt-wl-td--edge">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.01" :decimals="2" data-wagon-cell data-wagon-column="length" x-model="wagon.length" :x-bind:aria-label="$rowLabel(__('app.length_m'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>

                    <div role="cell" class="rt-wl-td">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.01" :decimals="2" data-wagon-cell data-wagon-column="wagonWeight" x-model="wagon.wagonWeight" :x-bind:aria-label="$rowLabel(__('app.wagon_weight_t'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>
                    <div role="cell" class="rt-wl-td">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.01" :decimals="2" data-wagon-cell data-wagon-column="loadWeight" x-model="wagon.loadWeight" :x-bind:aria-label="$rowLabel(__('app.load_weight_t'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>
                    <div role="cell" class="rt-wl-td rt-wl-td--calc rt-wl-td--edge" x-bind:aria-label="{{ $rowLabel(__('app.total_weight')) }}">
                        <span x-text="totalWeight(wagon) > 0 ? formatPlain(totalWeight(wagon), 2) : ''"></span>
                    </div>

                    <div role="cell" class="rt-wl-td">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" data-wagon-cell data-wagon-column="brakeG" x-model="wagon.brakeG" :x-bind:aria-label="$rowLabel(__('app.brake_weight_g'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>
                    <div role="cell" class="rt-wl-td rt-wl-td--edge">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" data-wagon-cell data-wagon-column="brakeP" x-model="wagon.brakeP" :x-bind:aria-label="$rowLabel(__('app.brake_weight_p'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>

                    <div role="cell" class="rt-wl-td">
                        <input data-wagon-cell data-wagon-column="shippingStation" x-model="wagon.shippingStation" autocomplete="off" x-bind:aria-label="{{ $rowLabel(__('app.shipping_station')) }}" class="rt-wl-cell">
                    </div>
                    <div role="cell" class="rt-wl-td rt-wl-td--edge">
                        <input data-wagon-cell data-wagon-column="destinationStation" x-model="wagon.destinationStation" autocomplete="off" x-bind:aria-label="{{ $rowLabel(__('app.destination_station')) }}" class="rt-wl-cell">
                    </div>

                    <div role="cell" class="rt-wl-td">
                        <input
                            data-wagon-cell
                            data-wagon-column="brakeType"
                            x-model="wagon.brakeType"
                            @change="normalizeSole(wagon)"
                            maxlength="2"
                            autocomplete="off"
                            autocapitalize="characters"
                            title="{{ __('app.brake_sole_legend') }}"
                            x-bind:aria-label="{{ $rowLabel(__('app.brake_sole')) }}"
                            class="rt-wl-cell rt-wl-cell--center uppercase"
                        >
                    </div>
                    <div role="cell" class="rt-wl-td">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" step="0.1" :decimals="1" data-wagon-cell data-wagon-column="parkingBrake" x-model="wagon.parkingBrake" :x-bind:aria-label="$rowLabel(__('app.parking_brake_kn'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>
                    <div role="cell" class="rt-wl-td rt-wl-td--edge">
                        <x-ui.forms.number-input :stepper="false" :keyboard-step="false" min="0" max="200" data-wagon-cell data-wagon-column="maxSpeed" x-model="wagon.maxSpeed" :x-bind:aria-label="$rowLabel(__('app.maximum_speed'))" class="rt-wl-cell rt-wl-cell--center" />
                    </div>

                    <div role="cell" class="rt-wl-td">
                        <input data-wagon-cell data-wagon-column="unNumber" x-model="wagon.unNumber" maxlength="39" autocomplete="off" x-bind:aria-label="{{ $rowLabel(__('app.un_number')) }}" class="rt-wl-cell rt-wl-cell--center">
                    </div>
                    <div role="cell" class="rt-wl-td rt-wl-td--edge">
                        <input data-wagon-cell data-wagon-column="hazardLabel" x-model="wagon.hazardLabel" maxlength="20" autocomplete="off" x-bind:aria-label="{{ $rowLabel(__('app.hazard_label')) }}" class="rt-wl-cell rt-wl-cell--center">
                    </div>

                    <div role="cell" class="rt-wl-td">
                        <input data-wagon-cell data-wagon-column="remark" x-model="wagon.remark" maxlength="255" autocomplete="off" x-bind:aria-label="{{ $rowLabel(__('app.remark')) }}" class="rt-wl-cell">
                    </div>

                    <div role="cell" class="rt-wl-td rt-wl-row-actions">
                        <button type="button" class="rt-wl-row-action" @click="moveWagon(index, -1)" :disabled="index === 0" title="{{ __('app.wagon_move_up') }}" x-bind:aria-label="{{ $rowLabel(__('app.wagon_move_up')) }}">
                            <i class="far fa-arrow-up" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="rt-wl-row-action" @click="moveWagon(index, 1)" :disabled="index >= visibleCount - 1" title="{{ __('app.wagon_move_down') }}" x-bind:aria-label="{{ $rowLabel(__('app.wagon_move_down')) }}">
                            <i class="far fa-arrow-down" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="rt-wl-row-action rt-wl-row-action--danger" @click="removeWagon(index)" title="{{ __('app.wagon_remove') }}" x-bind:aria-label="{{ $rowLabel(__('app.wagon_remove')) }}">
                            <i class="far fa-times" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        {{-- Summenzeile wie Zeile 47 der Excel-Liste --}}
        <div class="rt-wl-foot" role="rowgroup">
            <div class="rt-wl-grid" role="row">
                <div role="rowheader" class="rt-wl-td rt-wl-stick rt-wl-stick-0">Σ</div>
                <div role="cell" style="grid-column: span 5;" class="rt-wl-td rt-wl-td--edge rt-wl-stick rt-wl-stick-1">
                    <span x-text="totals.wagons"></span>&nbsp;{{ __('app.wagons') }}
                </div>
                <div role="cell" class="rt-wl-td"></div>
                <div role="cell" class="rt-wl-td" x-text="totals.axles > 0 ? formatPlain(totals.axles, 0) : ''"></div>
                <div role="cell" class="rt-wl-td rt-wl-td--edge" x-text="totals.length > 0 ? formatPlain(totals.length, 2) : ''"></div>
                <div role="cell" class="rt-wl-td" x-text="totals.wagonWeight > 0 ? formatPlain(totals.wagonWeight, 2) : ''"></div>
                <div role="cell" class="rt-wl-td" x-text="totals.loadWeight > 0 ? formatPlain(totals.loadWeight, 2) : ''"></div>
                <div role="cell" class="rt-wl-td rt-wl-td--edge" x-text="totals.totalWeight > 0 ? formatPlain(totals.totalWeight, 2) : ''"></div>
                <div role="cell" class="rt-wl-td" x-text="totals.brakeG > 0 ? formatPlain(totals.brakeG, 1) : ''"></div>
                <div role="cell" class="rt-wl-td rt-wl-td--edge" x-text="totals.brakeP > 0 ? formatPlain(totals.brakeP, 1) : ''"></div>
                <div role="cell" style="grid-column: span 9;" class="rt-wl-td"></div>
            </div>
        </div>
    </div>

    {{-- Einzelansicht: Wagen links wählen, rechts als Formular bearbeiten --}}
    <div
        x-show.important="desktopTableMode === 'overview'"
        x-cloak
        class="grid min-h-0 flex-1 grid-cols-[clamp(15rem,20vw,19rem)_minmax(0,1fr)]"
        data-wagon-desktop-overview
    >
        <div class="h-full min-h-0 overflow-y-auto border-r border-rt-border/70 bg-rt-surface-muted/45 p-2.5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/25">
            <ol
                class="rt-wagon-review-list"
                role="listbox"
                aria-label="{{ __('app.wagons') }}"
                :aria-activedescendant="`wagon-overview-option-${desktopWagon}`"
            >
                <template x-for="(wagon, index) in visibleWagons" :key="index">
                    <li>
                        <button
                            type="button"
                            role="option"
                            :id="`wagon-overview-option-${index}`"
                            :aria-selected="desktopWagon === index"
                            :data-state="wagonStatus(wagon)"
                            :data-current="desktopWagon === index ? 'true' : 'false'"
                            x-bind:aria-label="@js(__('app.selected_wagon')) + ' ' + (index + 1) + ': ' + (wagonNumber(wagon) || @js(__('app.wagon_empty')))"
                            @click="desktopWagon = index"
                            class="rt-wagon-review-item"
                        >
                            <span class="rt-wagon-review-item-index" x-text="index + 1"></span>
                            <span class="min-w-0 flex-1">
                                <span class="rt-wagon-review-item-title" x-text="wagonNumber(wagon) || `${@js(__('app.wagons'))} ${index + 1}`"></span>
                                <span class="rt-wagon-review-item-meta" x-show="isWagonFilled(wagon)">
                                    <span x-text="wagon.category || '—'"></span>
                                    <span aria-hidden="true">·</span>
                                    <span class="tabular-nums" x-text="`${formatPlain(totalWeight(wagon), 1)} t`"></span>
                                </span>
                                <span class="rt-wagon-review-item-route" x-show="wagonRouteLabel(wagon)" x-text="wagonRouteLabel(wagon)"></span>
                            </span>
                            <span class="rt-wagon-review-item-state" aria-hidden="true"></span>
                            <i class="far fa-chevron-right rt-wagon-review-item-chevron" aria-hidden="true"></i>
                        </button>
                    </li>
                </template>
            </ol>
        </div>

        <div class="h-full min-h-0 overflow-y-auto px-4 pb-4" data-wagon-detail-inspector>
            <div class="sticky top-0 z-10 -mx-4 flex items-center justify-between gap-4 border-b border-rt-border/70 bg-rt-surface/95 px-4 py-2.5 backdrop-blur-xl dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/95">
                <div class="min-w-0">
                    <h3 class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                        {{ __('app.selected_wagon') }} <span class="tabular-nums" x-text="desktopWagon + 1"></span>
                    </h3>
                    <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted" x-text="wagonNumber(wagons[desktopWagon]) || @js(__('app.wagon_empty'))"></p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" class="rt-wl-ghost" @click="moveWagon(desktopWagon, -1)" :disabled="desktopWagon === 0" title="{{ __('app.wagon_move_up') }}" aria-label="{{ __('app.wagon_move_up') }}">
                        <i class="far fa-arrow-up" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="rt-wl-ghost" @click="moveWagon(desktopWagon, 1)" :disabled="desktopWagon >= visibleCount - 1" title="{{ __('app.wagon_move_down') }}" aria-label="{{ __('app.wagon_move_down') }}">
                        <i class="far fa-arrow-down" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="rt-wl-ghost" @click="removeWagon(desktopWagon)" title="{{ __('app.wagon_remove') }}" aria-label="{{ __('app.wagon_remove') }}">
                        <i class="far fa-trash-alt" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="rt-wl-form-grid rt-wl-form-grid--2 mt-3">
                @include('livewire.operations.partials.wagon-detail-fields', [
                    'w' => 'wagons[desktopWagon]',
                    'scope' => 'desktop',
                    'anchors' => false,
                ])
            </div>
        </div>
    </div>
</section>
