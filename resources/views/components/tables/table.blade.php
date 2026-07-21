@props([
    'columns' => [],
    'items' => [],
    'selectedItems' => [],
    'empty' => __('app.no_entries_found'),
    'class' => '',
    // Sort-Status (optional, nur für Pfeil & dir-Berechnung)
    'sortBy' => null,        // z.B. 'title'
    'sortDir' => 'asc',      // 'asc' | 'desc'
    // Optionale Partials für Zeilen und Aktionen
    'rowView' => null,       // z.B. 'components.tables.rows.user-messages.row'
    'actionsView' => null,   // z.B. 'components.tables.rows.user-messages.actions'
    'detailsView' => null,
    'expandedId' => null,
])

@php
    // Normalisiere Columns
    $columns = collect($columns)->map(function ($c) {
        if (is_string($c)) {
            return [
                'label'    => $c,
                'key'      => \Illuminate\Support\Str::slug($c, '_'),
                'width'    => '1fr',
                'sortable' => false,
                'hideOn'   => 'none',
            ];
        }
        $label    = $c['label'] ?? '';
        $key      = $c['key']   ?? $label;
        $width    = $c['width'] ?? '1fr';
        $sortable = (bool)($c['sortable'] ?? false);
        $hideOn   = $c['hideOn'] ?? 'none';

        return compact('label','key','width','sortable','hideOn');
    });

    // Grid-Template nur für md+ (mobil ist gestackt)
    $gridTemplate = $columns->map(fn($c) => $c['width'])->implode(' ');

    // Mapping für hideOn -> Utility-Klassen
    $hideClass = function (string $hideOn) {
        return match ($hideOn) {
            'sm'  => 'hidden sm:block',
            'md'  => 'hidden md:block',
            'lg'  => 'hidden lg:block',
            'xl'  => 'hidden xl:block',
            default => '', // 'none'
        };
    };

    // Pfeil für Sort-Indikator
    $arrowFor = function ($colKey, $sortBy, $sortDir) {
        if ($sortBy !== $colKey) return '';
        return $sortDir === 'asc' ? '▲' : '▼';
    };
@endphp

{{-- Kein overflow-hidden: die Aktions-Dropdowns der Zeilen ragen ueber den
     Rahmen hinaus und wuerden sonst abgeschnitten. Die runden Ecken werden
     stattdessen ueber rounded-t/b auf Kopf- und letzter Zeile gehalten. --}}
<div {{ $attributes->merge(['class' => 'w-full mt-4 relative rounded-xl bg-rt-surface text-rt-text shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 '.$class]) }}>
    {{-- Header (nur md+) --}}
    <div class="hidden md:grid rounded-t-xl bg-rt-surface-muted p-2 pr-16 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted border-b border-rt-border dark:border-rt-dark-border text-left"
         style="grid-template-columns: {{ $gridTemplate }};">
        @foreach($columns as $col)
            @php $hidden = $hideClass($col['hideOn']); @endphp

            @if($col['sortable'])
                <button
                    type="button"
                    class="px-2 py-2 text-left flex items-center gap-1 transition hover:text-rt-red {{ $hidden }}"
                    @click="$dispatch('table-sort', {
                        key: '{{ $col['key'] }}',
                        dir: '{{ ($sortBy == $col['key'] && $sortDir == 'asc') ? 'desc' : 'asc' }}'
                    })"
                >
                    <span>{{ $col['label'] }}</span>
                        <span class="text-[10px] opacity-70">
                            {{ $arrowFor($col['key'], $sortBy, $sortDir) }}
                        </span>
                </button>
            @else
                <div class="px-2 py-2 {{ $hidden }}">{{ $col['label'] }}</div>
            @endif
        @endforeach
    </div>

    {{-- Rows --}}
    @forelse($items as $item)
    @php
        $isSelected = in_array($item->id, (array) $selectedItems);
    @endphp
    <div class="relative border-b border-rt-border/60 px-2 py-2 pr-14 text-sm transition-colors duration-300 ease-rt-spring last:rounded-b-xl last:border-b-0 hover:bg-rt-nav-hover dark:border-rt-dark-border dark:hover:bg-rt-dark-nav-hover md:px-2 md:pr-16">
        <div class="rt-table-row-grid items-center gap-x-2 gap-y-0.5 md:gap-0" style="--rt-table-columns: {{ $gridTemplate }};">
        {{-- Zellen --}}
        @if($rowView)
            @include($rowView, ['item' => $item, 'isSelected' => $isSelected, 'columnsMeta' => $columns, 'hideClass' => $hideClass])
        @else
            @foreach($columns as $col)
            <div class="px-2 py-2 {{ $hideClass($col['hideOn']) }}">—</div>
            @endforeach
        @endif

        </div>

        {{-- Zeilenaktionen bleiben unabhaengig von Anzahl/Breite der Spalten
             immer am rechten Zeilenrand erreichbar. --}}
        @if($actionsView ?? false)
            <div class="rt-table-row-actions absolute right-2 inset-y-0 flex items-center">
                @include($actionsView, ['item' => $item])
            </div>
        @endif
        @if ($detailsView && (int) $expandedId === (int) $item->id)
            @include($detailsView, ['item' => $item])
        @endif
    </div>
    @empty
    <div class="p-4 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $empty }}</div>
    @endforelse

</div>
