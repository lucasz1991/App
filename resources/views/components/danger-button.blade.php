<button {{ $attributes->merge(['type' => 'button', 'class' => 'rt-ui-button rt-ui-button-danger inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 bg-red-600 border border-transparent text-sm font-semibold text-white dark:text-white shadow-rt-xs hover:bg-red-700 hover:-translate-y-px active:bg-red-700 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-red-500/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50 transition-all duration-300 ease-rt-spring']) }}>
    {{ $slot }}
</button>
