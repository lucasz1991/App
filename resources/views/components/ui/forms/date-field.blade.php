@props([
    'min' => null,
    'max' => null,
    'disabled' => false,
    'readonly' => false,
    'clearable' => true,
    'ariaLabel' => null,
])

{{-- Ein gemeinsames Feld: x-model bleibt fuer Alpine-Verbraucher erhalten;
     wire:model bindet denselben ISO-Wert via Livewire entangle. --}}
@php
    $locale = app()->getLocale() === 'de' ? 'de-DE' : 'en-GB';
    $wireModel = $attributes->wire('model')->value();
    $wireLive = $attributes->wire('model')->hasModifier('live');
    $fieldId = $attributes->get('id');
    $controlLabel = $ariaLabel ?: $attributes->get('aria-label') ?: __('app.date');
    $alpineConfig = [
        'locale' => $locale,
        'weekStart' => 1,
        'min' => $min,
        'max' => $max,
        'disabled' => (bool) $disabled,
        'readonly' => (bool) $readonly,
        'clearable' => (bool) $clearable,
        'value' => $wireModel ? '' : (string) $attributes->get('value', ''),
    ];
    $outerAttributes = $attributes->whereDoesntStartWith('wire:model')->except([
        'id', 'value', 'name', 'required', 'aria-label', 'aria-describedby', 'aria-invalid',
    ]);
    $previousYearLabel = $locale === 'de-DE' ? 'Vorheriges Jahr' : 'Previous year';
    $nextYearLabel = $locale === 'de-DE' ? 'Nächstes Jahr' : 'Next year';
@endphp

<div
    x-data="rtDateField({ ...{{ \Illuminate\Support\Js::from($alpineConfig) }}@if($wireModel), value: $wire.entangle({{ \Illuminate\Support\Js::from($wireModel) }}){{ $wireLive ? '.live' : '' }}@endif })"
    x-modelable="value"
    x-id="['rt-date-panel', 'rt-date-heading']"
    @keydown.escape="if (open) { $event.preventDefault(); $event.stopPropagation(); closePanel(true); }"
    {{ $outerAttributes->merge(['class' => 'rt-ui-date-field relative']) }}
    data-rt-date-field
    @if($wireModel)
        data-autosave-field
        data-autosave-model="{{ $wireModel }}"
        data-autosave-field-id="{{ $fieldId ?: $wireModel }}"
        data-autosave-state="idle"
    @endif
