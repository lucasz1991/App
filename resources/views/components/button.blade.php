<button   {!! $attributes->merge(['class' => 'transition-all duration-300 ease-rt-spring inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-center bg-rt-red text-white shadow-rt-xs hover:bg-rt-red-dark hover:shadow-rt-glow hover:-translate-y-px active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50 waves-effect']) !!} x-data="{ isClicked: false }"
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    {{ $slot }}   
</button>
