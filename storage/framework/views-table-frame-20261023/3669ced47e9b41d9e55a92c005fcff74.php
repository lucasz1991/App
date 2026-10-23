<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'filterCount' => 0,
    'singleLine' => false,
    'title' => null,
    'resetAction' => null,
    'resetLabel' => null,
    'applyLabel' => null,
    'searchLabel' => null,
    'searchFor' => null,
    'searchInHeader' => false,
    'id' => null,
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
    'filterCount' => 0,
    'singleLine' => false,
    'title' => null,
    'resetAction' => null,
    'resetLabel' => null,
    'applyLabel' => null,
    'searchLabel' => null,
    'searchFor' => null,
    'searchInHeader' => false,
    'id' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $filterCount = max(0, (int) $filterCount);
    $singleLine = filter_var($singleLine, FILTER_VALIDATE_BOOL);
    $hasFilters = trim((string) $slot) !== '';
    $hasBulk = isset($bulk) && trim((string) $bulk) !== '';
    $hasChips = isset($chips) && trim((string) $chips) !== '';
    $safeResetAction = is_string($resetAction)
        && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $resetAction)
            ? $resetAction
            : null;
    $resolvedTitle = filled($title) ? (string) $title : ($hasFilters ? __('app.filters') : __('app.search'));
    $resolvedResetLabel = filled($resetLabel) ? (string) $resetLabel : __('app.reset_filters');
    $resolvedApplyLabel = filled($applyLabel) ? (string) $applyLabel : __('app.apply_filters');
    $resolvedSearchLabel = filled($searchLabel) ? (string) $searchLabel : __('app.search');
    $panelId = filled($id)
        ? (string) $id
        : 'rt-table-filters-'.substr(md5($resolvedTitle.(string) $slot), 0, 10);
?>


<section
    <?php echo e($attributes->except('id')->class([
        'rt-table-toolbar mb-3 min-w-0 sm:mb-4',
        'rt-table-toolbar--single-line' => $singleLine,
    ])); ?>

    x-data="{
        desktopFilters: window.matchMedia('(min-width: 1024px)').matches,
        filtersOpen: false,
        filterViewport: null,
        filterViewportListener: null,
        init() {
            this.filterViewport = window.matchMedia('(min-width: 1024px)');
            this.desktopFilters = this.filterViewport.matches;
            this.filterViewportListener = (event) => {
                this.desktopFilters = event.matches;
                if (event.matches) this.filtersOpen = false;
            };
            if (typeof this.filterViewport.addEventListener === 'function') {
                this.filterViewport.addEventListener('change', this.filterViewportListener);
            } else {
                this.filterViewport.addListener(this.filterViewportListener);
            }
        },
        closeFilters(restoreFocus = false) {
            this.filtersOpen = false;
            if (restoreFocus) {
                this.$nextTick(() => this.$refs.filterTrigger?.focus({ preventScroll: true }));
            }
        },
        destroy() {
            if (!this.filterViewport || !this.filterViewportListener) return;
            if (typeof this.filterViewport.removeEventListener === 'function') {
                this.filterViewport.removeEventListener('change', this.filterViewportListener);
            } else {
                this.filterViewport.removeListener(this.filterViewportListener);
            }
        },
    }"
    x-on:keydown.escape.window="if (filtersOpen) closeFilters(true)"
    data-tables-toolbar
    data-rt-premium-filter
    aria-labelledby="<?php echo e($panelId); ?>-title"
