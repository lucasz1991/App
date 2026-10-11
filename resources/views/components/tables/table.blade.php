@props([
    'columns' => [],
    'items' => [],
    'selectedItems' => [],
    'empty' => __('app.no_entries_found'),
    'class' => '',
    // Sort-Status (optional, nur für Pfeil & dir-Berechnung)
    'sortBy' => null,        // z.B. 'title'
    'sortDir' => 'asc',      // 'asc' | 'desc'
    'sortAction' => null,
    'tableKey' => null,
    'label' => 'Ergebnisliste',
    // Optionale Partials für Zeilen und Aktionen
    'rowView' => null,       // z.B. 'components.tables.rows.user-messages.row'
    'actionsView' => null,   // z.B. 'components.tables.rows.user-messages.actions'
    'detailsView' => null,
    'expandedId' => null,
    // Einheitliche Zeileninteraktion: Einfachklick = Auswahl,
    // Doppelklick = vorhandene Detailansicht bzw. Detailroute.
    'selectionAction' => null,
    'detailAction' => null,
    'detailEvent' => null,
    'detailRoute' => null,
    // Optional fuer Listen mit einem separaten, direkt angrenzenden Mobile-Header.
    'flushTop' => false,
    // Opt-in: nur der Zeilenbereich scrollt innerhalb einer begrenzten Hoehe.
    'scrollBody' => false,
    // Opt-in fuer kompakte mobile Zeilen: echte Spaltenkoepfe statt Sortiermenue.
    'mobileHeader' => false,
    // Global fuer numerisch paginierte Listen; lokale Ausnahme bleibt moeglich.
    'scrollPagination' => true,
])

@php
    $flushTop = filter_var($flushTop, FILTER_VALIDATE_BOOL);
    $scrollBody = filter_var($scrollBody, FILTER_VALIDATE_BOOL);
    $mobileHeader = filter_var($mobileHeader, FILTER_VALIDATE_BOOL);
    $scrollPagination = filter_var($scrollPagination, FILTER_VALIDATE_BOOL)
        && $items instanceof \Illuminate\Contracts\Pagination\Paginator;

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

    $safeSortAction = is_string($sortAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sortAction) ? $sortAction : null;
    $sortableColumns = $columns->where('sortable', true);
    $activeSortLabel = $sortableColumns->firstWhere('key', $sortBy)['label'] ?? 'Standardsortierung';
    $sortPayload = fn ($col) => ['key' => $col['key'], 'dir' => $sortBy === $col['key'] && $sortDir === 'asc' ? 'desc' : 'asc'];
    $sortExpression = fn ($col) => $safeSortAction.'('.json_encode($sortPayload($col)['key']).', '.json_encode($sortPayload($col)['dir']).')';
@endphp

{{-- Kein overflow-hidden: die Aktions-Dropdowns der Zeilen ragen ueber den
     Rahmen hinaus und wuerden sonst abgeschnitten. Die runden Ecken werden
     stattdessen ueber rounded-t/b auf Kopf- und letzter Zeile gehalten. --}}
<div
    {{ $attributes->merge(['class' => 'rt-ui-surface rt-ui-table rt-table-frame relative w-full min-w-0 max-w-full rounded-xl bg-rt-surface text-rt-text shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 '.($flushTop ? '' : 'mt-4 ').$class]) }}
    data-rt-premium-table
    @if($scrollPagination)
        x-data="rtListScrollPagination"
        data-list-scroll-pagination
        data-list-page="{{ $items->currentPage() }}"
        data-list-page-name="{{ $items->getPageName() }}"
        data-list-has-next="{{ $items->hasMorePages() ? 'true' : 'false' }}"
        data-list-has-previous="{{ $items->onFirstPage() ? 'false' : 'true' }}"
        wire:loading.attr="data-list-pagination-busy"
        x-bind:aria-busy="pending ? 'true' : 'false'"
    @endif
    @if($scrollBody) data-table-scroll="body" @endif
    @if($mobileHeader) data-table-mobile-header @endif
    data-table-sortable="{{ $sortableColumns->isNotEmpty() ? 'true' : 'false' }}"
