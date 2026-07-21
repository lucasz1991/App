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
    // Einheitliche Zeileninteraktion: Einfachklick = Auswahl,
    // Doppelklick = vorhandene Detailansicht bzw. Detailroute.
    'selectionAction' => null,
    'detailAction' => null,
    'detailRoute' => null,
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
<div {{ $attributes->merge(['class' => 'rt-ui-surface rt-ui-table relative mt-4 w-full min-w-0 max-w-full rounded-xl bg-rt-surface text-rt-text shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 '.$class]) }}>
    {{-- Header (nur md+) --}}
    <div class="rt-ui-surface-muted hidden md:grid rounded-t-xl bg-rt-surface-muted p-2 pr-16 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted border-b border-rt-border dark:border-rt-dark-border text-left"
         style="grid-template-columns: {{ $gridTemplate }};">
        @foreach($columns as $col)
            @php $hidden = $hideClass($col['hideOn']); @endphp

            @if($col['sortable'])
                <button
                    type="button"
                    aria-sort="{{ $sortBy === $col['key'] ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}"
                    class="flex items-center gap-1 rounded-lg px-2 py-2 text-left transition hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40 {{ $hidden }}"
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
        $rowId = (int) $item->id;
        $rowDetailUrl = $detailRoute ? route($detailRoute, $rowId) : null;
        $safeSelectionAction = is_string($selectionAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $selectionAction)
            ? $selectionAction
            : null;
        $safeDetailAction = is_string($detailAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $detailAction)
            ? $detailAction
            : null;
    @endphp
    <div
        class="rt-table-row {{ $isSelected ? 'rt-table-row-selected' : '' }} relative cursor-pointer border-b border-rt-border/60 px-3 py-3 pr-14 text-sm transition-colors duration-300 ease-rt-spring last:rounded-b-xl last:border-b-0 hover:bg-rt-nav-hover focus-visible:z-[1] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red/50 dark:border-rt-dark-border dark:hover:bg-rt-dark-nav-hover md:px-2 md:py-2 md:pr-16"
        role="row"
        tabindex="0"
        data-table-row-interactive="true"
        data-detail-available="{{ ($rowDetailUrl || $safeDetailAction) ? 'true' : 'false' }}"
        data-selected="{{ $isSelected ? 'true' : 'false' }}"
        aria-selected="{{ $isSelected ? 'true' : 'false' }}"
        x-data="{
            clickTimer: null,
            rowId: {{ $rowId }},
            selectionAction: @js($safeSelectionAction),
            detailAction: @js($safeDetailAction),
            detailUrl: @js($rowDetailUrl),
            isControl(event) {
                return event.target instanceof Element
                    && event.target.closest('a, button, input, select, textarea, label, [role=button], [data-table-row-ignore]');
            },
            toggleSelection() {
                if (this.selectionAction) {
                    this.$wire.call(this.selectionAction, this.rowId);
                }
            },
            queueSelection(event) {
                if (this.isControl(event)) return;
                window.clearTimeout(this.clickTimer);
                this.clickTimer = window.setTimeout(() => this.toggleSelection(), 220);
            },
            openDetails(event) {
                if (this.isControl(event) || (!this.detailUrl && !this.detailAction)) return;
                window.clearTimeout(this.clickTimer);

                if (this.detailUrl) {
                    window.location.assign(this.detailUrl);
                    return;
                }

                this.$wire.call(this.detailAction, this.rowId);
            },
            handleKeyboard(event) {
                if (this.isControl(event)) return;
                if ((event.ctrlKey || event.metaKey) && (this.detailUrl || this.detailAction)) {
                    this.openDetails(event);
                    return;
                }
                this.toggleSelection();
            },
            destroy() {
                window.clearTimeout(this.clickTimer);
            }
        }"
        x-on:click="queueSelection($event)"
        x-on:dblclick.prevent="openDetails($event)"
        x-on:keydown.enter.prevent="handleKeyboard($event)"
    >
        <div class="rt-table-row-grid content-start items-center gap-1.5 sm:gap-x-3 md:gap-0" style="--rt-table-columns: {{ $gridTemplate }};">
        {{-- Zellen --}}
        @if($rowView)
            @include($rowView, ['item' => $item, 'isSelected' => $isSelected, 'selectedItems' => $selectedItems, 'columnsMeta' => $columns, 'hideClass' => $hideClass])
        @else
            @foreach($columns as $col)
            <div class="px-2 py-2 {{ $hideClass($col['hideOn']) }}">—</div>
            @endforeach
        @endif

        </div>

        {{-- Zeilenaktionen bleiben unabhaengig von Anzahl/Breite der Spalten
             immer am rechten Zeilenrand erreichbar. --}}
        @if($actionsView ?? false)
            <div class="rt-table-row-actions absolute right-3 top-3 z-10 flex items-center md:inset-y-0 md:right-2 md:top-auto md:z-auto">
                @include($actionsView, ['item' => $item])
            </div>
        @endif
        @if ($detailsView && (int) $expandedId === (int) $item->id)
            <div class="rt-table-row-details -mx-3 -mb-3 mt-3 md:-mx-2 md:-mb-2 md:mt-2">
                @include($detailsView, ['item' => $item])
            </div>
        @endif
    </div>
    @empty
    <div class="p-4 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $empty }}</div>
    @endforelse

</div>
