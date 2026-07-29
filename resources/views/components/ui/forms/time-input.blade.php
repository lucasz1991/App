{{-- resources/views/components/ui/forms/time-input.blade.php --}}
@props([
    'id' => null,
    'name' => null,
    'label' => null,
    'hint' => null,
    'value' => null,          // initialer Wert: "HH:ii"
    'min' => null,            // z.B. "08:00"
    'max' => null,            // z.B. "23:00"
    'placeholder' => 'HH:MM',
    'required' => false,
])

@php
    // minuteIncrement aus dem step-Attribut (Sekunden) ableiten: 300s => 5 Min
    $stepAttr = $attributes->get('step');
    $minuteIncrement = 5;
    if (is_numeric($stepAttr) && (int)$stepAttr > 0) {
        $minuteIncrement = max(1, (int)$stepAttr / 60);
    }

    $inputId = $id ?? $name ?? 'time_'.uniqid();
@endphp



<div class="space-y-1.5" x-data="{
    fp: null,
    init() {
        const el = this.$refs.input;

        // Flatpickr initialisieren
        this.fp = flatpickr(el, {
            enableTime: true,
            noCalendar: true,
            dateFormat: 'H:i',
            time_24hr: true,
            allowInput: true,
            minuteIncrement: {{ (int)$minuteIncrement }},
            {{ $min ? "minTime: '{$min}'," : '' }}
            {{ $max ? "maxTime: '{$max}'," : '' }}
            defaultDate: el.value || null,
            onOpen: () => { /* optional: Platz für Logik beim Öffnen */ },
            onChange: (selectedDates, dateStr) => {
                el.value = dateStr ?? '';
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        // Wenn der Wert extern (Livewire-Update) geändert wird, Flatpickr syncen
        el.addEventListener('input', () => {
            if (this.fp && this.fp.input.value !== el.value) {
                this.fp.setDate(el.value || null, false);
            }
        });
    }
}">
    @if($label)
        <x-ui.forms.label for="{{ $inputId }}">
            {{ $label }} @if($required)<span class="text-rt-red" aria-hidden="true">*</span>@endif
        </x-ui.forms.label>
    @endif

    <div class="relative">
        <input
            x-ref="input"
            id="{{ $inputId }}"
            name="{{ $name }}"
            type="text"
            inputmode="numeric"
            placeholder="{{ $placeholder }}"
            value="{{ old($name, $value) }}"
            @if($required) required @endif
            pattern="^\d{2}:\d{2}$"
            {{ $attributes->merge([
                'class' => 'rt-ui-control block min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 pr-11 text-base leading-6 text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft transition-[border-color,box-shadow,background-color,color] duration-200 ease-rt-spring hover:border-rt-accent/50 hover:shadow-rt-sm focus:border-rt-accent disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-60 disabled:shadow-none sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas',
                // Hinweis: min/max bei Flatpickr werden via JS gesetzt; HTML-Attribute sind optional
                'autocomplete' => 'off',
            ]) }}
        />

        <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
            <i class="fal fa-clock text-rt-muted dark:text-rt-dark-muted fa-lg"></i>
        </div>
    </div>

    @if($hint)
        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="flex items-start gap-1.5 text-xs font-medium leading-5 text-rt-red dark:text-rt-dark-accent" role="alert">
            <i class="far fa-circle-exclamation mt-1 shrink-0 text-[0.7rem]" aria-hidden="true"></i>
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
