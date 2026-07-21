@props([
    'label' => null,
])

<button
    type="button"
    {{ $attributes->class([
        'inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-rt-border bg-rt-surface px-2.5 text-sm font-semibold text-rt-muted shadow-rt-xs transition-all duration-200 hover:border-rt-red/30 hover:bg-rt-surface-muted hover:text-rt-red active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 focus-visible:ring-offset-2 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent dark:focus-visible:ring-offset-rt-dark-surface',
        'w-9' => blank($label),
    ]) }}
    aria-label="{{ $label ?: __('app.actions') }}"
    aria-haspopup="menu"
>
    <i class="far fa-ellipsis-h shrink-0 leading-none" aria-hidden="true"></i>
    @if (filled($label))
        <span>{{ $label }}</span>
    @endif
</button>
