
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['points' => [], 'height' => 270, 'label' => null, 'interactive' => false, 'highlightable' => false]));

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

foreach (array_filter((['points' => [], 'height' => 270, 'label' => null, 'interactive' => false, 'highlightable' => false]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $points = collect($points);
    $mapLabel = $label ?? ('Deutschlandkarte'.($points->isNotEmpty() ? ' mit '.$points->pluck('label')->filter()->unique()->implode(', ') : ''));
    $mapStyle = '--rt-map-default-height: '.max(80, (int) $height).'px; --rt-map-land: url('.asset('rt-brand/illustrations/germany-outline.svg').'); --rt-map-borders: url('.asset('rt-brand/illustrations/germany-borders.svg').')';
?>
<div <?php echo e($attributes->class('rt-germany-map')); ?> style="<?php echo e($mapStyle); ?>" role="<?php echo e($interactive ? 'group' : 'img'); ?>" aria-label="<?php echo e($mapLabel); ?>">
    <div class="rt-germany-map__stage">
        <span class="rt-germany-map__land" aria-hidden="true"></span>
        <span class="rt-germany-map__borders" aria-hidden="true"></span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $points; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $point): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $pinStyle = 'left: '.(float) $point['left'].'%; top: '.(float) $point['top'].'%'.(empty($point['size']) ? '' : '; --pin-size: '.(int) $point['size'].'px');
                $pinPulse = ! empty($point['pulse']);
                $pinMarker = ! empty($point['marker']);
                $pinKey = $highlightable && is_string($point['key'] ?? null) ? trim($point['key']) : '';
            ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($pinKey !== ''): ?>
                <span class="rt-map-highlight" aria-hidden="true" style="<?php echo e($pinStyle); ?>" data-map-highlight="<?php echo e($pinKey); ?>" data-map-active="false" x-bind:data-map-active="mapIsActive(<?php echo \Illuminate\Support\Js::from($pinKey)->toHtml() ?>)"></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <span class="rt-germany-map__pin" aria-hidden="true" style="<?php echo e($pinStyle); ?>" data-tone="<?php echo e($point['tone'] ?? 'default'); ?>" <?php if($pinPulse): ?> data-pulse <?php endif; ?> <?php if($pinMarker): ?> data-location-marker <?php endif; ?> <?php if($pinKey !== ''): ?> data-map-point="<?php echo e($pinKey); ?>" x-bind:data-map-active="mapIsActive(<?php echo \Illuminate\Support\Js::from($pinKey)->toHtml() ?>)" <?php endif; ?> title="<?php echo e($point['title'] ?? $point['label'] ?? ''); ?>"></span>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php echo e($markers ?? ''); ?>

    </div>
    <?php echo e($slot); ?>

</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/germany-map.blade.php ENDPATH**/ ?>