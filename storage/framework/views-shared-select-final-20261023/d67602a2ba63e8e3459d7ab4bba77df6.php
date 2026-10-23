<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['controlClass' => '']));

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

foreach (array_filter((['controlClass' => '']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if (! $__env->hasRenderedOnce('577913ef-5a5e-45d9-9386-c25f4f2266d6')): $__env->markAsRenderedOnce('577913ef-5a5e-45d9-9386-c25f4f2266d6'); ?>
<style data-rt-floating-composer>
.rt-floating-window {
    --rt-composer-height: 40px;
    --rt-composer-field-height: 32px;
    --rt-composer-button-size: 40px;
    --rt-composer-tool-size: 32px;
}
.rt-floating-window :is(.rt-chat-composer, .rt-ai-assist__composer, .ai-intake-panel__composer) {
    padding: 6px 10px !important;
    border-top: 1px solid var(--rt-shell-border);
    background: var(--rt-shell-surface-solid);
    box-shadow: none;
    backdrop-filter: none;
}
.rt-floating-window .rt-chat-composer > form { min-height: 0; }
.rt-floating-window .rt-floating-composer-row { gap: 6px; align-items: flex-end; }
.rt-floating-window .rt-floating-composer-control {
    display: flex; flex: 1; align-items: center; gap: 2px; min-width: 0;
    min-height: var(--rt-composer-height); padding: 3px 6px;
    border: 1px solid color-mix(in srgb, var(--rt-shell-border) 85%, transparent);
    border-radius: 22px; color: var(--rt-shell-text); background: var(--rt-shell-surface-solid);
    box-shadow: 0 1px 0 rgb(255 255 255 / 12%) inset;
}
.rt-floating-window .rt-floating-composer-control:focus-within {
    border-color: color-mix(in srgb, var(--rt-shell-accent) 44%, var(--rt-shell-border));
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--rt-shell-accent) 9%, transparent);
    transform: none;
}
.rt-floating-window .rt-floating-composer-control :is(input[type='text'], textarea) {
    width: 100%; min-width: 0; min-height: var(--rt-composer-field-height) !important;
    height: var(--rt-composer-field-height); max-height: 120px; margin: 0; padding: 6px;
    border: 0; border-radius: 0; outline: 0; background: transparent;
    color: var(--rt-shell-text); font-size: 13px; line-height: 20px; box-shadow: none;
}
.rt-floating-window .rt-floating-composer-control :is(input, textarea)::placeholder { color: var(--rt-shell-muted); }
.rt-floating-window .rt-floating-composer-control :is(input, textarea):focus { box-shadow: none; }
.rt-floating-window .rt-floating-composer-row button {
    display: inline-flex; flex: none; align-items: center; justify-content: center;
    width: var(--rt-composer-button-size); height: var(--rt-composer-button-size);
    min-width: var(--rt-composer-button-size); min-height: var(--rt-composer-button-size);
    padding: 0; border-radius: 50%; font-size: 14px; box-shadow: none;
}
.rt-floating-window .rt-floating-composer-control button {
    width: var(--rt-composer-tool-size); height: var(--rt-composer-tool-size);
    min-width: var(--rt-composer-tool-size); min-height: var(--rt-composer-tool-size);
    color: var(--rt-shell-muted); border: 0; background: transparent;
}
.rt-floating-window .rt-floating-composer-row .rt-floating-composer-attachment {
    border: 1px solid color-mix(in srgb, var(--rt-shell-border) 85%, transparent);
    color: var(--rt-shell-accent); background: var(--rt-shell-surface-solid);
}
.rt-floating-window .rt-floating-composer-row .rt-floating-composer-send {
    border: 0; color: #fff; background: var(--rt-shell-accent);
}
.rt-floating-window .rt-floating-composer-send::after { display: none; }
.rt-floating-window .rt-floating-composer-row button:hover:not(:disabled) { filter: brightness(.96); }
.rt-floating-window .rt-floating-composer-row button:focus-visible { outline: 2px solid var(--rt-shell-accent); outline-offset: 2px; }
@media (max-width: 760px), (pointer: coarse) {
    .rt-floating-window { --rt-composer-height: 48px; --rt-composer-field-height: 40px; --rt-composer-button-size: 44px; --rt-composer-tool-size: 40px; }
    .rt-floating-window .rt-floating-composer-control :is(input[type='text'], textarea) { padding-block: 10px; font-size: 16px; }
    .rt-floating-window :is(.rt-chat-composer, .rt-ai-assist__composer, .ai-intake-panel__composer) {
        padding: 6px max(10px, env(safe-area-inset-right)) max(6px, env(safe-area-inset-bottom)) max(10px, env(safe-area-inset-left)) !important;
    }
}
</style>
<?php endif; ?>

<div <?php echo e($attributes->class(['rt-floating-composer-row flex min-w-0 flex-1 items-center gap-2'])); ?>>
    <?php echo e($leading ?? ''); ?>

    <div class="rt-floating-composer-control flex min-h-10 min-w-0 flex-1 items-center gap-1 rounded-full px-1.5 py-1 <?php echo e($controlClass); ?>" data-chat-input-capsule>
        <?php echo e($slot); ?>

    </div>
    <?php echo e($actions ?? ''); ?>

</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/floating/composer-row.blade.php ENDPATH**/ ?>