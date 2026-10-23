<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'count' => 0,
    'selectAll' => null,        // Name der wire:click-Methode fuer "alle markieren/entfernen"
    'selectAllTitle' => null,
    'align' => 'left',
    'width' => '56',
    'actionsAvailable' => true,
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
    'count' => 0,
    'selectAll' => null,        // Name der wire:click-Methode fuer "alle markieren/entfernen"
    'selectAllTitle' => null,
    'align' => 'left',
    'width' => '56',
    'actionsAvailable' => true,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $count = (int) $count;
    $isEmpty = $count === 0;
    $actionsAvailable = filter_var($actionsAvailable, FILTER_VALIDATE_BOOL);
    $hasActions = $actionsAvailable && trim((string) $slot) !== '';
    $showActions = ! $isEmpty && $hasActions;
    $selectAllTitle = $selectAllTitle ?? __('app.select_all');
?>

<div class="rt-table-bulk flex items-center gap-2" data-tables-bulk>
    
    <button
        type="button"
        <?php if($selectAll): ?> wire:click="<?php echo e($selectAll); ?>" <?php endif; ?>
        title="<?php echo e($selectAllTitle); ?>"
        aria-label="<?php echo e($selectAllTitle); ?>"
        class="rt-table-select-trigger flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 hover:text-rt-accent focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <rect x="4" y="4" width="16" height="16" rx="4" />
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($count > 0): ?>
                <path stroke-linecap="round" d="M8 12h8" />
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </svg>
    </button>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showActions): ?>
        <div data-tables-bulk-menu>
            <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => $align,'width' => $width,'contentClasses' => 'bg-rt-surface p-1.5 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($align),'width' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($width),'content-classes' => 'bg-rt-surface p-1.5 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                 <?php $__env->slot('trigger', null, []); ?> 
                    <button
                        type="button"
                        aria-label="<?php echo e(__('app.bulk_actions')); ?>"
                        title="<?php echo e(__('app.bulk_actions')); ?>"
                        class="rt-table-bulk-trigger group flex h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3.5 text-sm font-medium text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20"
                    >
                        <svg class="h-[18px] w-[18px] shrink-0 text-rt-muted dark:text-rt-dark-muted" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.005 11.19V12l6.998 4.042L19 12v-.81M5 16.15v.81L11.997 21l6.998-4.042v-.81M12.003 3 5.005 7.042l6.998 4.042L19 7.042 12.003 3Z" />
                        </svg>
                        <span class="sr-only"><?php echo e(__('app.bulk_actions')); ?></span>
                        <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rt-red px-1.5 py-0.5 text-xs font-bold leading-none text-white">
                            <?php echo e($count); ?>

                        </span>
                    </button>
                 <?php $__env->endSlot(); ?>

                 <?php $__env->slot('content', null, []); ?> 
                    <?php echo e($slot); ?>

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
    <?php else: ?>
        <button type="button" disabled class="rt-table-bulk-trigger rt-table-bulk-icon" aria-label="<?php echo e(__('app.bulk_actions')); ?> – <?php echo e($isEmpty ? 'Zuerst Einträge auswählen' : 'Keine Aktionen verfügbar'); ?>" title="<?php echo e($isEmpty ? 'Zuerst Einträge auswählen' : 'Keine Aktionen verfügbar'); ?>">
            <i class="far fa-layer-group" aria-hidden="true"></i>
        </button>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/bulk-actions.blade.php ENDPATH**/ ?>