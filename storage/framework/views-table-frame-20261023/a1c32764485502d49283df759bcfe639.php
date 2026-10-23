<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['preview', 'location' => null]));

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

foreach (array_filter((['preview', 'location' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $points = \App\Support\Operations\GermanyMap::pointsFromPreview($preview);
    $located = $points !== [];
    $precisionLabel = ($preview['confirmed'] ?? false) ? 'bestätigter Einsatzstandort.' : (($preview['estimated'] ?? false) ? 'Ortsvorschlag, keine bestätigte Einsatzadresse.' : 'ungefähre Ortslage, keine genaue Einsatzadresse.');
    $mapLabel = $located ? 'Deutschland: '.$preview['place'].' – '.$precisionLabel : 'Deutschland: '.$preview['label'];
    $pins = array_map(fn (array $point): array => $point + ['tone' => 'open', 'marker' => true, 'title' => count($points) > 1 ? $point['label'] : ($location ?: $point['label'])], $points);
?>
<figure class="rt-event-mini-map" data-map-state="<?php echo e($preview['state']); ?>">
    <div class="rt-event-mini-map__heading">Deutschland</div>
    <?php if (isset($component)) { $__componentOriginal4cd1e99d9923221b0d93b64f83f21681 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4cd1e99d9923221b0d93b64f83f21681 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.germany-map','data' => ['class' => 'rt-event-mini-map__canvas','height' => 112,'label' => $mapLabel,'points' => $pins]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.germany-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-event-mini-map__canvas','height' => 112,'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($mapLabel),'points' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pins)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4cd1e99d9923221b0d93b64f83f21681)): ?>
<?php $attributes = $__attributesOriginal4cd1e99d9923221b0d93b64f83f21681; ?>
<?php unset($__attributesOriginal4cd1e99d9923221b0d93b64f83f21681); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4cd1e99d9923221b0d93b64f83f21681)): ?>
<?php $component = $__componentOriginal4cd1e99d9923221b0d93b64f83f21681; ?>
<?php unset($__componentOriginal4cd1e99d9923221b0d93b64f83f21681); ?>
<?php endif; ?>
    <figcaption>
        <strong class="rt-event-mini-map__place" title="<?php echo e($location ?: ($preview['place'] ?? 'Kein Einsatzort hinterlegt')); ?>"><?php echo e($located ? $preview['place'] : ($location ?: 'Kein Einsatzort')); ?></strong>
        <span class="rt-event-mini-map__precision"><?php echo e($preview['label']); ?></span>
    </figcaption>
</figure>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/timeline-mini-map.blade.php ENDPATH**/ ?>