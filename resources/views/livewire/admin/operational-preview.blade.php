@section('title', $moduleData['title'])

<x-ui.page
    :title="$moduleData['title']"
    eyebrow="Einsatzplanung"
    :description="$moduleData['description']"
    :auto-intro="false"
    content-class="space-y-5"
    data-operational-module="{{ $module }}"
>
    <nav
        class="flex gap-2 overflow-x-auto pb-1 lg:grid lg:grid-cols-4 lg:overflow-visible"
        aria-label="Bereiche der Einsatzplanung"
        data-operations-navigation
    >
        @foreach ($allModules as $slug => $navigationModule)
            <a
                href="{{ route('admin.operations.preview', ['module' => $slug]) }}"
                wire:navigate
                wire:key="operations-nav-{{ $slug }}"
                @class([
                    'group flex min-h-11 min-w-[9.5rem] items-center gap-3 rounded-xl border px-3.5 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 lg:min-w-0',
                    'border-rt-red bg-rose-50 text-rt-red shadow-rt-xs dark:bg-rose-500/10 dark:text-rose-300' => $slug === $module,
                    'border-rt-border/70 bg-rt-surface text-rt-muted hover:border-rt-red/30 hover:text-rt-text dark:border-rt-dark-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:text-white' => $slug !== $module,
                ])
                @if ($slug === $module) aria-current="page" @endif
            >
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition',
                    'bg-rt-red text-white' => $slug === $module,
                    'bg-rt-surface-muted text-rt-soft group-hover:text-rt-red dark:bg-rt-dark-surface-muted dark:text-rt-dark-soft' => $slug !== $module,
                ])>
                    <i data-feather="{{ $navigationModule['icon'] }}" class="h-4 w-4" aria-hidden="true"></i>
                </span>
                <span class="truncate">{{ $navigationModule['title'] }}</span>
            </a>
        @endforeach
    </nav>

    <div wire:key="operations-workspace-{{ $module }}" data-operations-workspace>
        @switch($module)
            @case('orders')
                <livewire:admin.operations.orders :key="'operations-orders'" />
                @break
            @case('shift-management')
                <livewire:admin.operations.shift-management :key="'operations-shift-management'" />
                @break
            @case('calendar')
                <livewire:admin.operations.calendar :key="'operations-calendar'" />
                @break
            @case('customers')
                <livewire:admin.operations.customers :key="'operations-customers'" />
                @break
        @endswitch
    </div>
</x-ui.page>
