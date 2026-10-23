<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['item']));

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

foreach (array_filter((['item']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $order = $item->order;
    $customer = $order?->relationLoaded('customer') ? $order->getRelation('customer') : null;
    $summary = $item->summary;
    $timezone = $item->timezone ?: 'Europe/Berlin';
    $from = $item->from instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($item->from)->setTimezone($timezone)->locale('de') : null;
    $until = $item->until instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($item->until)->setTimezone($timezone)->locale('de') : null;
    $validPeriod = $from && $until && $until->greaterThan($from);
    $calendars = [];
    $omittedMonths = 0;
    if ($validPeriod) {
        // Keep the complete service span half-open and the visualization bounded.
        $firstDay = $from->startOfDay();
        $lastDay = $until->subMicrosecond()->startOfDay();
        $firstDate = $firstDay->toDateString();
        $lastDate = $lastDay->toDateString();
        $firstMonth = $firstDay->startOfMonth();
        $lastMonth = $lastDay->startOfMonth();
        $monthDistance = ($lastMonth->year - $firstMonth->year) * 12 + $lastMonth->month - $firstMonth->month;
        $omittedMonths = max(0, $monthDistance - 1);
        $occupiedDates = $firstDay->format('d.m.Y').($firstDay->isSameDay($lastDay) ? '' : ' bis '.$lastDay->format('d.m.Y'));
        foreach ($monthDistance === 0 ? [$firstMonth] : [$firstMonth, $lastMonth] as $month) {
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
    $location = $order?->location_name ?: 'Einsatzort offen';
    $locationPreview = $order instanceof \App\Models\Order
        ? \App\Support\Operations\TimelineLocationPreview::fromOrder($order)
        : ['state' => 'unknown', 'label' => 'Standort nicht verortet'];
    $address = collect([$order?->street, trim(($order?->postal_code ?? '').' '.($order?->city ?? '')), $order?->country])->filter()->join(', ');
    $orderFrom = $item->orderFrom ?? null;
    $orderUntil = $item->orderUntil ?? null;
?>
<div <?php echo e($attributes->class(['rt-service-detail-summary'])); ?> data-service-detail-summary data-service-summary-scope="complete" x-data="{ open: true }">
    <div class="rt-service-detail-summary__identity">
        <div class="rt-service-detail-summary__customer">
            <?php if (isset($component)) { $__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.company-avatar','data' => ['customer' => $customer,'size' => 48]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.company-avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['customer' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($customer),'size' => 48]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a)): ?>
<?php $attributes = $__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a; ?>
<?php unset($__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a)): ?>
<?php $component = $__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a; ?>
<?php unset($__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a); ?>
<?php endif; ?>
            <div>
                <span class="rt-service-detail-summary__label">Kunde</span>
                <strong><?php echo e($customer?->company_name ?: 'Kunde nicht hinterlegt'); ?></strong>
            </div>
        </div>
    </div>

    <section class="rt-service-detail-summary__staffing" aria-label="Besetzung der gesamten Leistung">
        <div class="rt-service-detail-summary__section-heading">
            <h3>Besetzung</h3>
        </div>
        <?php if (isset($component)) { $__componentOriginal92b971aa100c2d8a3096a622de7d8225 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal92b971aa100c2d8a3096a622de7d8225 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-staffing-content','data' => ['seats' => $item->staffingSeats,'summary' => $summary,'timezone' => $timezone,'expanded' => true,'detailIdPrefix' => 'service-detail-'.$item->id.'-seat']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-staffing-content'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['seats' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->staffingSeats),'summary' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary),'timezone' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timezone),'expanded' => true,'detail-id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('service-detail-'.$item->id.'-seat')]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal92b971aa100c2d8a3096a622de7d8225)): ?>
<?php $attributes = $__attributesOriginal92b971aa100c2d8a3096a622de7d8225; ?>
<?php unset($__attributesOriginal92b971aa100c2d8a3096a622de7d8225); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal92b971aa100c2d8a3096a622de7d8225)): ?>
<?php $component = $__componentOriginal92b971aa100c2d8a3096a622de7d8225; ?>
<?php unset($__componentOriginal92b971aa100c2d8a3096a622de7d8225); ?>
<?php endif; ?>
        <p class="rt-service-detail-summary__note"><span class="tabular-nums"><?php echo e($summary['confirmed']); ?></span> bestätigt · Aktive Einsatzplätze</p>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((int) $summary['required'] === 0): ?>
            <p class="rt-service-detail-summary__empty">Für diese Leistung sind aktuell keine aktiven Einsatzplätze hinterlegt.</p>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <div class="rt-service-detail-summary__context">
        <section class="rt-service-detail-summary__period" aria-label="Zeitraum der gesamten Leistung">
            <div class="rt-service-detail-summary__section-heading"><h3><i class="far fa-calendar-alt" aria-hidden="true"></i> Zeitraum</h3></div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($orderFrom && $orderUntil): ?>
                <p class="rt-service-detail-summary__note" data-service-order-period>Leistungszeitraum: <?php echo e($orderFrom->format('d.m.Y H:i')); ?> – <?php echo e($orderUntil->format('d.m.Y H:i')); ?></p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from && $until): ?>
                <dl class="rt-service-detail-summary__range">
                    <div><dt><?php echo e($item->count > 0 ? 'Erste Schicht' : 'Beginn'); ?></dt><dd><time datetime="<?php echo e($from->toIso8601String()); ?>"><?php echo e($from->format('d.m.Y')); ?> <strong><?php echo e($from->format('H:i')); ?></strong></time></dd></div>
                    <div><dt><?php echo e($item->count > 0 ? 'Letzte Schicht endet' : 'Ende'); ?></dt><dd><time datetime="<?php echo e($until->toIso8601String()); ?>"><?php echo e($until->format('d.m.Y')); ?> <strong><?php echo e($until->format('H:i')); ?></strong></time></dd></div>
                </dl>
                <p class="rt-service-detail-summary__note"><?php echo e($timezone); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($from->format('P') !== $until->format('P')): ?> · Zeitumstellung: UTC <?php echo e($from->format('P')); ?> → <?php echo e($until->format('P')); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($validPeriod): ?>
                <div class="rt-service-detail-summary__calendars" data-service-detail-calendar-months="<?php echo e(count($calendars)); ?>">
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
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($omittedMonths > 0): ?>
                    <p class="rt-service-detail-summary__note" data-service-detail-omitted-months="<?php echo e($omittedMonths); ?>"><?php echo e($omittedMonths); ?> <?php echo e($omittedMonths === 1 ? 'weiterer Monat liegt' : 'weitere Monate liegen'); ?> zwischen den gezeigten Monaten.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php else: ?>
                <p class="rt-service-detail-summary__empty"><?php echo e($from && $until ? 'Kein gültiger Zeitraum hinterlegt.' : 'Zeitraum nicht hinterlegt.'); ?></p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </section>

        <section class="rt-service-detail-summary__location" aria-label="Ort der Leistung">
            <div class="rt-service-detail-summary__section-heading"><h3><i class="far fa-map-marker-alt" aria-hidden="true"></i> Einsatzort</h3></div>
            <strong class="rt-service-detail-summary__location-name"><?php echo e($location); ?></strong>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($address): ?><p class="rt-service-detail-summary__note"><?php echo e($address); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if (isset($component)) { $__componentOriginal8e63117d4f57364d8602eb85edbb579c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8e63117d4f57364d8602eb85edbb579c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.timeline-mini-map','data' => ['preview' => $locationPreview,'location' => $order?->location_name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.timeline-mini-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['preview' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($locationPreview),'location' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($order?->location_name)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8e63117d4f57364d8602eb85edbb579c)): ?>
<?php $attributes = $__attributesOriginal8e63117d4f57364d8602eb85edbb579c; ?>
<?php unset($__attributesOriginal8e63117d4f57364d8602eb85edbb579c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8e63117d4f57364d8602eb85edbb579c)): ?>
<?php $component = $__componentOriginal8e63117d4f57364d8602eb85edbb579c; ?>
<?php unset($__componentOriginal8e63117d4f57364d8602eb85edbb579c); ?>
<?php endif; ?>
        </section>
    </div>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-detail-summary.blade.php ENDPATH**/ ?>