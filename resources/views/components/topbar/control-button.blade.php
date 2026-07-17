@props(['type' => 'button'])

<button
    type="{{ $type }}"
    {{ $attributes->class('flex h-9 items-center justify-center rounded-lg border border-rt-border bg-rt-surface px-2 text-rt-text shadow-sm transition hover:bg-rt-surface-muted hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted dark:hover:text-white') }}
>
    {{ $slot }}
</button>
