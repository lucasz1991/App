<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'from' => '',
    'until' => '',
    'fromModel' => null,
    'untilModel' => null,
    'applyAction' => null,
    'applyEvent' => null,
    'today' => null,
    'min' => null,
    'max' => null,
    'maxDays' => null,
    'resetOnOpen' => false,
    'selectionMode' => 'range',
    'presetKeys' => null,
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
    'from' => '',
    'until' => '',
    'fromModel' => null,
    'untilModel' => null,
    'applyAction' => null,
    'applyEvent' => null,
    'today' => null,
    'min' => null,
    'max' => null,
    'maxDays' => null,
    'resetOnOpen' => false,
    'selectionMode' => 'range',
    'presetKeys' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $de = app()->getLocale() === 'de';
    $singleDate = $selectionMode === 'single';
    $config = [
        'locale' => $de ? 'de-DE' : 'en-GB', 'from' => $from, 'until' => $until,
        'today' => $today ?: now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString(),
        'min' => $min, 'max' => $max, 'maxDays' => $maxDays, 'resetOnOpen' => $resetOnOpen,
        'selectionMode' => $singleDate ? 'single' : 'range', 'presets' => $presetKeys,
    ];
?>
<div
    x-data="rtDateRangePicker({ ...<?php echo e(\Illuminate\Support\Js::from($config)); ?><?php if($fromModel): ?>, from: $wire.entangle(<?php echo e(\Illuminate\Support\Js::from($fromModel)); ?>)<?php endif; ?> <?php if($untilModel): ?>, until: $wire.entangle(<?php echo e(\Illuminate\Support\Js::from($untilModel)); ?>)<?php endif; ?> <?php if($applyAction): ?>, onApply: async (range) => { <?php if($applyEvent): ?> const request = { owner: $wire.$id, ...range, handled: false, promise: null }; $dispatch(<?php echo e(\Illuminate\Support\Js::from($applyEvent)); ?>, request); if (request.handled) { await request.promise; return; } <?php endif; ?> await $wire.call(<?php echo e(\Illuminate\Support\Js::from($applyAction)); ?>, range.from, range.until); }<?php endif; ?> })"
    x-id="['range-from', 'range-until', 'range-status']"
    <?php echo e($attributes->class(['rt-date-range-picker', 'rt-date-range-picker--single' => $singleDate])); ?>

    data-rt-date-range-picker data-rt-dropdown-keep-open
    data-selection-mode="<?php echo e($singleDate ? 'single' : 'range'); ?>"
    :aria-busy="busy"
