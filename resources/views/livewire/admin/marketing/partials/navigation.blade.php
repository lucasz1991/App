@props(['active'])

<nav class="flex gap-2 overflow-x-auto pb-1" aria-label="Marketing-Bereiche" data-marketing-navigation>
    <a
        href="{{ route('admin.marketing.creatives.index') }}"
        wire:navigate
        @class([
            'flex min-h-11 shrink-0 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15',
            'border-rt-red bg-rose-50 text-rt-red dark:bg-rose-500/10 dark:text-rose-300' => $active === 'creatives',
            'border-rt-border bg-rt-surface text-rt-muted hover:border-rt-red/30 hover:text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:text-white' => $active !== 'creatives',
        ])
        @if ($active === 'creatives') aria-current="page" @endif
    >
        <i data-feather="layout" class="h-4 w-4" aria-hidden="true"></i>
        Motive
    </a>

    <a
        href="{{ route('admin.marketing.assets.index') }}"
        wire:navigate
        @class([
            'flex min-h-11 shrink-0 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15',
            'border-rt-red bg-rose-50 text-rt-red dark:bg-rose-500/10 dark:text-rose-300' => $active === 'assets',
            'border-rt-border bg-rt-surface text-rt-muted hover:border-rt-red/30 hover:text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:text-white' => $active !== 'assets',
        ])
        @if ($active === 'assets') aria-current="page" @endif
    >
        <i data-feather="image" class="h-4 w-4" aria-hidden="true"></i>
        Medien
    </a>
</nav>