>
    <h2 id="<?php echo e($panelId); ?>-title" class="sr-only"><?php echo e($resolvedTitle); ?></h2>
    <div class="rt-table-toolbar__body">
        <div class="rt-table-toolbar__bulk">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasBulk): ?>
                <?php echo e($bulk); ?>

            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$hasBulk || !str_contains((string) $bulk, 'data-tables-bulk')): ?>
                <button type="button" disabled aria-label="<?php echo e(__('app.bulk_actions')); ?> – keine Sammelaktionen verfügbar" title="Keine Sammelaktionen für diese Liste verfügbar" class="rt-table-bulk-trigger rt-table-bulk-icon" data-tables-bulk-unavailable>
                    <i class="far fa-layer-group" aria-hidden="true"></i>
                </button>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($search)): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($searchInHeader): ?>
                <template x-teleport="[data-page-header-search]">
                    <div data-page-list-search><?php echo e($search); ?></div>
                </template>
            <?php else: ?>
            <div class="rt-table-toolbar__search-field">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($searchFor)): ?>
                    <label class="rt-filter-field__label" for="<?php echo e($searchFor); ?>"><?php echo e($resolvedSearchLabel); ?></label>
                <?php else: ?>
                    <span class="rt-filter-field__label"><?php echo e($resolvedSearchLabel); ?></span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div class="rt-table-toolbar__search min-w-0"><?php echo e($search); ?></div>
            </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <div class="rt-table-toolbar__actions">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($filterCount > 0): ?>
                <span class="rt-table-toolbar__count" data-tables-filter-count aria-live="polite" aria-atomic="true"><?php echo e($filterCount); ?> <?php echo e(__('app.active')); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($safeResetAction && $filterCount > 0): ?>
                <button
                    type="button"
                    wire:click="<?php echo e($safeResetAction); ?>"
                    x-on:click="filtersOpen = false"
                    class="rt-table-toolbar__reset"
                    data-tables-filter-reset
                >
                    <span><?php echo e($resolvedResetLabel); ?></span>
                </button>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasFilters): ?>
                <template x-if="! desktopFilters">
                    <button
                        x-ref="filterTrigger"
                        type="button"
                        class="rt-ui-button rt-ui-button-secondary rt-table-filter-trigger"
                        x-on:click="filtersOpen = ! filtersOpen"
                        x-bind:aria-expanded="filtersOpen.toString()"
                        aria-controls="<?php echo e($panelId); ?>-mobile"
                    >
                        <span><?php echo e(__('app.filters')); ?></span>
                        <i class="far fa-chevron-down" x-bind:class="filtersOpen && 'rotate-180'" aria-hidden="true"></i>
                    </button>
                </template>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasFilters): ?>
            <template x-if="desktopFilters">
                <div
                    class="rt-table-toolbar__filters rt-table-toolbar__filters--desktop"
                    data-tables-filters-desktop
                    aria-label="<?php echo e(__('app.filters')); ?>"
                >
                    <?php echo e($slot); ?>

                </div>
            </template>

            <template x-if="! desktopFilters">
                <div
                    id="<?php echo e($panelId); ?>-mobile"
                    x-cloak
                    x-show="filtersOpen"
                    x-transition:enter="rt-filter-panel-enter"
                    x-transition:enter-start="rt-filter-panel-enter-from"
                    x-transition:enter-end="rt-filter-panel-enter-to"
                    x-transition:leave="rt-filter-panel-leave"
                    x-transition:leave-start="rt-filter-panel-leave-from"
                    x-transition:leave-end="rt-filter-panel-leave-to"
                    class="rt-table-toolbar__mobile-panel"
                    data-tables-filters-mobile
                    role="region"
                    aria-label="<?php echo e(__('app.filters')); ?>"
                >
                    <div class="rt-table-toolbar__filters rt-table-toolbar__filters--mobile">
                        <?php echo e($slot); ?>

                    </div>
                    <button
                        type="button"
                        x-on:click="closeFilters(true)"
                        class="rt-table-toolbar__apply"
                        data-tables-filter-apply
                    >
                        <span><?php echo e($resolvedApplyLabel); ?></span>
                        <i class="far fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </template>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($hasChips): ?>
        <div class="rt-table-toolbar__chips" aria-label="<?php echo e(__('app.active_filters')); ?>" data-tables-filter-chips>
            <?php echo e($chips); ?>

        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/toolbar.blade.php ENDPATH**/ ?>