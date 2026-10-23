<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'min' => null,
    'max' => null,
    'disabled' => false,
    'readonly' => false,
    'required' => false,
    'clearable' => true,
    'ariaLabel' => null,
    'keepDropdownOpen' => false,
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
    'min' => null,
    'max' => null,
    'disabled' => false,
    'readonly' => false,
    'required' => false,
    'clearable' => true,
    'ariaLabel' => null,
    'keepDropdownOpen' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>


<?php
    $locale = app()->getLocale() === 'de' ? 'de-DE' : 'en-GB';
    $wireModel = $attributes->wire('model')->value();
    $wireLive = $attributes->wire('model')->hasModifier('live');
    $fieldId = $attributes->get('id');
    $controlLabel = $ariaLabel ?: $attributes->get('aria-label') ?: __('app.date');
    $alpineConfig = [
        'locale' => $locale,
        'weekStart' => 1,
        'min' => $min,
        'max' => $max,
        'disabled' => (bool) $disabled,
        'readonly' => (bool) $readonly,
        'clearable' => (bool) $clearable,
        'value' => $wireModel ? '' : (string) $attributes->get('value', ''),
    ];
    $outerAttributes = $attributes->whereDoesntStartWith('wire:model')->except([
        'id', 'value', 'name', 'required', 'aria-label', 'aria-describedby', 'aria-invalid',
    ]);
    $previousYearLabel = $locale === 'de-DE' ? 'Vorheriges Jahr' : 'Previous year';
    $nextYearLabel = $locale === 'de-DE' ? 'Nächstes Jahr' : 'Next year';
?>

<div
    x-data="rtDateField({ ...<?php echo e(\Illuminate\Support\Js::from($alpineConfig)); ?><?php if($wireModel): ?>, value: $wire.entangle(<?php echo e(\Illuminate\Support\Js::from($wireModel)); ?>)<?php echo e($wireLive ? '.live' : ''); ?><?php endif; ?> })"
    x-modelable="value"
    x-id="['rt-date-panel', 'rt-date-heading']"
    @keydown.escape="if (open) { $event.preventDefault(); $event.stopPropagation(); closePanel(true); }"
    <?php echo e($outerAttributes->merge(['class' => 'rt-ui-date-field relative'])); ?>

    data-rt-date-field
    <?php if($wireModel): ?>
        data-autosave-field
        data-autosave-model="<?php echo e($wireModel); ?>"
        data-autosave-field-id="<?php echo e($fieldId ?: $wireModel); ?>"
        data-autosave-state="idle"
    <?php endif; ?>
