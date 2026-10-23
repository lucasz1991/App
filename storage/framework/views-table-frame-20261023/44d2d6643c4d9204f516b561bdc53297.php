<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
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
    // Opt-in: ein Klick oeffnet Details statt der sonstigen Zeilenauswahl.
    'detailOnClick' => false,
    // Optional fuer Listen mit einem separaten, direkt angrenzenden Mobile-Header.
    'flushTop' => false,
    // Opt-in: nur der Zeilenbereich scrollt innerhalb einer begrenzten Hoehe.
    'scrollBody' => false,
    // Opt-in fuer kompakte mobile Zeilen: echte Spaltenkoepfe statt Sortiermenue.
    'mobileHeader' => false,
    // Global fuer numerisch paginierte Listen; lokale Ausnahme bleibt moeglich.
    'scrollPagination' => true,
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
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
    // Opt-in: ein Klick oeffnet Details statt der sonstigen Zeilenauswahl.
    'detailOnClick' => false,
    // Optional fuer Listen mit einem separaten, direkt angrenzenden Mobile-Header.
    'flushTop' => false,
    // Opt-in: nur der Zeilenbereich scrollt innerhalb einer begrenzten Hoehe.
    'scrollBody' => false,
    // Opt-in fuer kompakte mobile Zeilen: echte Spaltenkoepfe statt Sortiermenue.
    'mobileHeader' => false,
    // Global fuer numerisch paginierte Listen; lokale Ausnahme bleibt moeglich.
    'scrollPagination' => true,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $flushTop = filter_var($flushTop, FILTER_VALIDATE_BOOL);
    $scrollBody = filter_var($scrollBody, FILTER_VALIDATE_BOOL);
    $mobileHeader = filter_var($mobileHeader, FILTER_VALIDATE_BOOL);
    $detailOnClick = filter_var($detailOnClick, FILTER_VALIDATE_BOOL);
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
?>


<div
    <?php echo e($attributes->merge(['class' => 'rt-ui-surface rt-ui-table rt-table-frame relative w-full min-w-0 max-w-full rounded-xl bg-rt-surface text-rt-text shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 '.($flushTop ? '' : 'mt-4 ').$class])); ?>

    data-rt-premium-table
    <?php if($scrollPagination): ?>
        x-data="rtListScrollPagination"
        data-list-scroll-pagination
        data-list-page="<?php echo e($items->currentPage()); ?>"
        data-list-page-name="<?php echo e($items->getPageName()); ?>"
        data-list-has-next="<?php echo e($items->hasMorePages() ? 'true' : 'false'); ?>"
        data-list-has-previous="<?php echo e($items->onFirstPage() ? 'false' : 'true'); ?>"
        wire:loading.attr="data-list-pagination-busy"
        x-bind:aria-busy="pending ? 'true' : 'false'"
    <?php endif; ?>
    <?php if($scrollBody): ?> data-table-scroll="body" <?php endif; ?>
    <?php if($mobileHeader): ?> data-table-mobile-header <?php endif; ?>
    data-table-sortable="<?php echo e($sortableColumns->isNotEmpty() ? 'true' : 'false'); ?>"
