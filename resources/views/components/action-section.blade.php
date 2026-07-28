<section {{ $attributes->class('overflow-hidden rounded-2xl border border-rt-border/80 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/80 dark:bg-rt-dark-surface') }}>
    <header class="border-b border-rt-border/70 bg-rt-surface-muted/45 px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/30 sm:px-6 sm:py-5">
        <h3 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
            {{ $title }}
        </h3>
        <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            {{ $description }}
        </p>
    </header>

    <div class="px-5 py-5 sm:px-6 sm:py-6">
        {{ $content }}
    </div>
</section>