>
    <div
        x-ref="anchor"
        class="rt-ui-date-field__shell rt-ui-field-shell"
        :data-open="open ? 'true' : 'false'"
        :data-disabled="locked ? 'true' : 'false'"
    >
        <input
            x-ref="display"
            <?php if($fieldId): ?> id="<?php echo e($fieldId); ?>" <?php endif; ?>
            type="text"
            inputmode="numeric"
            autocomplete="off"
            placeholder="<?php echo e(__('app.date_format_hint')); ?>"
            x-model="display"
            @blur="commitTyped()"
            @keydown.enter.prevent="commitTyped(); closePanel()"
            @keydown.down.prevent="openPanel($event.currentTarget)"
            <?php if($disabled): echo 'disabled'; endif; ?>
            <?php if($readonly): echo 'readonly'; endif; ?>
            <?php if($required): echo 'required'; endif; ?>
            aria-label="<?php echo e($controlLabel); ?>"
            aria-haspopup="dialog"
            :aria-controls="$id('rt-date-panel')"
            :aria-expanded="open"
            <?php if($attributes->has('aria-describedby')): ?> aria-describedby="<?php echo e($attributes->get('aria-describedby')); ?>" <?php endif; ?>
            <?php if($attributes->has('aria-invalid')): ?> aria-invalid="<?php echo e($attributes->get('aria-invalid')); ?>" <?php endif; ?>
            class="rt-ui-date-field__display"
        >
        <button
            type="button"
            @click="togglePanel($event.currentTarget)"
            <?php if($disabled || $readonly): echo 'disabled'; endif; ?>
            class="rt-ui-date-field__trigger"
            :aria-expanded="open"
            :aria-controls="$id('rt-date-panel')"
            aria-haspopup="dialog"
            aria-label="<?php echo e(__('app.date_choose')); ?>: <?php echo e($controlLabel); ?>"
        >
            <i class="far fa-calendar-days" aria-hidden="true"></i>
        </button>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($attributes->has('name')): ?>
        <input type="hidden" name="<?php echo e($attributes->get('name')); ?>" :value="value" <?php if($disabled): echo 'disabled'; endif; ?> />
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <template x-teleport="body" wire:ignore>
        
        <div
            x-show.important="open"
            x-cloak
            x-transition.opacity.duration.150ms
            @click.outside="if (!$refs.anchor.contains($event.target)) closePanel()"
            @keydown="handlePanelKeydown($event)"
            x-ref="panel"
            :id="$id('rt-date-panel')"
            role="dialog"
            aria-modal="false"
            :aria-labelledby="$id('rt-date-heading')"
            tabindex="-1"
            :style="panelStyle"
            class="rt-ui-date-panel fixed"
            <?php if($keepDropdownOpen): ?> data-rt-dropdown-keep-open <?php endif; ?>
        >
            <div class="rt-ui-date-panel__header">
                <button type="button" @click="shiftMonth(-12)" :disabled="!canShiftMonth(-12)" class="rt-ui-date-nav" aria-label="<?php echo e($previousYearLabel); ?>">
                    <i class="far fa-angles-left" aria-hidden="true"></i>
                </button>
                <button type="button" @click="shiftMonth(-1)" :disabled="!canShiftMonth(-1)" class="rt-ui-date-nav" aria-label="<?php echo e(__('app.date_previous_month')); ?>">
                    <i class="far fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div :id="$id('rt-date-heading')" class="rt-ui-date-panel__heading" aria-live="polite" aria-atomic="true">
                    <strong x-text="monthName"></strong>
                    <span x-text="viewYear"></span>
                </div>
                <button type="button" @click="shiftMonth(1)" :disabled="!canShiftMonth(1)" class="rt-ui-date-nav" aria-label="<?php echo e(__('app.date_next_month')); ?>">
                    <i class="far fa-chevron-right" aria-hidden="true"></i>
                </button>
                <button type="button" @click="shiftMonth(12)" :disabled="!canShiftMonth(12)" class="rt-ui-date-nav" aria-label="<?php echo e($nextYearLabel); ?>">
                    <i class="far fa-angles-right" aria-hidden="true"></i>
                </button>
            </div>

            <div class="rt-ui-date-panel__body">
                <div class="rt-ui-date-panel__grid" role="grid" :aria-label="monthLabel" @keydown="handleGridKeydown($event)">
                    <div class="rt-ui-date-panel__weekdays" role="row">
                        <template x-for="weekday in weekdayLabels" :key="weekday">
                            <span role="columnheader" x-text="weekday"></span>
                        </template>
                    </div>
                    <template x-for="week in weeks" :key="week[0].iso">
                        <div class="rt-ui-date-panel__week" role="row">
                            <template x-for="day in week" :key="day.iso">
                                <button
                                    type="button"
                                    role="gridcell"
                                    @click="select(day.iso)"
                                    @focus="focusedIso = day.iso"
                                    :disabled="day.disabled"
                                    :tabindex="day.focused ? 0 : -1"
                                    :data-date-focused="day.focused ? 'true' : 'false'"
                                    :data-outside="day.outside ? 'true' : 'false'"
                                    :data-today="day.today ? 'true' : 'false'"
                                    :aria-current="day.today ? 'date' : null"
                                    :aria-selected="day.selected"
                                    :aria-label="formatLong(day.iso)"
                                    class="rt-ui-date-day"
                                    :class="day.selected ? 'rt-ui-date-day--selected' : ''"
                                    x-text="day.label"
                                ></button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="rt-ui-date-panel__footer">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($clearable): ?>
                    <button type="button" @click="clear()" class="rt-ui-date-action" x-show="hasValue">
                        <?php echo e(__('app.date_clear')); ?>

                    </button>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <button type="button" @click="selectToday()" :disabled="!todaySelectable" class="rt-ui-date-action rt-ui-date-action--accent">
                    <i class="far fa-calendar-day" aria-hidden="true"></i>
                    <?php echo e(__('app.today')); ?>

                </button>
            </div>
        </div>
    </template>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/forms/date-field.blade.php ENDPATH**/ ?>