@props([
    // Untergrenze/Obergrenze als ISO-Datum (YYYY-MM-DD).
    'min' => null,
    'max' => null,
    'disabled' => false,
    'readonly' => false,
    // Beschriftung fuer Hilfstechnik, wenn kein umschliessendes <label> greift.
    'ariaLabel' => null,
])

{{--
    Gemeinsames Datumsfeld — Ersatz fuer <input type="date">.

    Warum nicht nativ: der Browser-Datepicker ist weder gestaltbar noch
    positionierbar, und seine Mindestbreite sprengt enge Raster. In der
    Wagenliste stand das Feld dadurch mit Ueberlauf in der fuenfspaltigen
    Kopfzeile.

    Aufbau wie bei x-ui.forms.number-input: der WERT liegt in einem
    versteckten Feld, an dem auch gebunden wird (x-model ODER wire:model).
    Sichtbar ist ein Textfeld mit deutscher Schreibweise; der Kalender haengt
    per x-teleport am <body> und wird fixed positioniert, damit ihn kein
    overflow:hidden abschneidet (Vollbild-Modal, Wizard-Folien).
--}}

@php
    $locale = app()->getLocale() === 'de' ? 'de-DE' : 'en-GB';
    $fieldId = 'rt-date-'.\Illuminate\Support\Str::random(6);
    $alpineConfig = [
        'locale' => $locale,
        'weekStart' => app()->getLocale() === 'de' ? 1 : 1,
        'min' => $min,
        'max' => $max,
    ];
@endphp

<div
    x-data="rtDateField({{ \Illuminate\Support\Js::from($alpineConfig) }})"
    x-on:keydown.escape.stop="closePanel(true)"
    class="rt-ui-date-field relative"
    data-rt-date-field
>
    <input
        x-ref="field"
        type="hidden"
        @disabled($disabled)
        {{ $attributes->except(['class']) }}
    >

    <div
        x-ref="anchor"
        class="rt-ui-date-field__shell flex min-h-11 w-full items-stretch overflow-hidden rounded-xl border border-rt-border bg-rt-control shadow-rt-xs transition-[border-color,box-shadow] duration-200 ease-rt-spring hover:border-rt-accent/50 focus-within:border-rt-accent focus-within:shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:hover:border-rt-dark-accent"
        :data-open="open ? 'true' : 'false'"
    >
        <input
            x-ref="display"
            id="{{ $fieldId }}"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            placeholder="{{ __('app.date_format_hint') }}"
            x-model="display"
            x-mask="99.99.9999"
            @blur="commitTyped()"
            @keydown.enter.prevent="commitTyped(); closePanel()"
            @keydown.down.prevent="openPanel()"
            @disabled($disabled)
            @readonly($readonly)
            @if (filled($ariaLabel)) aria-label="{{ $ariaLabel }}" @endif
            class="{{ $attributes->get('class') }} min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-base tabular-nums leading-6 text-rt-text outline-none placeholder:text-rt-soft focus:ring-0 disabled:cursor-not-allowed sm:text-sm sm:leading-5 dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft"
        >

        <button
            type="button"
            @click="togglePanel()"
            @disabled($disabled || $readonly)
            class="flex w-10 shrink-0 items-center justify-center border-l border-rt-border text-rt-muted transition-colors hover:bg-rt-surface-muted hover:text-rt-accent active:scale-95 disabled:pointer-events-none disabled:opacity-40 dark:border-rt-dark-border dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
            :aria-expanded="open"
            aria-haspopup="dialog"
            aria-label="{{ __('app.date_choose') }}"
        >
            <i class="far fa-calendar-days" aria-hidden="true"></i>
        </button>
    </div>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition duration-150 ease-out"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-100 ease-in"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click.outside="closePanel()"
            x-ref="panel"
            role="dialog"
            aria-modal="false"
            aria-label="{{ __('app.date_choose') }}"
            :style="panelStyle"
            class="rt-ui-date-panel fixed z-[240] flex flex-col overflow-hidden rounded-2xl border border-rt-border bg-rt-surface shadow-rt-lg dark:border-rt-dark-border dark:bg-rt-dark-surface"
        >
            <div class="flex shrink-0 items-center justify-between gap-2 border-b border-rt-border/70 px-2.5 py-2 dark:border-rt-dark-border/70">
                <button
                    type="button"
                    @click="shiftMonth(-1)"
                    class="rt-ui-date-nav"
                    aria-label="{{ __('app.date_previous_month') }}"
                >
                    <i class="far fa-chevron-left" aria-hidden="true"></i>
                </button>
                <strong class="min-w-0 flex-1 truncate text-center text-sm font-semibold capitalize text-rt-text dark:text-rt-dark-text" x-text="monthLabel" aria-live="polite"></strong>
                <button
                    type="button"
                    @click="shiftMonth(1)"
                    class="rt-ui-date-nav"
                    aria-label="{{ __('app.date_next_month') }}"
                >
                    <i class="far fa-chevron-right" aria-hidden="true"></i>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-2.5">
                <div class="grid grid-cols-7 gap-1 pb-1" aria-hidden="true">
                    <template x-for="weekday in weekdayLabels" :key="weekday">
                        <span class="text-center text-[10px] font-bold uppercase tracking-[0.06em] text-rt-soft dark:text-rt-dark-soft" x-text="weekday"></span>
                    </template>
                </div>

                <div class="grid grid-cols-7 gap-1" role="grid" @keydown="handleGridKeydown($event)">
                    <template x-for="day in days" :key="day.iso">
                        <button
                            type="button"
                            role="gridcell"
                            @click="select(day.iso)"
                            :disabled="day.disabled"
                            :tabindex="day.focused ? 0 : -1"
                            :data-date-focused="day.focused ? 'true' : 'false'"
                            :data-outside="day.outside ? 'true' : 'false'"
                            :data-today="day.today ? 'true' : 'false'"
                            :aria-selected="day.selected"
                            :aria-label="formatLong(day.iso)"
                            class="rt-ui-date-day"
                            :class="day.selected ? 'rt-ui-date-day--selected' : ''"
                            x-text="day.label"
                        ></button>
                    </template>
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-2 border-t border-rt-border/70 px-2.5 py-2 dark:border-rt-dark-border/70">
                <button type="button" @click="clear()" class="rt-ui-date-action" x-show="hasValue">
                    {{ __('app.date_clear') }}
                </button>
                <button type="button" @click="selectToday()" class="rt-ui-date-action rt-ui-date-action--accent ml-auto">
                    {{ __('app.today') }}
                </button>
            </div>
        </div>
    </template>
</div>
