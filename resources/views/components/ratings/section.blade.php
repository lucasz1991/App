@props(['title'])

<div class="rt-ui-surface-muted space-y-2 rounded border border-rt-border bg-rt-surface-muted p-4 text-rt-text shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-text">
    <h2 class="mb-2 text-lg font-semibold text-rt-text dark:text-rt-dark-text">{{ $title }}</h2>
    {{ $slot }}
</div>
