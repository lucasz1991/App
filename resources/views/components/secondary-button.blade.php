<button {!! $attributes->merge(['class' => 'transition-all duration-100 inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-center bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600 dark:hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50']) !!} 
    x-data="{ isClicked: false }" 
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    {{ $slot }}   
</button>
