<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 bg-red-600 border border-transparent text-sm font-semibold text-white hover:bg-red-700 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50 transition']) }}>
    {{ $slot }}
</button>
