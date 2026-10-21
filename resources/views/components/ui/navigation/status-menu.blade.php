@props([
    'options' => [],
    'counts' => [],
    'value' => null,
    'action' => null,
    'label' => 'Bearbeitungsstand',
    'disabled' => false,
])

@php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) ? $action : null;
    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || $safeAction === null;
@endphp

{{-- A status filter changes one list, rather than switching tab panels. --}}
<nav {{ $attributes->class('rt-status-menu') }} aria-label="{{ $label }}"
    @if($safeAction) wire:loading.attr="aria-busy" wire:target="{{ $safeAction }}" @endif>
    @foreach($options as $key => $optionLabel)
        <button type="button" class="rt-status-menu__item"
            data-status-value="{{ $key }}"
            aria-pressed="{{ (string) $value === (string) $key ? 'true' : 'false' }}"
            @disabled($isDisabled)
            @if(! $isDisabled)
                wire:click="{{ $safeAction }}({{ \Illuminate\Support\Js::from((string) $key) }})"
                wire:loading.attr="disabled" wire:target="{{ $safeAction }}"
            @endif>
            <span>{{ $optionLabel }}</span>
            @if(isset($counts[$key]))
                <span class="rt-status-menu__count">{{ $counts[$key] }}</span>
            @endif
        </button>
    @endforeach
</nav>
