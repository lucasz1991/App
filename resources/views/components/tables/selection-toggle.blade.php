{{--
    Auswahlschalter fuer Tabellen: Kopfauswahl ("alle auf dieser Seite") und
    Zeilenauswahl teilen sich dieses Bauteil.

    - action: Livewire-Methode (nur Bezeichner).
    - arguments: Argument(e) fuer die Methode, z. B. die Zeilen-ID; ein Wert
      oder eine Liste aus int/float/bool/string.
    - target: weitere Methoden fuer wire:target (Komma-Liste), z. B. die
      Kopfauswahl, damit Zeilenschalter waehrend "alle auswaehlen" sperren.
    - state: 'false' | 'mixed' | 'true' (serverseitiger Stand).
    - checkedExpression: optionaler Alpine-Ausdruck fuer den sofortigen
      Stand vor der Serverantwort (bindet aria-checked und das Haekchen).
    - size: 'md' (Kopf/Werkzeugleiste, 44 px) | 'sm' (Zeile, 36 px).
    - checkboxRole: false fuer reine Aktionsknoepfe ohne role="checkbox".
    Weitere Attribute (id, data-*, x-on:*, wire:loading.*) werden durchgereicht.
--}}
@props([
    'action' => null,
    'arguments' => null,
    'target' => null,
    'label' => __('app.select_all'),
    'state' => 'false',
    'checkedExpression' => null,
    'size' => 'md',
    'disabled' => false,
    'checkboxRole' => true,
])

@php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $action) ? $action : null;
    $call = $safeAction;
    if ($safeAction && $arguments !== null) {
        $call .= '('.collect(\Illuminate\Support\Arr::wrap($arguments))->map(fn ($argument) => match (true) {
            is_bool($argument) => $argument ? 'true' : 'false',
            is_int($argument), is_float($argument) => (string) $argument,
            default => json_encode((string) $argument, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
        })->implode(', ').')';
    }
    $targets = collect([$safeAction, ...explode(',', (string) $target)])
        ->map(fn ($name) => trim((string) $name))
        ->filter(fn ($name) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name))
        ->unique()->implode(',');
    $state = in_array($state, ['false', 'mixed', 'true'], true) ? $state : 'false';
    $disabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || ! $safeAction;
    $checkboxRole = filter_var($checkboxRole, FILTER_VALIDATE_BOOL);
    $checkedExpression = $checkboxRole && is_string($checkedExpression) && trim($checkedExpression) !== '' ? trim($checkedExpression) : null;
    $small = $size === 'sm';
@endphp

<button type="button" @if($checkboxRole) role="checkbox" aria-checked="{{ $state }}" @endif
    @if($checkedExpression) x-bind:aria-checked="({{ $checkedExpression }}) ? 'true' : 'false'" @endif
    aria-label="{{ $label }}" title="{{ $label }}" data-table-selection-toggle data-size="{{ $small ? 'sm' : 'md' }}"
    @if($safeAction) wire:click="{{ $call }}" wire:target="{{ $targets }}" @endif
    wire:loading.class="pointer-events-none" @disabled($disabled)
    {{ $attributes->class([
        'rt-table-select-trigger flex shrink-0 items-center justify-center border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 hover:text-rt-accent focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] disabled:cursor-default disabled:opacity-40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20',
        'h-11 w-11 rounded-xl' => ! $small,
        'h-9 w-9 rounded-lg' => $small,
    ]) }}
>
    <svg xmlns="http://www.w3.org/2000/svg" class="{{ $small ? 'h-4 w-4' : 'h-[18px] w-[18px]' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
        <rect x="4" y="4" width="16" height="16" rx="4" />
        @if($checkedExpression)
            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12 3 3 5-6" x-show="{{ $checkedExpression }}" @if($state !== 'true') style="display: none" @endif />
        @elseif($state === 'true')
            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12 3 3 5-6" />
        @elseif($state === 'mixed')
            <path stroke-linecap="round" d="M8 12h8" />
        @endif
    </svg>
</button>
