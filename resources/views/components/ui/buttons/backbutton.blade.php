<button   {!! $attributes->merge(['class' => 'transition-all duration-300 ease-rt-spring inline-flex items-center justify-center bg-rt-surface dark:bg-rt-dark-surface p-4 text-base font-medium text-center text-rt-text dark:text-rt-dark-text border border-rt-border dark:border-rt-dark-border shadow-rt-xs rounded-full aspect-square hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900 ']) !!} x-data="{ isClicked: false }"
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    <svg class="transform rotate-180 text-slate-500 fill-slate-500 dark:text-slate-400 dark:fill-slate-400 h-6 aspect-square" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 648.63399 645.8089"><polygon fill="currentColor" points="391.537 0 0 0 257.097 322.904 0 645.809 391.537 645.809 648.634 322.904 391.537 0"/></svg>
</button>