>
    @if($sortableColumns->isNotEmpty() && ! $mobileHeader)
        <div class="rt-table-mobile-sort md:hidden">
            <x-ui.dropdown.anchor-dropdown align="right" width="64">
                <x-slot:trigger>
                    <button type="button" class="rt-table-mobile-sort__trigger" aria-label="Sortierung: {{ $activeSortLabel }}, {{ $sortDir === 'desc' ? 'absteigend' : 'aufsteigend' }}. Sortierung ändern">
                        <i class="far fa-sort-amount-down" aria-hidden="true"></i>
                        <span>{{ $sortableColumns->firstWhere('key', $sortBy)['label'] ?? 'Sortieren' }}</span>
                        <i class="far {{ $sortDir === 'desc' ? 'fa-arrow-down' : 'fa-arrow-up' }}" aria-hidden="true"></i>
                    </button>
                </x-slot:trigger>
                <x-slot:content>
                    @foreach($sortableColumns as $col)
                        <button type="button" role="menuitemradio" aria-checked="{{ $sortBy === $col['key'] ? 'true' : 'false' }}" class="rt-table-mobile-sort__option"
                            @if($safeSortAction) wire:click="{{ $sortExpression($col) }}" @click="open = false"
                            @else @click="$dispatch('table-sort', {{ \Illuminate\Support\Js::from($sortPayload($col)) }}); open = false" @endif
                            aria-label="{{ $col['label'] }} {{ $sortPayload($col)['dir'] === 'asc' ? 'aufsteigend' : 'absteigend' }} sortieren">
                            <span>{{ $col['label'] }}</span>
                            <i class="far {{ $sortBy === $col['key'] ? ($sortDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort-alt' }}" aria-hidden="true"></i>
                        </button>
                    @endforeach
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
        </div>
    @endif
    <div class="rt-table-core" role="table" aria-label="{{ $label }}">
    {{-- Mobile Kopfzeilen respektieren dieselben hideOn-Spalten wie die Zeilen. --}}
    <div class="rt-ui-surface-muted rt-table-head {{ $mobileHeader ? 'grid' : 'hidden' }} md:grid bg-rt-surface-muted p-2 pr-16 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted border-b border-rt-border dark:border-rt-dark-border text-left"
         role="row" style="grid-template-columns: {{ $mobileHeader ? 'var(--rt-table-header-columns, '.$gridTemplate.')' : $gridTemplate }};">
        @foreach($columns as $col)
            @php $hidden = $hideClass($col['hideOn']); @endphp

            @if($col['sortable'])
                <div role="columnheader" class="min-w-0 {{ $hidden }}" aria-sort="{{ $sortBy === $col['key'] ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                <button
                    type="button"
                    class="rt-table-head-cell rt-table-sort-control flex w-full items-center justify-between gap-2 rounded-lg px-2 py-2 text-left transition hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40"
                    data-sort-key="{{ $col['key'] }}"
                    aria-label="{{ $col['label'] }} {{ $sortPayload($col)['dir'] === 'asc' ? 'aufsteigend' : 'absteigend' }} sortieren"
                    title="{{ $col['label'] }} sortieren"
                    @if($safeSortAction) wire:click="{{ $sortExpression($col) }}" wire:loading.attr="disabled" wire:target="{{ $safeSortAction }}"
                    @else @click="$dispatch('table-sort', {{ \Illuminate\Support\Js::from($sortPayload($col)) }})" @endif
                >
                    <span>{{ $col['label'] }}</span>
                    <i class="far {{ $sortBy === $col['key'] ? ($sortDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort-alt' }} shrink-0 text-[10px] opacity-70" aria-hidden="true"></i>
                </button>
                </div>
            @else
                <div role="columnheader" class="rt-table-head-cell px-2 py-2 {{ $hidden }}">{{ $col['label'] }}</div>
            @endif
        @endforeach
    </div>

    {{-- Rows --}}
    <div class="rt-table-body" role="rowgroup" @if($scrollBody) data-table-scroll-body tabindex="0" aria-label="{{ $label }} – scrollbarer Inhalt" @endif>
    @if($scrollPagination)
        <div role="presentation" data-list-pagination-content wire:ignore.self x-bind:inert="pending">
    @endif
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
        $safeDetailEvent = is_string($detailEvent) && preg_match('/^[a-z][a-z0-9-]*$/', $detailEvent)
            ? $detailEvent
            : null;
    @endphp
    <div
        @if($tableKey) wire:key="{{ $tableKey }}-row-{{ $rowId }}" @endif
        class="rt-table-row {{ $isSelected ? 'rt-table-row-selected' : '' }} relative cursor-pointer border-b border-rt-border/60 px-3 py-3 pr-14 text-sm transition-colors duration-300 ease-rt-spring last:rounded-b-xl last:border-b-0 hover:bg-rt-nav-hover focus-visible:z-[1] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red/50 dark:border-rt-dark-border dark:hover:bg-rt-dark-nav-hover md:px-2 md:py-2 md:pr-16"
        role="row"
        tabindex="0"
        data-table-row-interactive="true"
        data-detail-available="{{ ($rowDetailUrl || $safeDetailAction || $safeDetailEvent) ? 'true' : 'false' }}"
        data-selected="{{ $isSelected ? 'true' : 'false' }}"
        aria-selected="{{ $isSelected ? 'true' : 'false' }}"
        x-data="{
            clickTimer: null,
            rowId: {{ $rowId }},
            selectionAction: @js($safeSelectionAction),
            detailAction: @js($safeDetailAction),
            detailEvent: @js($safeDetailEvent),
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
                if (this.isControl(event) || (!this.detailUrl && !this.detailAction && !this.detailEvent)) return;
                window.clearTimeout(this.clickTimer);

                if (this.detailEvent) {
                    this.$dispatch(this.detailEvent, { id: this.rowId });
                    return;
                }

                if (this.detailUrl) {
                    window.location.assign(this.detailUrl);
                    return;
                }

                this.$wire.call(this.detailAction, this.rowId);
            },
            handleKeyboard(event) {
                if (this.isControl(event)) return;
                if ((event.ctrlKey || event.metaKey) && (this.detailUrl || this.detailAction || this.detailEvent)) {
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
        <div role="presentation" class="rt-table-row-grid content-start items-center gap-1.5 sm:gap-x-3 md:gap-0" style="--rt-table-columns: {{ $gridTemplate }};">
        {{-- Zellen --}}
        @if($rowView)
            @include($rowView, ['item' => $item, 'isSelected' => $isSelected, 'selectedItems' => $selectedItems, 'columnsMeta' => $columns, 'hideClass' => $hideClass])
        @else
            @foreach($columns as $col)
            <div role="cell" class="px-2 py-2 {{ $hideClass($col['hideOn']) }}">—</div>
            @endforeach
        @endif

        </div>

        {{-- Zeilenaktionen bleiben unabhaengig von Anzahl/Breite der Spalten
             immer am rechten Zeilenrand erreichbar. --}}
        @if($actionsView ?? false)
            <div class="rt-table-row-actions absolute right-3 top-3 z-10 flex items-center md:inset-y-0 md:right-2 md:z-auto">
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
    <div role="row"><div role="cell" class="rt-table-empty p-4 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $empty }}</div></div>
    @endforelse
    @if($scrollPagination)
        </div>
    @endif
    </div>
    </div>
    @if($scrollPagination)
        <template x-teleport="body">
            <div x-cloak x-show="loading" x-bind:style="loadingStyle"
                class="rt-list-pagination-loading" data-list-pagination-loading
                role="status" aria-live="polite" aria-atomic="true">
                <svg class="h-4 w-4 shrink-0 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
                    <path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span class="sr-only" x-text="loadingDirection > 0 ? 'Nächste Seite wird geladen …' : 'Vorherige Seite wird geladen …'"></span>
            </div>
        </template>
    @endif
</div>
