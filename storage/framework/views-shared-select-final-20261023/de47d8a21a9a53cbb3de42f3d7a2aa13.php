<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['id', 'maxWidth', 'placement' => 'center', 'showExpression' => null]));

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

foreach (array_filter((['id', 'maxWidth', 'placement' => 'center', 'showExpression' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
][$maxWidth ?? '2xl'];

$dialogRole = $attributes->get('role', 'dialog');
$labelledBy = $attributes->get('aria-labelledby');
$describedBy = $attributes->get('aria-describedby');
$ariaLabel = $attributes->get('aria-label', config('app.name') . ' Dialog');
$isDrawer = $placement === 'right';
?>

<div
    <?php if($showExpression): ?>
        x-data="{ show: false }"
        x-init="show = !!(<?php echo e($showExpression); ?>); $watch('<?php echo e($showExpression); ?>', value => show = !!value); $watch('show', value => <?php echo e($showExpression); ?> = value)"
    <?php else: ?>
        x-data="{ show: <?php if ((object) ($attributes->wire('model')) instanceof \Livewire\WireDirective) : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e($attributes->wire('model')->value()); ?>')<?php echo e($attributes->wire('model')->hasModifier('live') ? '.live' : ''); ?><?php else : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e($attributes->wire('model')); ?>')<?php endif; ?> }"
    <?php endif; ?>
    x-on:close.stop="show = false"
    x-on:rt-navigation:prepare.window="show = false"
    x-on:keydown.escape.window="show = false"
>
    <template x-teleport="body">
        <div
            x-show.important="show"
            x-on:close.stop="show = false"
            x-trap.inert.noscroll="show"
            id="<?php echo e($id); ?>"
            class="rt-ui-modal rt-modal-shell <?php echo e($isDrawer ? 'rt-disposition-drawer' : 'rt-modal-center-shell'); ?> jetstream-modal fixed inset-0 z-[190] overflow-x-hidden overflow-y-auto overscroll-contain p-3 sm:p-6"
            style="display: none;"
            data-rt-modal-shell
            data-rt-overlay-layer
            data-rt-overlay-base="190"
        >
            <div x-show.important="show" aria-hidden="true" class="rt-modal-backdrop fixed inset-0" x-on:click="show = false" x-transition:enter="rt-motion-backdrop-enter"
                            x-transition:enter-start="rt-motion-faded"
                            x-transition:enter-end="rt-motion-shown"
                            x-transition:leave="rt-motion-backdrop-leave"
                            x-transition:leave-start="rt-motion-shown"
                            x-transition:leave-end="rt-motion-faded">
                <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-md"></div>
            </div>

            <div x-show.important="show"
                            role="<?php echo e($dialogRole); ?>"
                            aria-modal="true"
                            <?php if(filled($labelledBy)): ?>
                                aria-labelledby="<?php echo e($labelledBy); ?>"
                            <?php else: ?>
                                aria-label="<?php echo e($ariaLabel); ?>"
                            <?php endif; ?>
                            <?php if(filled($describedBy)): ?>
                                aria-describedby="<?php echo e($describedBy); ?>"
                            <?php endif; ?>
                            class="rt-ui-surface rt-ui-modal-panel rt-modal-frame <?php echo e($isDrawer ? 'rt-disposition-drawer__panel' : ''); ?> relative my-auto flex max-h-[calc(100dvh-1.5rem)] min-h-0 min-w-0 w-full max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 sm:max-h-[calc(100dvh-3rem)] <?php echo e($maxWidth); ?>"
                            x-transition:enter="rt-motion-modal-enter"
                            x-transition:enter-start="rt-motion-modal-enter-from"
                            x-transition:enter-end="rt-motion-modal-enter-to"
                            x-transition:leave="rt-motion-modal-leave"
                            x-transition:leave-start="rt-motion-modal-leave-from"
                            x-transition:leave-end="rt-motion-modal-leave-to"
                            data-rt-modal-panel>
                <?php echo e($slot); ?>

            </div>

            
            <div
                wire:ignore
                class="pointer-events-none fixed inset-0 z-[20]"
                data-rt-overlay-portal
                data-rt-modal-portal
            ></div>
        </div>
    </template>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/modal.blade.php ENDPATH**/ ?>