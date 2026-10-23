<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'paginator',
    'label' => __('pagination.pagination_navigation'),
    'tableKey' => null,
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
    'paginator',
    'label' => __('pagination.pagination_navigation'),
    'tableKey' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Numeric, length-aware pagination only. Queries and page state stay with
    // the original Livewire owner; this component only renders native actions.
    $pageName = $paginator->getPageName();
    $scopeKey = (string) ($tableKey ?? $pageName);
    $keyPrefix = 'table-pagination-'.substr(hash('sha256', $scopeKey.'|'.$pageName), 0, 12);
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $pageNumbers = $lastPage <= 7
        ? range(1, $lastPage)
        : collect([1, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage])
            ->filter(fn ($page) => $page >= 1 && $page <= $lastPage)
            ->unique()->sort()->values()->all();
    $action = fn ($method, $page = null) => $method.'('.($page === null ? '' : $page.', ').json_encode($pageName).')';
?>

<nav <?php echo e($attributes->class(['rt-table-pagination'])); ?> role="navigation" aria-label="<?php echo e($label); ?>"
    data-table-pagination data-table-pagination-key="<?php echo e($scopeKey); ?>" data-table-page-name="<?php echo e($pageName); ?>"
    x-data="{}"
    x-on:click="
        if (!$event.target.closest('button[data-table-page-action]:not(:disabled)')) return;
        const owner = $el.closest('[wire\\:id]');
        const key = $el.dataset.tablePaginationKey;
        const table = Array.from(owner?.querySelectorAll('[data-rt-premium-table][data-table-pagination-key]') ?? [])
            .find(candidate => candidate.closest('[wire\\:id]') === owner && candidate.dataset.tablePaginationKey === key);
        table?.querySelector('[data-table-scroll-body]')?.scrollTo({ top: 0, behavior: 'instant' });
    "
>
    <span class="rt-table-pagination__range" aria-live="polite" aria-atomic="true"
        aria-label="<?php echo e(__('pagination.showing_results', ['from' => $paginator->firstItem() ?? 0, 'to' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()])); ?>">
        <?php echo e($paginator->firstItem() ?? 0); ?>–<?php echo e($paginator->lastItem() ?? 0); ?>

        <span><?php echo e(__('pagination.of')); ?> <?php echo e($paginator->total()); ?></span>
    </span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($paginator->hasPages()): ?>
        <div class="rt-table-pagination__controls" data-table-page-menu wire:loading.attr="aria-busy">
            
            <span class="rt-area-menu__indicator rt-table-pagination__indicator" aria-hidden="true" wire:ignore
                x-data="rtAreaMenu({ optionSelector: '[data-table-page-indicator-option]', visualSelector: '[data-table-page-label]', allowDisabledActive: true })"></span>
            <button type="button" class="rt-table-pagination__button" data-table-page-action
                wire:key="<?php echo e($keyPrefix); ?>-previous" wire:click="<?php echo e($action('previousPage')); ?>" wire:loading.attr="disabled"
                <?php if($paginator->onFirstPage()): echo 'disabled'; endif; ?> aria-label="<?php echo e(html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8')); ?>">
                <i class="far fa-chevron-left" aria-hidden="true"></i>
            </button>
            <span class="rt-table-pagination__mobile-page" aria-hidden="true" data-table-page-indicator-option aria-current="page">
                <span data-table-page-label><?php echo e($currentPage); ?> / <?php echo e($lastPage); ?></span>
            </span>
            <?php $previousNumber = null; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $pageNumbers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $page): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($previousNumber !== null && $page > $previousNumber + 1): ?>
                    <span class="rt-table-pagination__ellipsis" aria-hidden="true" wire:key="<?php echo e($keyPrefix); ?>-gap-<?php echo e($previousNumber); ?>">…</span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <button type="button" class="rt-table-pagination__button rt-table-pagination__page-number" data-table-page-action data-table-page-indicator-option
                    wire:key="<?php echo e($keyPrefix); ?>-page-<?php echo e($page); ?>" wire:click="<?php echo e($action('gotoPage', $page)); ?>" wire:loading.attr="disabled"
                    <?php if($page === $currentPage): ?> aria-current="page" <?php endif; ?> <?php if($page === $currentPage): echo 'disabled'; endif; ?>
                    aria-label="<?php echo e(__('pagination.go_to_page', ['page' => $page])); ?>"><span data-table-page-label><?php echo e($page); ?></span></button>
                <?php $previousNumber = $page; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <button type="button" class="rt-table-pagination__button" data-table-page-action
                wire:key="<?php echo e($keyPrefix); ?>-next" wire:click="<?php echo e($action('nextPage')); ?>" wire:loading.attr="disabled"
                <?php if(!$paginator->hasMorePages()): echo 'disabled'; endif; ?> aria-label="<?php echo e(html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8')); ?>">
                <i class="far fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
        <span class="sr-only"><?php echo e(__('pagination.go_to_page', ['page' => $currentPage])); ?> / <?php echo e($lastPage); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</nav>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/pagination.blade.php ENDPATH**/ ?>