>
    <aside class="rt-date-range-picker__presets" aria-label="<?php echo e($singleDate ? ($de ? 'Schnellauswahl' : 'Quick selection') : ($de ? 'Zeitraum-Vorgaben' : 'Date range presets')); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($singleDate)): ?><span class="rt-date-range-picker__eyebrow"><?php echo e($de ? 'Zeitraum' : 'Date range'); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <template x-for="preset in presets" :key="preset.key">
            <button type="button" @click="choosePreset(preset)" :disabled="busy || preset.disabled" :aria-pressed="draftFrom === preset.from && draftUntil === preset.until" class="rt-date-range-picker__preset">
                <span x-text="preset.label"></span><i class="far fa-check" x-show="draftFrom === preset.from && draftUntil === preset.until" aria-hidden="true"></i>
            </button>
        </template>
    </aside>
    <div class="rt-date-range-picker__main">
        <div class="rt-date-range-picker__fields">
            <label :for="$id('range-from')"><span><?php echo e($singleDate ? ($de ? 'Datum' : 'Date') : ($de ? 'Von' : 'From')); ?></span><input :id="$id('range-from')" :value="fromText" @input="updateTyped(0, $event.target.value)" :disabled="busy" type="text" inputmode="numeric" autocomplete="off" placeholder="<?php echo e($de ? 'TT.MM.JJJJ' : 'DD/MM/YYYY'); ?>" :aria-describedby="$id('range-status')"></label>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($singleDate)): ?>
            <span class="rt-date-range-picker__separator" aria-hidden="true">–</span>
            <label :for="$id('range-until')"><span><?php echo e($de ? 'Bis' : 'To'); ?></span><input :id="$id('range-until')" :value="untilText" @input="updateTyped(1, $event.target.value)" :disabled="busy" type="text" inputmode="numeric" autocomplete="off" placeholder="<?php echo e($de ? 'TT.MM.JJJJ' : 'DD/MM/YYYY'); ?>" :aria-describedby="$id('range-status')"></label>
            <button type="button" @click="clear()" :disabled="busy" class="rt-date-range-picker__clear"><?php echo e($de ? 'Zurücksetzen' : 'Clear'); ?></button>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="rt-date-range-picker__calendars">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $singleDate ? [0] : [0, 1]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $side): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <section class="rt-date-range-picker__calendar" data-range-calendar="<?php echo e($side); ?>" :aria-label="monthLabel(<?php echo e($side); ?>)">
                    <div class="rt-date-range-picker__month-nav">
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'link','size' => 'sm','@click' => 'shiftMonth('.e($side).', -1)','xBind:disabled' => 'busy','class' => 'rt-date-range-picker__nav','ariaLabel' => ''.e($de ? 'Vorheriger Monat' : 'Previous month').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'link','size' => 'sm','@click' => 'shiftMonth('.e($side).', -1)','x-bind:disabled' => 'busy','class' => 'rt-date-range-picker__nav','aria-label' => ''.e($de ? 'Vorheriger Monat' : 'Previous month').'']); ?><i class="far fa-chevron-left" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                        <strong x-text="monthLabel(<?php echo e($side); ?>)" aria-live="polite"></strong>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'link','size' => 'sm','@click' => 'shiftMonth('.e($side).', 1)','xBind:disabled' => 'busy','class' => 'rt-date-range-picker__nav','ariaLabel' => ''.e($de ? 'Nächster Monat' : 'Next month').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'link','size' => 'sm','@click' => 'shiftMonth('.e($side).', 1)','x-bind:disabled' => 'busy','class' => 'rt-date-range-picker__nav','aria-label' => ''.e($de ? 'Nächster Monat' : 'Next month').'']); ?><i class="far fa-chevron-right" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    </div>
                    <div class="rt-date-range-picker__grid" role="grid" :aria-label="monthLabel(<?php echo e($side); ?>)" @keydown="handleKey($event, <?php echo e($side); ?>)" @mouseleave="hover = null">
                        <div class="rt-date-range-picker__weekdays" role="row"><template x-for="weekday in weekdays" :key="weekday"><span role="columnheader" x-text="weekday"></span></template></div>
                        <template x-for="week in [0, 1, 2, 3, 4, 5]" :key="week">
                            <div class="rt-date-range-picker__week" role="row">
                                <template x-for="day in days(<?php echo e($side); ?>).slice(week * 7, week * 7 + 7)" :key="day.iso">
                                    <button type="button" role="gridcell" class="rt-date-range-picker__day" @click="select(day.iso, <?php echo e($side); ?>)" @mouseenter="if (selectingEnd && !day.outside) hover = day.iso" @focus="focused[<?php echo e($side); ?>] = day.iso"
                                        :disabled="busy || day.outside || day.disabled" :tabindex="!day.outside && day.iso === focused[<?php echo e($side); ?>] ? 0 : -1" :data-range-date="day.iso" :data-range-state="state(day.iso)" :data-outside="day.outside" :data-hidden-neighbor="day.hiddenNeighbor" :aria-hidden="day.hiddenNeighbor ? 'true' : null" :data-today="day.iso === today"
                                        :aria-selected="['start', 'end', 'between'].includes(state(day.iso))" :aria-current="day.iso === today ? 'date' : null" :aria-label="format(day.iso, true)" x-text="day.label"></button>
                                </template>
                            </div>
                        </template>
                    </div>
                </section>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="rt-date-range-picker__footer">
            <span :id="$id('range-status')" role="status" class="rt-date-range-picker__status" :class="{ 'sr-only': <?php echo e($singleDate ? 'true' : 'false'); ?> && !failure && !(touched && validationMessage) }" :data-error="!!failure || (touched && !!validationMessage && !selectingEnd)" x-text="summary"></span>
            <div class="rt-date-range-picker__actions">
                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','@click' => 'cancel()','xBind:disabled' => 'busy']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','@click' => 'cancel()','x-bind:disabled' => 'busy']); ?><?php echo e($de ? 'Abbrechen' : 'Cancel'); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','mode' => 'primary','@click' => 'apply()','xBind:disabled' => '!canApply']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','mode' => 'primary','@click' => 'apply()','x-bind:disabled' => '!canApply']); ?><span x-text="busy ? <?php echo e(\Illuminate\Support\Js::from($de ? 'Wird übernommen …' : 'Applying …')); ?> : <?php echo e(\Illuminate\Support\Js::from($de ? 'Übernehmen' : 'Apply')); ?>"></span> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/forms/date-range-picker.blade.php ENDPATH**/ ?>