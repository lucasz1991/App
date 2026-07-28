@props(['submit'])

<section
    {{ $attributes->class('relative overflow-hidden rounded-2xl border border-rt-border/80 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/80 dark:bg-rt-dark-surface') }}
    data-autosave-scope
>
    <header class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-rt-border/70 bg-rt-surface-muted/45 px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/30 sm:gap-5 sm:px-6 sm:py-5">
        <div class="min-w-0">
            <h3 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                {{ $title }}
            </h3>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                {{ $description }}
            </p>
        </div>

        @if (isset($actions))
            <div class="flex min-h-7 shrink-0 items-center justify-end gap-2">
                {{ $actions }}
            </div>
        @endif
    </header>

    <form wire:submit="{{ $submit }}" class="px-5 py-5 sm:px-6 sm:py-6">
        <div class="grid grid-cols-6 gap-5 sm:gap-6">
            {{ $form }}
        </div>
    </form>
</section>
