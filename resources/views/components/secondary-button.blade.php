<button {!! $attributes->merge(['class' => 'transition-all duration-100 inline-flex items-center justify-center px-5 py-3 text-base font-medium text-center text-gray-700 dark:text-slate-300 border border-gray-300 dark:border-slate-600 rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 focus:ring-4 focus:ring-gray-200 dark:focus:ring-slate-700']) !!} 
    x-data="{ isClicked: false }" 
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    {{ $slot }}   
</button>