>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sortableColumns->isNotEmpty() && ! $mobileHeader): ?>
        <div class="rt-table-mobile-sort md:hidden">
            <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'right','width' => '64']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'right','width' => '64']); ?>
                 <?php $__env->slot('trigger', null, []); ?> 
                    <button type="button" class="rt-table-mobile-sort__trigger" aria-label="Sortierung: <?php echo e($activeSortLabel); ?>, <?php echo e($sortDir === 'desc' ? 'absteigend' : 'aufsteigend'); ?>. Sortierung ändern">
                        <i class="far fa-sort-amount-down" aria-hidden="true"></i>
                        <span><?php echo e($sortableColumns->firstWhere('key', $sortBy)['label'] ?? 'Sortieren'); ?></span>
                        <i class="far <?php echo e($sortDir === 'desc' ? 'fa-arrow-down' : 'fa-arrow-up'); ?>" aria-hidden="true"></i>
                    </button>
                 <?php $__env->endSlot(); ?>
                 <?php $__env->slot('content', null, []); ?> 
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $sortableColumns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <button type="button" role="menuitemradio" aria-checked="<?php echo e($sortBy === $col['key'] ? 'true' : 'false'); ?>" class="rt-table-mobile-sort__option"
                            <?php if($safeSortAction): ?> wire:click="<?php echo e($sortExpression($col)); ?>" @click="open = false"
                            <?php else: ?> @click="$dispatch('table-sort', <?php echo e(\Illuminate\Support\Js::from($sortPayload($col))); ?>); open = false" <?php endif; ?>
                            aria-label="<?php echo e($col['label']); ?> <?php echo e($sortPayload($col)['dir'] === 'asc' ? 'aufsteigend' : 'absteigend'); ?> sortieren">
                            <span><?php echo e($col['label']); ?></span>
                            <i class="far <?php echo e($sortBy === $col['key'] ? ($sortDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort-alt'); ?>" aria-hidden="true"></i>
                        </button>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                 <?php $__env->endSlot(); ?>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $attributes = $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $component = $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div class="rt-table-core" role="table" aria-label="<?php echo e($label); ?>">
    
    <div class="rt-ui-surface-muted rt-table-head <?php echo e($mobileHeader ? 'grid' : 'hidden'); ?> md:grid bg-rt-surface-muted p-2 pr-16 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted border-b border-rt-border dark:border-rt-dark-border text-left"
         role="row" style="grid-template-columns: <?php echo e($mobileHeader ? 'var(--rt-table-header-columns, '.$gridTemplate.')' : $gridTemplate); ?>;">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $columns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php $hidden = $hideClass($col['hideOn']); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($col['sortable']): ?>
                <div role="columnheader" class="min-w-0 <?php echo e($hidden); ?>" aria-sort="<?php echo e($sortBy === $col['key'] ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none'); ?>">
                <button
                    type="button"
                    class="rt-table-head-cell rt-table-sort-control flex w-full items-center justify-between gap-2 rounded-lg px-2 py-2 text-left transition hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40"
                    data-sort-key="<?php echo e($col['key']); ?>"
                    aria-label="<?php echo e($col['label']); ?> <?php echo e($sortPayload($col)['dir'] === 'asc' ? 'aufsteigend' : 'absteigend'); ?> sortieren"
                    title="<?php echo e($col['label']); ?> sortieren"
                    <?php if($safeSortAction): ?> wire:click="<?php echo e($sortExpression($col)); ?>" wire:loading.attr="disabled" wire:target="<?php echo e($safeSortAction); ?>"
                    <?php else: ?> @click="$dispatch('table-sort', <?php echo e(\Illuminate\Support\Js::from($sortPayload($col))); ?>)" <?php endif; ?>
                >
                    <span><?php echo e($col['label']); ?></span>
                    <i class="far <?php echo e($sortBy === $col['key'] ? ($sortDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort-alt'); ?> shrink-0 text-[10px] opacity-70" aria-hidden="true"></i>
                </button>
                </div>
            <?php else: ?>
                <div role="columnheader" class="rt-table-head-cell px-2 py-2 <?php echo e($hidden); ?>"><?php echo e($col['label']); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    
    <div class="rt-table-body" role="rowgroup" <?php if($scrollBody): ?> data-table-scroll-body tabindex="0" aria-label="<?php echo e($label); ?> – scrollbarer Inhalt" <?php endif; ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($scrollPagination): ?>
        <div role="presentation" data-list-pagination-content wire:ignore.self x-bind:inert="pending">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
    <?php
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
    ?>
    <div
        <?php if($tableKey): ?> wire:key="<?php echo e($tableKey); ?>-row-<?php echo e($rowId); ?>" <?php endif; ?>
        class="rt-table-row <?php echo e($isSelected ? 'rt-table-row-selected' : ''); ?> relative cursor-pointer border-b border-rt-border/60 px-3 py-3 pr-14 text-sm transition-colors duration-300 ease-rt-spring last:rounded-b-xl last:border-b-0 hover:bg-rt-nav-hover focus-visible:z-[1] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red/50 dark:border-rt-dark-border dark:hover:bg-rt-dark-nav-hover md:px-2 md:py-2 md:pr-16"
        role="row"
        tabindex="0"
        data-table-row-interactive="true"
        data-table-row-click="<?php echo e($detailOnClick ? 'details' : 'selection'); ?>"
        data-detail-available="<?php echo e(($rowDetailUrl || $safeDetailAction || $safeDetailEvent) ? 'true' : 'false'); ?>"
        data-selected="<?php echo e($isSelected ? 'true' : 'false'); ?>"
        aria-selected="<?php echo e($isSelected ? 'true' : 'false'); ?>"
        x-data="{
            clickTimer: null,
            rowId: <?php echo e($rowId); ?>,
            selectionAction: <?php echo \Illuminate\Support\Js::from($safeSelectionAction)->toHtml() ?>,
            detailAction: <?php echo \Illuminate\Support\Js::from($safeDetailAction)->toHtml() ?>,
            detailEvent: <?php echo \Illuminate\Support\Js::from($safeDetailEvent)->toHtml() ?>,
            detailUrl: <?php echo \Illuminate\Support\Js::from($rowDetailUrl)->toHtml() ?>,
            detailOnClick: <?php echo \Illuminate\Support\Js::from($detailOnClick)->toHtml() ?>,
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
                if (this.detailOnClick) {
                    // The first click owns opening; don't submit again on dblclick.
                    if (event.detail > 1) return;
                    this.openDetails(event);
                    return;
                }
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
                if (this.detailOnClick) {
                    if (!event.repeat) this.openDetails(event);
                    return;
                }
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
        <?php if (! ($detailOnClick)): ?> x-on:dblclick.prevent="openDetails($event)" <?php endif; ?>
        x-on:keydown.enter.prevent="handleKeyboard($event)"
        <?php if($detailOnClick): ?> x-on:keydown.space="if (!isControl($event)) { $event.preventDefault(); handleKeyboard($event) }" <?php endif; ?>
    >
        <div role="presentation" class="rt-table-row-grid content-start items-center gap-1.5 sm:gap-x-3 md:gap-0" style="--rt-table-columns: <?php echo e($gridTemplate); ?>;">
        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rowView): ?>
            <?php echo $__env->make($rowView, ['item' => $item, 'isSelected' => $isSelected, 'selectedItems' => $selectedItems, 'columnsMeta' => $columns, 'hideClass' => $hideClass], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php else: ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $columns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div role="cell" class="px-2 py-2 <?php echo e($hideClass($col['hideOn'])); ?>">—</div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        </div>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($actionsView ?? false): ?>
            <div class="rt-table-row-actions absolute right-3 top-3 z-10 flex items-center md:inset-y-0 md:right-2 md:z-auto">
                <?php echo $__env->make($actionsView, ['item' => $item], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailsView && (int) $expandedId === (int) $item->id): ?>
            <div class="rt-table-row-details -mx-3 -mb-3 mt-3 md:-mx-2 md:-mb-2 md:mt-2">
                <?php echo $__env->make($detailsView, ['item' => $item], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
    <div role="row"><div role="cell" class="rt-table-empty p-4 text-sm text-rt-muted dark:text-rt-dark-muted"><?php echo e($empty); ?></div></div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($scrollPagination): ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($scrollPagination): ?>
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
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/table.blade.php ENDPATH**/ ?>