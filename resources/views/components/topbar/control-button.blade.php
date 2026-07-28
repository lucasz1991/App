@props(['type' => 'button'])

<button
    type="{{ $type }}"
    {{ $attributes->class('rt-ui-button rt-ui-button-secondary rt-topbar-control flex h-10 min-w-10 items-center justify-center rounded-xl border border-rt-border bg-rt-surface px-2.5 text-rt-text shadow-rt-xs transition hover:bg-rt-surface-muted hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted dark:hover:text-white') }}
    data-rt-topbar-control
>
    {{ $slot }}
</button>
