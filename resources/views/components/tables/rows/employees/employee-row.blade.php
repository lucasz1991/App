@php
    // Kurzhelfer pro Spaltenindex (kommt vom x-tables.table)
    $hc = fn($i) => $hideClass($columnsMeta[$i]['hideOn'] ?? 'none');

    /** @var \App\Models\User $item */
    $name   = $item->name ?? '—';
    $email  = $item->email ?? '—';
    $team   = $item->currentTeam?->name ?? '—';
    $created= optional($item->created_at)->locale('de')->isoFormat('ll');

    // optional: ausgewählt-Status, falls du ihn mitlieferst
    $isSelected = in_array($item->id, $selectedItems ?? [], true);
@endphp

{{-- 0: Name (mit Auswahl-Kreis wie bei Courses) --}}
<div data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}" class="px-2 py-2 pr-4 {{ $hc(0) }} cursor-pointer" wire:click="$dispatch('toggleEmployeeSelection', [{{ $item->id }}])">
    <div class="grid grid-cols-[auto_1fr] gap-2 items-center">
        <div class="flex items-center">
            <div
                class="w-4 h-4 rounded-full border cursor-pointer transition-all duration-300 ease-rt-spring
                {{ $isSelected ? 'ring-4 ring-rt-red/40 bg-rt-red/10 border-rt-red' : 'border-slate-400 dark:border-slate-500' }}">
            </div>
        </div>

        {{-- WICHTIG: min-w-0 damit truncate greift --}}
        <div class="flex flex-col min-w-0">
            <div class="flex items-center gap-1.5 min-w-0">
                <div class="px-1 font-semibold truncate">
                    {{ $name }}
                </div>
                @if ($item->isOnline())
                    <span class="h-2 w-2 shrink-0 rounded-full bg-green-400 dark:bg-green-500" title="{{ __('app.online') }}"></span>
                @else
                    <span class="h-2 w-2 shrink-0 rounded-full bg-slate-300 dark:bg-slate-600" title="{{ __('app.offline') }}"></span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- 1: E-Mail --}}
<div data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}" class="px-2 py-2 text-rt-muted dark:text-rt-dark-muted truncate {{ $hc(1) }}">
    <a href="mailto:{{ $email }}" class="hover:underline">{{ $email }}</a>
</div>

{{-- 2: Team (Badge) --}}
<div data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}" class="flex items-center px-2 py-2 text-xs {{ $hc(2) }}">
    <div class="flex items-center">
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-rt-surface-muted text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60 mr-2">
            {{ $team }}
        </span>
                                <span title="{{ $item->status ? __('app.active') : __('app.inactive') }}" class="h-4 w-4 rounded-full flex items-center justify-center {{ $item->status ? 'bg-green-400' : 'bg-red-400' }}" >    
                                @if ($item->status)
                                    <!-- SVG für Aktiv (Haken) -->
                                    <svg 
                                        xmlns="http://www.w3.org/2000/svg" 
                                        class="h-3 w-3 text-white" 
                                        fill="none" 
                                        viewBox="0 0 24 24" 
                                        stroke-width="4" 
                                        stroke="currentColor"
                                    >
                                        <path 
                                            stroke-linecap="round" 
                                            stroke-linejoin="round" 
                                            d="M5 13l4 4L19 7" 
                                        />
                                    </svg>
                                @else
                                    <!-- SVG für Inaktiv (X) -->
                                    <svg 
                                        xmlns="http://www.w3.org/2000/svg" 
                                        class="h-3 w-3 text-white" 
                                        fill="none" 
                                        viewBox="0 0 24 24" 
                                        stroke-width="4" 
                                        stroke="currentColor"
                                    >
                                        <path 
                                            stroke-linecap="round" 
                                            stroke-linejoin="round" 
                                            d="M6 18L18 6M6 6l12 12" 
                                        />
                                    </svg>
                                @endif
    
                            </span>
    </div>
</div>

{{-- 3: Erstellt am --}}
<div data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}" class="px-2 py-2 text-rt-muted dark:text-rt-dark-muted {{ $hc(3) }} ">
    <div class="pr-8">
        {{ $created ?? '—' }}
    </div>
</div>

