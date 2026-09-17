@props([
    'options' => [],
    'value' => null,
    'action' => null,
    'label' => 'Ansicht wählen',
    'id' => null,
    'disabled' => false,
])

@php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) ? $action : null;
    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || $safeAction === null;
    $selectedValue = is_string($value) || is_int($value) ? (string) $value : null;
    $normalizedOptions = [];
    $seenValues = [];
    foreach (is_array($options) ? $options : [] as $option) {
        if (!is_array($option) || !isset($option['value'], $option['label'], $option['icon'])
            || (!is_string($option['value']) && !is_int($option['value']))
            || !is_string($option['label']) || trim($option['label']) === ''
            || !is_string($option['icon']) || !preg_match('/^fa-[a-z0-9-]+$/D', $option['icon'])) {
            continue;
        }
        $optionValue = (string) $option['value'];
        if (in_array($optionValue, $seenValues, true)) continue;
        $seenValues[] = $optionValue;
        $normalizedOptions[] = [
            'value' => $optionValue,
            'label' => trim($option['label']),
            'icon' => $option['icon'],
            'disabled' => $isDisabled || filter_var($option['disabled'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }
    $enabledOptions = collect($normalizedOptions)->reject(fn ($option) => $option['disabled']);
    $tabValue = $enabledOptions->first(fn ($option) => $option['value'] === $selectedValue)['value'] ?? $enabledOptions->first()['value'] ?? null;
@endphp

<div
    {{ $attributes->class(['rt-multi-toggle'])->merge(array_filter(['id' => $id])) }}
    role="group"
    aria-label="{{ $label }}"
    @if($isDisabled) aria-disabled="true" @endif
    @if($safeAction) wire:loading.attr="aria-busy" wire:target="{{ $safeAction }}" @endif
    data-multi-toggle
    x-id="['multi-toggle-tooltip']"
    x-data="{
        tooltipOpen: false,
        tooltipReady: false,
        tooltipText: '',
        tooltipTarget: null,
        touchInteraction: false,
        focusAfterLoad: null,
        wasBusy: false,
        observer: null,
        init() {
            this.observer = new MutationObserver(() => {
                if (this.$root.getAttribute('aria-busy') === 'true') {
                    this.wasBusy = true;
                    this.hideTooltip();
                    return;
                }
                if (!this.wasBusy) return;
                this.wasBusy = false;
                const key = this.focusAfterLoad;
                this.focusAfterLoad = null;
                this.$nextTick(() => {
                    if (key === null || (document.activeElement !== document.body && !this.$root.contains(document.activeElement))) return;
                    this.buttons().find(button => button.dataset.toggleValue === key)?.focus({ preventScroll: true });
                });
            });
            this.observer.observe(this.$root, { attributes: true, attributeFilter: ['aria-busy'] });
        },
        destroy() { this.observer?.disconnect(); },
        buttons() {
            return Array.from(this.$root.querySelectorAll('[data-multi-toggle-option]')).filter(button => !button.disabled);
        },
        rememberFocus(event) {
            const button = event.target.closest('[data-multi-toggle-option]');
            if (button && !button.disabled) this.focusAfterLoad = button.dataset.toggleValue;
        },
        handleKey(event) {
            const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
            if (!keys.includes(event.key) || !event.target.closest('[data-multi-toggle-option]')) return;
            event.preventDefault();
            if (this.$root.getAttribute('aria-busy') === 'true') return;
            const buttons = this.buttons();
            if (!buttons.length) return;
            let index = buttons.indexOf(event.target.closest('[data-multi-toggle-option]'));
            const rtl = getComputedStyle(this.$root).direction === 'rtl';
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = buttons.length - 1;
            else {
                let step = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1;
                if (rtl && ['ArrowLeft', 'ArrowRight'].includes(event.key)) step *= -1;
                index = (index + step + buttons.length) % buttons.length;
            }
            buttons.forEach((button, buttonIndex) => button.tabIndex = buttonIndex === index ? 0 : -1);
            buttons[index].focus({ preventScroll: true });
            buttons[index].click();
        },
        showTooltip(button) {
            if (button.disabled || this.$root.getAttribute('aria-busy') === 'true') return;
            this.tooltipTarget = button;
            this.tooltipText = button.dataset.tooltipText;
            this.tooltipReady = false;
            this.tooltipOpen = true;
            this.$nextTick(() => {
                if (!this.tooltipOpen || this.tooltipTarget !== button) return;
                const tooltip = this.$refs.tooltip;
                const anchor = button.getBoundingClientRect();
                const viewport = window.visualViewport;
                const left = viewport?.offsetLeft || 0;
                const top = viewport?.offsetTop || 0;
                const width = viewport?.width || window.innerWidth;
                const height = viewport?.height || window.innerHeight;
                tooltip.style.maxWidth = Math.max(0, Math.min(288, width - 16)) + 'px';
                const bounds = tooltip.getBoundingClientRect();
                const x = Math.max(left + 8, Math.min(anchor.left + (anchor.width - bounds.width) / 2, left + width - bounds.width - 8));
                let y = anchor.top - bounds.height - 8;
                if (y < top + 8) y = anchor.bottom + 8;
                y = Math.max(top + 8, Math.min(y, top + height - bounds.height - 8));
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
                tooltip.style.zIndex = Math.max(250, Number(this.$root.closest('[data-rt-overlay-base]')?.dataset.rtOverlayBase || 0) + 30);
                this.tooltipReady = true;
            });
        },
        hideTooltip() { this.tooltipOpen = false; this.tooltipReady = false; this.tooltipTarget = null; },
    }"
    x-on:keydown="handleKey($event)"
    x-on:keydown.window="touchInteraction = false"
    x-on:pointerdown.capture="touchInteraction = $event.pointerType === 'touch'; if (touchInteraction) hideTooltip()"
    x-on:keydown.escape="hideTooltip()"
    x-on:click.capture="rememberFocus($event)"
    x-on:resize.window="hideTooltip()"
    x-on:scroll.window.capture="hideTooltip()"
    x-on:focusin.window="if (focusAfterLoad !== null && $event.target !== document.body && !$root.contains($event.target)) focusAfterLoad = null"
>
    @foreach($normalizedOptions as $option)
        @php
            // Keep values raw until the single attribute-bag merge below.
            $buttonAttributes = [
                'type' => 'button',
                'class' => 'rt-multi-toggle__button',
                'data-multi-toggle-option' => '',
                'data-toggle-value' => $option['value'],
                'data-tooltip-text' => $option['label'],
                'aria-label' => $option['label'],
                'aria-pressed' => $selectedValue === $option['value'] ? 'true' : 'false',
                'tabindex' => !$option['disabled'] && $option['value'] === $tabValue ? '0' : '-1',
                'title' => $option['label'],
            ];
            if ($id) $buttonAttributes['wire:key'] = $id.'-'.substr(hash('sha256', $option['value']), 0, 16);
            if ($option['disabled']) {
                $buttonAttributes += ['disabled' => true, 'aria-disabled' => 'true'];
            } else {
                $buttonAttributes += [
                    'wire:click' => $safeAction.'('.\Illuminate\Support\Js::from($option['value'])->toHtml().')',
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => $safeAction,
                ];
            }
        @endphp
        <x-ui.buttons.button-basic
            mode="basic"
            {{ $attributes->only([])->merge($buttonAttributes) }}
            x-on:pointerenter="if ($event.pointerType !== 'touch') showTooltip($el)"
            x-on:pointerleave="if (document.activeElement !== $el) hideTooltip()"
            x-on:focus="if (!touchInteraction) showTooltip($el)"
            x-on:blur="hideTooltip()"
            x-bind:title="tooltipOpen && tooltipTarget === $el ? null : $el.dataset.tooltipText"
            x-bind:aria-describedby="tooltipOpen && tooltipTarget === $el ? $id('multi-toggle-tooltip') : null"
        >
            <i class="far {{ $option['icon'] }}" aria-hidden="true"></i>
            <span class="sr-only">{{ $option['label'] }}</span>
        </x-ui.buttons.button-basic>
    @endforeach
    <template x-teleport="body" wire:ignore>
        <span
            x-ref="tooltip"
            x-bind:id="$id('multi-toggle-tooltip')"
            role="tooltip"
            class="rt-multi-toggle__tooltip"
            x-show="tooltipOpen"
            x-bind:style="{ visibility: tooltipReady ? 'visible' : 'hidden' }"
            x-text="tooltipText"
            x-cloak
        ></span>
    </template>
</div>
