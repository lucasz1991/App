@props(['submit'])

<section
    {{ $attributes->class('relative overflow-hidden rounded-2xl border border-rt-border/80 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/80 dark:bg-rt-dark-surface') }}
    data-autosave-scope
>
    @if (isset($actions))
        <div class="absolute right-4 top-4 z-10 flex min-h-8 items-center justify-end gap-2 sm:right-5 sm:top-5">
            {{ $actions }}
        </div>
    @endif

    <header @class([
        'border-b border-rt-border/70 bg-rt-surface-muted/45 px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/30 sm:px-6 sm:py-5',
        'pr-32 sm:pr-44' => isset($actions),
    ])>
        <h3 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
            {{ $title }}
        </h3>
        <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            {{ $description }}
        </p>
    </header>

    <form wire:submit="{{ $submit }}" class="px-5 py-5 sm:px-6 sm:py-6">
        <div class="grid grid-cols-6 gap-5 sm:gap-6">
            {{ $form }}
        </div>
    </form>
</section>
