<button {!! $attributes->merge(['class' => 'rt-ui-button rt-ui-button-secondary transition-all duration-300 ease-rt-spring inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold leading-none text-center border border-rt-border bg-rt-surface text-rt-text shadow-rt-xs hover:bg-rt-surface-muted active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted focus:outline-none focus:ring-2 focus:ring-rt-accent/40 focus:ring-offset-2 dark:focus:ring-offset-rt-dark-canvas disabled:opacity-50 [&>i]:shrink-0 [&>i]:leading-none [&>svg]:h-[1em] [&>svg]:w-[1em] [&>svg]:shrink-0']) !!}
    x-data="{ isClicked: false }" 
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    {{ $slot }}   
</button>
