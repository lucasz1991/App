<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'title',
    'description' => null,
    'eyebrow' => null,
    'count' => null,
    'help' => null,
    'backUrl' => null,
    'backLabel' => null,
    'showBack' => true,
    'titleInTopbar' => false,
    'variant' => 'default',
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
    'title',
    'description' => null,
    'eyebrow' => null,
    'count' => null,
    'help' => null,
    'backUrl' => null,
    'backLabel' => null,
    'showBack' => true,
    'titleInTopbar' => false,
    'variant' => 'default',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php ($compact = $variant === 'compact'); ?>

<header
    <?php echo e($attributes->class(['relative flex min-w-0 items-center gap-3 sm:gap-4', 'rt-page-header--compact' => $compact])); ?>

    data-anim="fade-up"
    data-page-header
    data-page-header-variant="<?php echo e($compact ? 'compact' : 'default'); ?>"
    <?php if($titleInTopbar): ?> x-data="{}" data-page-title-in-topbar <?php endif; ?>
>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showBack): ?>
        <?php if (isset($component)) { $__componentOriginalaf1e01618205bd671885c0c2b296336f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaf1e01618205bd671885c0c2b296336f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.backbutton','data' => ['href' => $backUrl,'label' => $backLabel]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.backbutton'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($backUrl),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($backLabel)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaf1e01618205bd671885c0c2b296336f)): ?>
<?php $attributes = $__attributesOriginalaf1e01618205bd671885c0c2b296336f; ?>
<?php unset($__attributesOriginalaf1e01618205bd671885c0c2b296336f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaf1e01618205bd671885c0c2b296336f)): ?>
<?php $component = $__componentOriginalaf1e01618205bd671885c0c2b296336f; ?>
<?php unset($__componentOriginalaf1e01618205bd671885c0c2b296336f); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="rt-page-header-primary flex min-w-0 flex-1 items-center gap-3">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($titleInTopbar): ?>
            <template x-teleport="[data-topbar-page-title]">
                <h1 class="rt-shell-page-title" title="<?php echo e($title); ?>"><?php echo e($title); ?></h1>
            </template>
        <?php else: ?>
        <h1 class="<?php echo \Illuminate\Support\Arr::toCssClasses(['sr-only' => $compact, 'min-w-0 truncate text-xl font-semibold leading-tight tracking-[-0.03em] text-rt-text sm:text-2xl dark:text-rt-dark-text' => ! $compact]); ?>" title="<?php echo e($title); ?>">
            <?php echo e($title); ?>

        </h1>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <div class="rt-page-header-search min-w-0 shrink-0" data-page-header-search><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($controls)): ?><?php echo e($controls); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
    </div>

    <div class="rt-page-header-metrics min-w-0 flex-1" data-page-header-metrics></div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($compact || isset($actions) || $help): ?>
        <div class="flex shrink-0 flex-nowrap items-center justify-end gap-1.5" data-page-header-actions>
            <div class="empty:hidden flex min-w-0 flex-nowrap items-center justify-end gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-page-header-action-list>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($actions)): ?>
                    <?php echo e($actions); ?>

                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($help): ?>
                <?php if (isset($component)) { $__componentOriginal6c5294affc32919e28349c4ca11a4356 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6c5294affc32919e28349c4ca11a4356 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.page-info-button','data' => ['title' => $title,'help' => $help,'dataPageHeaderControl' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.page-info-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($title),'help' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($help),'data-page-header-control' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6c5294affc32919e28349c4ca11a4356)): ?>
<?php $attributes = $__attributesOriginal6c5294affc32919e28349c4ca11a4356; ?>
<?php unset($__attributesOriginal6c5294affc32919e28349c4ca11a4356); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6c5294affc32919e28349c4ca11a4356)): ?>
<?php $component = $__componentOriginal6c5294affc32919e28349c4ca11a4356; ?>
<?php unset($__componentOriginal6c5294affc32919e28349c4ca11a4356); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</header>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/page-header.blade.php ENDPATH**/ ?>