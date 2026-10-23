<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['start' => null, 'end' => null, 'previewId' => null]));

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

foreach (array_filter((['start' => null, 'end' => null, 'previewId' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $from = $start instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($start)->locale('de') : null;
    $until = $end instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($end)->setTimezone($from?->getTimezone() ?? $end->getTimezone())->locale('de') : null;
    $validPeriod = $from && $until && $until->greaterThan($from);
    $periodLabel = $from && $until ? $from->format('d.m.Y H:i').' bis '.$until->format('d.m.Y H:i') : 'Zeitraum nicht hinterlegt';
    $calendars = [];
    $omittedMonths = 0;
    if ($validPeriod) {
        $firstDay = $from->startOfDay();
        $lastDay = $until->subMicrosecond()->startOfDay();
        $firstDate = $firstDay->toDateString();
        $lastDate = $lastDay->toDateString();
        $firstMonth = $firstDay->startOfMonth();
        $lastMonth = $lastDay->startOfMonth();
        $monthDistance = ($lastMonth->year - $firstMonth->year) * 12 + $lastMonth->month - $firstMonth->month;
        $omittedMonths = max(0, $monthDistance - 1);
        $months = $monthDistance === 0 ? [$firstMonth] : [$firstMonth, $lastMonth];
        $occupiedDates = $firstDay->format('d.m.Y').($firstDay->isSameDay($lastDay) ? '' : ' bis '.$lastDay->format('d.m.Y'));
        foreach ($months as $month) {
            // Reuse the existing Monday-first month grid, but select only the
            // aggregate [start, end) span, not the entire generated month.
            $calendar = \App\Support\Operations\TimelineMiniCalendar::forInterval($month, $month->addMonth());
            $calendar['accessible_label'] = 'Kalender '.$calendar['month_label'].'. Markierte Zeitspanne: '.$occupiedDates.'.';
            foreach ($calendar['days'] as &$day) {
                $selected = $day['date'] >= $firstDate && $day['date'] <= $lastDate;
                $day['selected'] = $selected;
                $day['start'] = $selected && $day['date'] === $firstDate;
                $day['end'] = $selected && $day['date'] === $lastDate;
            }
            unset($day);
            $calendars[] = $calendar;
        }
    }
?>
<?php if (isset($component)) { $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-cell-preview','data' => ['label' => 'Zeitraum: '.$periodLabel,'previewId' => $previewId,'kind' => 'period']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-cell-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Zeitraum: '.$periodLabel),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewId),'kind' => 'period']); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from && $until): ?>
                <span class="rt-table-value tabular-nums"><?php echo e($from->format('d.m.Y H:i')); ?></span>
                <span class="rt-table-meta tabular-nums">bis <?php echo e($until->format('d.m.Y H:i')); ?></span>
            <?php else: ?>
                <span class="rt-table-value">Zeitraum nicht hinterlegt</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <section class="rt-service-cell-preview__body rt-service-period-preview" aria-label="Zeitraum der Leistung">
            <h3 class="rt-service-cell-preview__title">Zeitraum</h3>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from && $until): ?>
                <dl class="rt-service-period-preview__range">
                    <div><dt>Beginn</dt><dd><time datetime="<?php echo e($from->toIso8601String()); ?>"><?php echo e($from->format('d.m.Y')); ?> <strong><?php echo e($from->format('H:i')); ?></strong></time></dd></div>
                    <div><dt>Ende</dt><dd><time datetime="<?php echo e($until->toIso8601String()); ?>"><?php echo e($until->format('d.m.Y')); ?> <strong><?php echo e($until->format('H:i')); ?></strong></time></dd></div>
                </dl>
                <p class="rt-service-cell-preview__note"><?php echo e($from->getTimezone()->getName()); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from->format('P') !== $until->format('P')): ?> · Zeitumstellung: UTC <?php echo e($from->format('P')); ?> → <?php echo e($until->format('P')); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($validPeriod): ?>
                <template x-if="open">
                    <div class="rt-service-period-preview__calendars" data-calendar-months="<?php echo e(count($calendars)); ?>">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $calendars; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $calendar): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <figure class="rt-event-mini-calendar" role="img" aria-label="<?php echo e($calendar['accessible_label']); ?>">
                                <div class="rt-event-mini-calendar__header" aria-hidden="true"><span class="rt-event-mini-calendar__month"><?php echo e($calendar['month_label']); ?></span></div>
                                <div class="rt-event-mini-calendar__grid" aria-hidden="true">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $weekday): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <span class="rt-event-mini-calendar__weekday"><?php echo e($weekday); ?></span>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $calendar['days']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                                            'rt-event-mini-calendar__day',
                                            'is-outside' => $day['outside'],
                                            'is-selected' => $day['selected'],
                                            'is-start' => $day['start'],
                                            'is-end' => $day['end'],
                                        ]); ?>" data-date="<?php echo e($day['date']); ?>"><?php echo e($day['number']); ?></span>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </figure>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </template>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($omittedMonths > 0): ?>
                    <p class="rt-service-cell-preview__note" data-calendar-omitted-months="<?php echo e($omittedMonths); ?>"><?php echo e($omittedMonths); ?> <?php echo e($omittedMonths === 1 ? 'weiterer Monat liegt' : 'weitere Monate liegen'); ?> zwischen den gezeigten Monaten.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php else: ?>
                <p class="rt-service-cell-preview__note"><?php echo e($from && $until ? 'Kein gültiger Zeitraum' : 'Zeitraum nicht hinterlegt'); ?></p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </section>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed)): ?>
<?php $attributes = $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed; ?>
<?php unset($__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed)): ?>
<?php $component = $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed; ?>
<?php unset($__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-period-preview.blade.php ENDPATH**/ ?>