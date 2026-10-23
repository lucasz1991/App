<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'id' => null,
    'maxWidth' => null,
    'showExpression' => null,
    // Schaltet die Inhaltsschleuse ab. Nur fuer Dialoge sinnvoll, deren
    // Inhalt selbst schon eine eigene Ladedarstellung mitbringt.
    'instant' => false,
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
    'id' => null,
    'maxWidth' => null,
    'showExpression' => null,
    // Schaltet die Inhaltsschleuse ab. Nur fuer Dialoge sinnvoll, deren
    // Inhalt selbst schon eine eigene Ladedarstellung mitbringt.
    'instant' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $modalId = $id ?? md5($attributes->wire('model'));
    $titleId = $modalId.'-title';
    $contentId = $modalId.'-content';
    $gated = ! filter_var($instant, FILTER_VALIDATE_BOOLEAN);
?>

<?php if (isset($component)) { $__componentOriginal9f64f32e90b9102968f2bc548315018c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9f64f32e90b9102968f2bc548315018c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.modal','data' => ['id' => $modalId,'maxWidth' => $maxWidth,'showExpression' => $showExpression,'role' => 'dialog','ariaLabelledby' => ''.e($titleId).'','ariaDescribedby' => ''.e($contentId).'','attributes' => $attributes->except(['role', 'aria-labelledby', 'aria-describedby'])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($modalId),'maxWidth' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($maxWidth),'show-expression' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($showExpression),'role' => 'dialog','aria-labelledby' => ''.e($titleId).'','aria-describedby' => ''.e($contentId).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->except(['role', 'aria-labelledby', 'aria-describedby']))]); ?>
    <header class="rt-modal-header relative grid shrink-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-4 border-b border-rt-border/70 bg-rt-surface px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-6">
        <h2 id="<?php echo e($titleId); ?>" class="min-w-0 text-lg font-semibold leading-6 tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">
            <?php echo e($title); ?>

        </h2>

        <div
            class="flex shrink-0 items-center gap-1"
            data-dialog-header-toolbar
        >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($headerActions)): ?>
                <div
                    class="flex items-center gap-1"
                    role="toolbar"
                    aria-label="<?php echo e(__('app.actions')); ?>"
                    data-dialog-header-actions
                >
                    <?php echo e($headerActions); ?>

                </div>

                <span
                    class="mx-0.5 h-5 w-px shrink-0 bg-rt-border/80 dark:bg-rt-dark-border/80"
                    aria-hidden="true"
                    data-dialog-header-separator
                ></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="rt-ui-button rt-ui-button-secondary inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-rt-border/70 bg-rt-control text-rt-muted shadow-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/35 hover:text-rt-accent active:scale-[0.97] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/70 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/35 dark:hover:text-rt-dark-accent"
                aria-label="<?php echo e(__('app.close')); ?>"
                title="<?php echo e(__('app.close')); ?>"
                data-dialog-close
            >
                <i class="far fa-times text-base" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    
    <div
        id="<?php echo e($contentId); ?>"
        class="rt-modal-content min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5 py-5 text-sm leading-6 text-rt-text [overflow-wrap:anywhere] [scrollbar-gutter:stable] dark:text-rt-dark-text sm:px-6 sm:py-6 [&_iframe]:max-w-full [&_img]:max-w-full [&_video]:max-w-full"
        <?php if($gated): ?>
            
            x-data="rtModalBody"
            data-rt-modal-body
            data-rt-state="ready"
        <?php endif; ?>
    >
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($gated): ?>
            <div class="rt-modal-body-loader" data-rt-modal-loader>
                <?php if (isset($component)) { $__componentOriginal24e17cb66881c3321b8b712021de0468 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal24e17cb66881c3321b8b712021de0468 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.loading.skeleton','data' => ['variant' => 'list','rows' => 3]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.loading.skeleton'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'list','rows' => 3]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal24e17cb66881c3321b8b712021de0468)): ?>
<?php $attributes = $__attributesOriginal24e17cb66881c3321b8b712021de0468; ?>
<?php unset($__attributesOriginal24e17cb66881c3321b8b712021de0468); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal24e17cb66881c3321b8b712021de0468)): ?>
<?php $component = $__componentOriginal24e17cb66881c3321b8b712021de0468; ?>
<?php unset($__componentOriginal24e17cb66881c3321b8b712021de0468); ?>
<?php endif; ?>
            </div>

            <div data-rt-modal-body-content>
                <?php echo e($content); ?>

            </div>
        <?php else: ?>
            <?php echo e($content); ?>

        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($footer)): ?>
        <footer class="rt-modal-footer flex shrink-0 flex-row flex-wrap items-center justify-end gap-2 border-t border-rt-border/70 bg-rt-surface-muted/55 px-5 py-4 text-end dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6">
            <?php echo e($footer); ?>

        </footer>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9f64f32e90b9102968f2bc548315018c)): ?>
<?php $attributes = $__attributesOriginal9f64f32e90b9102968f2bc548315018c; ?>
<?php unset($__attributesOriginal9f64f32e90b9102968f2bc548315018c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9f64f32e90b9102968f2bc548315018c)): ?>
<?php $component = $__componentOriginal9f64f32e90b9102968f2bc548315018c; ?>
<?php unset($__componentOriginal9f64f32e90b9102968f2bc548315018c); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/dialog-modal.blade.php ENDPATH**/ ?>