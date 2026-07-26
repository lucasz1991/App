@props([
    'min' => null,
    'max' => null,
    'step' => 1,
    // Erlaubte Dezimalstellen. 0 = nur ganze Zahlen.
    'decimals' => 0,
    // Dezimaltrennzeichen. Standard Komma (deutsch). Fuer Felder, die
    // serverseitig mit Laravels 'numeric'-Regel geprueft werden, muss '.'
    // uebergeben werden — is_numeric() akzeptiert kein Komma.
    'separator' => ',',
    // Kurze Einheit rechts im Feld (z. B. t, m, km/h, Tage).
    'unit' => null,
    // Darf leer bleiben? Bei serverseitig typisierten Pflichtfeldern false.
    'nullable' => true,
    // Schritt-Tasten. In dichten Tabellenrastern auf false setzen.
    'stepper' => true,
    'disabled' => false,
    'readonly' => false,
])

@php
    $decimals = max(0, (int) $decimals);

    $alpineConfig = [
        'min' => $min === null || $min === '' ? null : (float) $min,
        'max' => $max === null || $max === '' ? null : (float) $max,
        'step' => (float) $step,
        'decimals' => $decimals,
        'separator' => (string) $separator,
        'nullable' => (bool) $nullable,
    ];

    // Ziffern-Tastatur auf Mobilgeraeten, ohne die Browser-Spinner von
    // type="number" mitzunehmen.
    $inputMode = $decimals > 0 ? 'decimal' : 'numeric';

    // Alpine-Mask: Ziffern und genau EIN Trennzeichen, bewusst OHNE
    // Tausendertrennung (leerer 3. Parameter). Sonst wuerde aus 1234,5
    // "1.234,5" — und die Weiterverarbeitung im Frontend ersetzt nur das
    // Komma, wodurch Werte ab 1000 still verfaelscht wuerden.
    $mask = '$money($input, separator, \'\', decimals)';

    $handlers = [
        'x-mask:dynamic' => $mask,
        '@blur' => 'normalize()',
        '@keydown.up.prevent' => 'nudge(1)',
        '@keydown.down.prevent' => 'nudge(-1)',
    ];

    $sharedInputClasses = 'tabular-nums';
@endphp

@if ($stepper)
    @php
        $shellClasses = 'rt-ui-number-input relative flex min-h-11 w-full items-stretch overflow-hidden rounded-xl border border-rt-border bg-rt-control shadow-rt-xs transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus-within:border-rt-accent focus-within:ring-4 focus-within:ring-rt-accent/15 dark:border-rt-dark-border dark:bg-rt-dark-control dark:hover:border-rt-dark-accent dark:focus-within:ring-rt-dark-accent/20'
            . ($disabled ? ' cursor-not-allowed bg-rt-surface-muted opacity-70 dark:bg-rt-dark-canvas' : '');
        // Bewusst <span role="button"> statt <button>: die Komponente wird auch
        // innerhalb von <label> verwendet, und ein <button> ist ein labelable
        // element — ein Klick auf den Beschriftungstext wuerde dann die erste
        // Taste ausloesen und den Wert veraendern. Spans sind nicht labelable,
        // dadurch zielt das <label> korrekt auf das Eingabefeld. Die Tastatur
        // bedient den Wert ohnehin ueber Pfeil hoch/runter am Feld selbst.
        $buttonClasses = 'flex w-10 shrink-0 cursor-pointer select-none items-center justify-center text-rt-muted transition-colors hover:bg-rt-surface-muted hover:text-rt-accent active:scale-95 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent'
            . ($disabled || $readonly ? ' pointer-events-none opacity-40' : '');
    @endphp

    <div
        x-data="rtNumberInput({{ \Illuminate\Support\Js::from($alpineConfig) }})"
        class="{{ $shellClasses }}"
        data-rt-number-input
    >
        <span
            role="button"
            aria-hidden="true"
            @click="nudge(-1)"
            title="{{ __('app.decrease') }}"
            class="{{ $buttonClasses }} border-r border-rt-border dark:border-rt-dark-border"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" d="M5 12h14" />
            </svg>
        </span>

        <input
            x-ref="field"
            type="text"
            inputmode="{{ $inputMode }}"
            autocomplete="off"
            role="spinbutton"
            @if ($min !== null) aria-valuemin="{{ $min }}" @endif
            @if ($max !== null) aria-valuemax="{{ $max }}" @endif
            :aria-valuenow="ariaValue"
            @disabled($disabled)
            @readonly($readonly)
            @foreach ($handlers as $directive => $expression)
                {{ $directive }}="{{ $expression }}"
            @endforeach
            {!! $attributes->merge([
                'class' => 'rt-ui-number-input__field min-w-0 flex-1 border-0 bg-transparent px-2 py-2.5 text-center text-base leading-6 text-rt-text outline-none placeholder:text-rt-soft focus:ring-0 disabled:cursor-not-allowed disabled:text-rt-soft sm:text-sm sm:leading-5 dark:text-white dark:placeholder:text-rt-dark-soft ' . $sharedInputClasses,
            ]) !!}
        >

        @if (filled($unit))
            <span class="pointer-events-none flex shrink-0 items-center pr-1 text-xs font-medium text-rt-soft dark:text-rt-dark-soft">{{ $unit }}</span>
        @endif

        <span
            role="button"
            aria-hidden="true"
            @click="nudge(1)"
            title="{{ __('app.increase') }}"
            class="{{ $buttonClasses }} border-l border-rt-border dark:border-rt-dark-border"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" d="M12 5v14M5 12h14" />
            </svg>
        </span>
    </div>
@else
    {{-- Kompakte Bauform fuer dichte Raster: nur das maskierte Feld, x-data
         sitzt direkt am <input>. So kommt kein zusaetzliches Element ins
         Layout und die Zellen-Navigation (data-wagon-cell) bleibt intakt. --}}
    <input
        x-data="rtNumberInput({{ \Illuminate\Support\Js::from($alpineConfig) }})"
        type="text"
        inputmode="{{ $inputMode }}"
        autocomplete="off"
        @disabled($disabled)
        @readonly($readonly)
        @foreach ($handlers as $directive => $expression)
            {{ $directive }}="{{ $expression }}"
        @endforeach
        data-rt-number-input
        {!! $attributes->merge(['class' => $sharedInputClasses]) !!}
    >
@endif
