@props([
    'from' => '',
    'until' => '',
    'fromModel' => null,
    'untilModel' => null,
    'applyAction' => null,
    'today' => null,
    'min' => null,
    'max' => null,
    'maxDays' => null,
    'resetOnOpen' => false,
])
@php
    $de = app()->getLocale() === 'de';
    $config = [
        'locale' => $de ? 'de-DE' : 'en-GB', 'from' => $from, 'until' => $until,
        'today' => $today ?: now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString(),
        'min' => $min, 'max' => $max, 'maxDays' => $maxDays, 'resetOnOpen' => $resetOnOpen,
    ];
@endphp
<div
    x-data="rtDateRangePicker({ ...{{ \Illuminate\Support\Js::from($config) }}@if($fromModel), from: $wire.entangle({{ \Illuminate\Support\Js::from($fromModel) }})@endif @if($untilModel), until: $wire.entangle({{ \Illuminate\Support\Js::from($untilModel) }})@endif @if($applyAction), onApply: async (range) => { await $wire.call({{ \Illuminate\Support\Js::from($applyAction) }}, range.from, range.until); }@endif })"
    x-id="['range-from', 'range-until', 'range-status']"
    {{ $attributes->class('rt-date-range-picker') }}
    data-rt-date-range-picker data-rt-dropdown-keep-open
    :aria-busy="busy"
>
    <aside class="rt-date-range-picker__presets" aria-label="{{ $de ? 'Zeitraum-Vorgaben' : 'Date range presets' }}">
        <span class="rt-date-range-picker__eyebrow">{{ $de ? 'Zeitraum' : 'Date range' }}</span>
        <template x-for="preset in presets" :key="preset.key">
            <button type="button" @click="choosePreset(preset)" :disabled="busy || preset.disabled" :aria-pressed="draftFrom === preset.from && draftUntil === preset.until" class="rt-date-range-picker__preset">
                <span x-text="preset.label"></span><i class="far fa-check" x-show="draftFrom === preset.from && draftUntil === preset.until" aria-hidden="true"></i>
            </button>
        </template>
    </aside>
    <div class="rt-date-range-picker__main">
        <div class="rt-date-range-picker__fields">
            <label :for="$id('range-from')"><span>{{ $de ? 'Von' : 'From' }}</span><input :id="$id('range-from')" :value="fromText" @input="updateTyped(0, $event.target.value)" :disabled="busy" type="text" inputmode="numeric" autocomplete="off" placeholder="{{ $de ? 'TT.MM.JJJJ' : 'DD/MM/YYYY' }}" :aria-describedby="$id('range-status')"></label>
            <span class="rt-date-range-picker__separator" aria-hidden="true">–</span>
            <label :for="$id('range-until')"><span>{{ $de ? 'Bis' : 'To' }}</span><input :id="$id('range-until')" :value="untilText" @input="updateTyped(1, $event.target.value)" :disabled="busy" type="text" inputmode="numeric" autocomplete="off" placeholder="{{ $de ? 'TT.MM.JJJJ' : 'DD/MM/YYYY' }}" :aria-describedby="$id('range-status')"></label>
            <button type="button" @click="clear()" :disabled="busy" class="rt-date-range-picker__clear">{{ $de ? 'Zurücksetzen' : 'Clear' }}</button>
        </div>
        <div class="rt-date-range-picker__calendars">
            @foreach([0, 1] as $side)
                <section class="rt-date-range-picker__calendar" data-range-calendar="{{ $side }}" :aria-label="monthLabel({{ $side }})">
                    <div class="rt-date-range-picker__month-nav">
                        <x-ui.buttons.button-basic type="button" mode="link" size="sm" @click="shiftMonth({{ $side }}, -1)" x-bind:disabled="busy" class="rt-date-range-picker__nav" aria-label="{{ $de ? 'Vorheriger Monat' : 'Previous month' }}"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
                        <strong x-text="monthLabel({{ $side }})" aria-live="polite"></strong>
                        <x-ui.buttons.button-basic type="button" mode="link" size="sm" @click="shiftMonth({{ $side }}, 1)" x-bind:disabled="busy" class="rt-date-range-picker__nav" aria-label="{{ $de ? 'Nächster Monat' : 'Next month' }}"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                    </div>
                    <div class="rt-date-range-picker__grid" role="grid" :aria-label="monthLabel({{ $side }})" @keydown="handleKey($event, {{ $side }})" @mouseleave="hover = null">
                        <div class="rt-date-range-picker__weekdays" role="row"><template x-for="weekday in weekdays" :key="weekday"><span role="columnheader" x-text="weekday"></span></template></div>
                        <template x-for="week in [0, 1, 2, 3, 4, 5]" :key="week">
                            <div class="rt-date-range-picker__week" role="row">
                                <template x-for="day in days({{ $side }}).slice(week * 7, week * 7 + 7)" :key="day.iso">
                                    <button type="button" role="gridcell" class="rt-date-range-picker__day" @click="select(day.iso, {{ $side }})" @mouseenter="if (selectingEnd && !day.outside) hover = day.iso" @focus="focused[{{ $side }}] = day.iso"
                                        :disabled="busy || day.outside || day.disabled" :tabindex="!day.outside && day.iso === focused[{{ $side }}] ? 0 : -1" :data-range-date="day.iso" :data-range-state="state(day.iso)" :data-outside="day.outside" :data-hidden-neighbor="day.hiddenNeighbor" :aria-hidden="day.hiddenNeighbor ? 'true' : null" :data-today="day.iso === today"
                                        :aria-selected="['start', 'end', 'between'].includes(state(day.iso))" :aria-current="day.iso === today ? 'date' : null" :aria-label="format(day.iso, true)" x-text="day.label"></button>
                                </template>
                            </div>
                        </template>
                    </div>
                </section>
            @endforeach
        </div>
        <div class="rt-date-range-picker__footer">
            <span :id="$id('range-status')" role="status" class="rt-date-range-picker__status" :data-error="!!failure || (touched && !!validationMessage && !selectingEnd)" x-text="summary"></span>
            <div class="rt-date-range-picker__actions">
                <x-ui.buttons.button-basic type="button" size="sm" @click="cancel()" x-bind:disabled="busy">{{ $de ? 'Abbrechen' : 'Cancel' }}</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" size="sm" mode="primary" @click="apply()" x-bind:disabled="!canApply"><span x-text="busy ? {{ \Illuminate\Support\Js::from($de ? 'Wird übernommen …' : 'Applying …') }} : {{ \Illuminate\Support\Js::from($de ? 'Übernehmen' : 'Apply') }}"></span></x-ui.buttons.button-basic>
            </div>
        </div>
    </div>
</div>
