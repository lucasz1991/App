@props([
    'action' => null,
    'label' => __('app.select_all'),
    'state' => 'false',
    'disabled' => false,
    'checkboxRole' => true,
])

@php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $action) ? $action : null;
    $state = in_array($state, ['false', 'mixed', 'true'], true) ? $state : 'false';
    $disabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || ! $safeAction;
    $checkboxRole = filter_var($checkboxRole, FILTER_VALIDATE_BOOL);
@endphp

<button type="button" @if($checkboxRole) role="checkbox" aria-checked="{{ $state }}" @endif
    aria-label="{{ $label }}" title="{{ $label }}" data-table-selection-toggle
    @if($safeAction) wire:click="{{ $safeAction }}" wire:target="{{ $safeAction }}" @endif
    wire:loading.class="pointer-events-none" @disabled($disabled)
    {{ $attributes->class(['rt-table-select-trigger flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 hover:text-rt-accent focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] disabled:cursor-default disabled:opacity-40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20']) }}
>
    <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
        <rect x="4" y="4" width="16" height="16" rx="4" />
        @if($state === 'true')
            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12 3 3 5-6" />
        @elseif($state === 'mixed')
            <path stroke-linecap="round" d="M8 12h8" />
        @endif
    </svg>
</button>