>
    <div
        x-ref="anchor"
        class="rt-ui-date-field__shell rt-ui-field-shell"
        :data-open="open ? 'true' : 'false'"
        :data-disabled="locked ? 'true' : 'false'"
    >
        <input
            x-ref="display"
            @if($fieldId) id="{{ $fieldId }}" @endif
            type="text"
            inputmode="numeric"
            autocomplete="off"
            placeholder="{{ __('app.date_format_hint') }}"
            x-model="display"
            @blur="commitTyped()"
            @keydown.enter.prevent="commitTyped(); closePanel()"
            @keydown.down.prevent="openPanel($event.currentTarget)"
            @disabled($disabled)
            @readonly($readonly)
            @if($attributes->has('required')) required @endif
            aria-label="{{ $controlLabel }}"
            aria-haspopup="dialog"
            :aria-controls="$id('rt-date-panel')"
            :aria-expanded="open"
            @if($attributes->has('aria-describedby')) aria-describedby="{{ $attributes->get('aria-describedby') }}" @endif
            @if($attributes->has('aria-invalid')) aria-invalid="{{ $attributes->get('aria-invalid') }}" @endif
            class="rt-ui-date-field__display"
        >
        <button
            type="button"
            @click="togglePanel($event.currentTarget)"
            @disabled($disabled || $readonly)
            class="rt-ui-date-field__trigger"
            :aria-expanded="open"
            :aria-controls="$id('rt-date-panel')"
            aria-haspopup="dialog"
            aria-label="{{ __('app.date_choose') }}: {{ $controlLabel }}"
        >
            <i class="far fa-calendar-days" aria-hidden="true"></i>
        </button>
    </div>

    @if($attributes->has('name'))
        <input type="hidden" name="{{ $attributes->get('name') }}" :value="value" @disabled($disabled) />
    @endif

    <template x-teleport="body">
        {{-- important bewahrt x-show gegen die Legacy-flex-Utility. --}}
        <div
            x-show.important="open"
            x-cloak
            x-transition.opacity.duration.150ms
            @click.outside="if (!$refs.anchor.contains($event.target)) closePanel()"
            @keydown="handlePanelKeydown($event)"
            x-ref="panel"
            :id="$id('rt-date-panel')"
            role="dialog"
            aria-modal="false"
            :aria-labelledby="$id('rt-date-heading')"
            tabindex="-1"
            :style="panelStyle"
            class="rt-ui-date-panel fixed"
        >
            <div class="rt-ui-date-panel__header">
                <button type="button" @click="shiftMonth(-12)" :disabled="!canShiftMonth(-12)" class="rt-ui-date-nav" aria-label="{{ $previousYearLabel }}">
                    <i class="far fa-angles-left" aria-hidden="true"></i>
                </button>
                <button type="button" @click="shiftMonth(-1)" :disabled="!canShiftMonth(-1)" class="rt-ui-date-nav" aria-label="{{ __('app.date_previous_month') }}">
                    <i class="far fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div :id="$id('rt-date-heading')" class="rt-ui-date-panel__heading" aria-live="polite" aria-atomic="true">
                    <strong x-text="monthName"></strong>
                    <span x-text="viewYear"></span>
                </div>
                <button type="button" @click="shiftMonth(1)" :disabled="!canShiftMonth(1)" class="rt-ui-date-nav" aria-label="{{ __('app.date_next_month') }}">
                    <i class="far fa-chevron-right" aria-hidden="true"></i>
                </button>
                <button type="button" @click="shiftMonth(12)" :disabled="!canShiftMonth(12)" class="rt-ui-date-nav" aria-label="{{ $nextYearLabel }}">
                    <i class="far fa-angles-right" aria-hidden="true"></i>
                </button>
            </div>

            <div class="rt-ui-date-panel__body">
                <div class="rt-ui-date-panel__grid" role="grid" :aria-label="monthLabel" @keydown="handleGridKeydown($event)">
                    <div class="rt-ui-date-panel__weekdays" role="row">
                        <template x-for="weekday in weekdayLabels" :key="weekday">
                            <span role="columnheader" x-text="weekday"></span>
                        </template>
                    </div>
                    <template x-for="week in weeks" :key="week[0].iso">
                        <div class="rt-ui-date-panel__week" role="row">
                            <template x-for="day in week" :key="day.iso">
                                <button
                                    type="button"
                                    role="gridcell"
                                    @click="select(day.iso)"
                                    @focus="focusedIso = day.iso"
                                    :disabled="day.disabled"
                                    :tabindex="day.focused ? 0 : -1"
                                    :data-date-focused="day.focused ? 'true' : 'false'"
                                    :data-outside="day.outside ? 'true' : 'false'"
                                    :data-today="day.today ? 'true' : 'false'"
                                    :aria-current="day.today ? 'date' : null"
                                    :aria-selected="day.selected"
                                    :aria-label="formatLong(day.iso)"
                                    class="rt-ui-date-day"
                                    :class="day.selected ? 'rt-ui-date-day--selected' : ''"
                                    x-text="day.label"
                                ></button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="rt-ui-date-panel__footer">
                @if($clearable)
                    <button type="button" @click="clear()" class="rt-ui-date-action" x-show="hasValue">
                        {{ __('app.date_clear') }}
                    </button>
                @endif
                <button type="button" @click="selectToday()" :disabled="!todaySelectable" class="rt-ui-date-action rt-ui-date-action--accent">
                    <i class="far fa-calendar-day" aria-hidden="true"></i>
                    {{ __('app.today') }}
                </button>
            </div>
        </div>
    </template>
</div>
