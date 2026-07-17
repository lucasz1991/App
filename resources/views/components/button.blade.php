<button   {!! $attributes->merge(['class' => 'transition-all duration-100 inline-flex items-center justify-center px-5 py-3 text-base font-medium text-center text-gray-900 dark:text-slate-100 border border-gray-300 dark:border-slate-600 rounded-lg bg-gray-50 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 focus:ring-4 focus:ring-gray-100 dark:focus:ring-slate-700  waves-effect']) !!} x-data="{ isClicked: false }" 
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    {{ $slot }}   
</button>
