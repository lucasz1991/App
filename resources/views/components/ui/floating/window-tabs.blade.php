@props([
    'tabs' => [],
    'selected' => null,
    'action' => null,
    'idPrefix' => 'floating-window',
    'controls' => null,
    'label' => 'Fensterbereiche',
    'appearance' => 'window',
    'clientState' => null,
    'clientAction' => null,
    'clientKeyboardAction' => null,
    'tabDataAttribute' => null,
    'tabClass' => null,
    'countClass' => null,
])
@php
    $validIdentifier = static fn ($value) => is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $value);
    $client = $validIdentifier($clientState) && $validIdentifier($clientAction) && $validIdentifier($clientKeyboardAction);
    $safeAction = $validIdentifier($action) ? $action : null;
    $safeTabs = array_filter(is_array($tabs) ? $tabs : [], static fn ($tab, $key) => is_array($tab) && preg_match('/\A[a-z][a-z0-9-]*\z/', (string) $key), ARRAY_FILTER_USE_BOTH);
    $tabValue = array_key_exists($selected ?? '', $safeTabs) ? $selected : array_key_first($safeTabs);
    $dataAttribute = is_string($tabDataAttribute) && preg_match('/\Adata-[a-z][a-z0-9-]*\z/', $tabDataAttribute) ? $tabDataAttribute : null;
    $appearance = $appearance === 'surface' ? 'surface' : 'window';
@endphp
<nav {{ $attributes->class(['rt-window-tabs', 'rt-window-tabs--'.$appearance]) }} role="tablist" aria-label="{{ $label }}" aria-orientation="horizontal"
    data-window-tabs wire:key="{{ $idPrefix }}-navigation"
    @unless($client)
    wire:loading.attr="aria-busy" x-id="['window-tabs-tooltip']"
    x-data="{
        tooltipOpen: false, tooltipReady: false, tooltipText: '', tooltipTarget: null, touchInteraction: false, observer: null, focusAfterLoad: null, wasBusy: false,
        init() {
            this.observer = new MutationObserver(() => {
                if (this.$root.getAttribute('aria-busy') === 'true') { this.wasBusy = true; this.hideTooltip(); return; }
                if (!this.wasBusy) return;
                this.wasBusy = false;
                const key = this.focusAfterLoad; this.focusAfterLoad = null;
                this.$nextTick(() => {
                    if (key === null || (document.activeElement !== document.body && !this.$root.contains(document.activeElement))) return;
                    this.buttons().find(button => button.dataset.tab === key)?.focus({ preventScroll: true });
                });
            });
            this.observer.observe(this.$root, { attributes: true, attributeFilter: ['aria-busy'] });
        },
        destroy() { this.observer?.disconnect(); },
        buttons() { return Array.from(this.$root.querySelectorAll('[data-window-tab]')).filter(button => !button.disabled); },
        rememberFocus(event) { const button = event.target.closest('[data-window-tab]'); if (button && !button.disabled) this.focusAfterLoad = button.dataset.tab; },
        handleKey(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || !event.target.closest('[data-window-tab]')) return;
            event.preventDefault();
            if (this.$root.getAttribute('aria-busy') === 'true') return;
            const buttons = this.buttons();
            if (!buttons.length) return;
            let index = buttons.indexOf(event.target.closest('[data-window-tab]'));
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = buttons.length - 1;
            else {
                const rtl = getComputedStyle(this.$root).direction === 'rtl';
                const step = (event.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1);
                index = (index + step + buttons.length) % buttons.length;
            }
            buttons.forEach((button, buttonIndex) => button.tabIndex = buttonIndex === index ? 0 : -1);
            buttons[index].focus({ preventScroll: true });
        },
        showTooltip(button) {
            if (button.disabled || this.$root.getAttribute('aria-busy') === 'true') return;
            if (button.querySelector('.rt-window-tabs__label')?.textContent.trim() === button.dataset.tooltipText) return;
            this.tooltipTarget = button; this.tooltipText = button.dataset.tooltipText; this.tooltipReady = false; this.tooltipOpen = true;
            this.$nextTick(() => {
                if (!this.tooltipOpen || this.tooltipTarget !== button) return;
                const tooltip = this.$refs.tooltip, anchor = button.getBoundingClientRect(), viewport = window.visualViewport;
                const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0;
                const width = viewport?.width || window.innerWidth, height = viewport?.height || window.innerHeight;
                tooltip.style.maxWidth = Math.max(0, Math.min(288, width - 16)) + 'px';
                const bounds = tooltip.getBoundingClientRect();
                const x = Math.max(left + 8, Math.min(anchor.left + (anchor.width - bounds.width) / 2, left + width - bounds.width - 8));
                let y = anchor.bottom + 8;
                if (y + bounds.height > top + height - 8) y = anchor.top - bounds.height - 8;
                y = Math.max(top + 8, Math.min(y, top + height - bounds.height - 8));
                tooltip.style.left = x + 'px'; tooltip.style.top = y + 'px';
                tooltip.style.zIndex = Math.max(250, Number(this.$root.closest('[data-rt-overlay-base]')?.dataset.rtOverlayBase || 0) + 30);
                this.tooltipReady = true;
            });
        },
        hideTooltip() { this.tooltipOpen = false; this.tooltipReady = false; this.tooltipTarget = null; },
    }"
    x-on:keydown="handleKey($event)" x-on:keydown.window="touchInteraction = false"
    x-on:pointerdown.capture="touchInteraction = $event.pointerType === 'touch'; if (touchInteraction) hideTooltip()"
    x-on:keydown.escape="hideTooltip()" x-on:click.capture="rememberFocus($event); hideTooltip()"
    x-on:resize.window="hideTooltip()" x-on:scroll.window.capture="hideTooltip()"
    x-on:focusin.window="if (focusAfterLoad !== null && $event.target !== document.body && !$root.contains($event.target)) focusAfterLoad = null"
    @else
    x-on:keydown="{{ $clientKeyboardAction }}($event)"
    @endunless>
    @foreach($safeTabs as $key => $tab)
        @php
            $tabLabel = (string) ($tab['label'] ?? $key);
            $accessibleLabel = (string) ($tab['accessibleLabel'] ?? $tabLabel);
            $count = max(0, (int) ($tab['count'] ?? 0));
        @endphp
        <button type="button" @class(['rt-window-tabs__tab', $tabClass]) role="tab" id="{{ $idPrefix }}-tab-{{ $key }}"
            aria-controls="{{ $tab['controls'] ?? $controls }}" aria-label="{{ $accessibleLabel }}" title="{{ $accessibleLabel }}"
            data-window-tab data-tab="{{ $key }}" @if($dataAttribute) {{ $dataAttribute }}="{{ $key }}" @endif
            wire:key="{{ $idPrefix }}-nav-{{ $key }}"
            @if($client)
                x-bind:aria-selected="({{ $clientState }} === '{{ $key }}').toString()" x-bind:tabindex="{{ $clientState }} === '{{ $key }}' ? 0 : -1"
                x-on:click="{{ $clientAction }}('{{ $key }}')"
            @else
                aria-selected="{{ $tabValue === $key ? 'true' : 'false' }}" tabindex="{{ $tabValue === $key && $safeAction ? '0' : '-1' }}"
                data-tooltip-text="{{ $accessibleLabel }}"
                @if($safeAction) wire:click="{{ $safeAction }}('{{ $key }}')" wire:loading.attr="disabled" @else disabled @endif
                x-on:pointerenter="if ($event.pointerType !== 'touch') showTooltip($el)" x-on:pointerleave="if (document.activeElement !== $el) hideTooltip()"
                x-on:focus="if (!touchInteraction) showTooltip($el)" x-on:blur="hideTooltip()"
                x-bind:title="tooltipOpen && tooltipTarget === $el ? null : $el.dataset.tooltipText"
                x-bind:aria-describedby="tooltipOpen && tooltipTarget === $el ? $id('window-tabs-tooltip') : null"
            @endif>
            <i class="far {{ $tab['icon'] ?? 'fa-circle' }}" aria-hidden="true"></i>
            <span class="rt-window-tabs__label">{{ $tabLabel }}</span>
            @if($count > 0)<span @class(['rt-window-tabs__count', $countClass]) aria-hidden="true">{{ $count > 99 ? '99+' : $count }}</span>@endif
        </button>
    @endforeach
    @unless($client)
    <template x-teleport="body" wire:ignore>
        <span x-ref="tooltip" x-bind:id="$id('window-tabs-tooltip')" role="tooltip" class="rt-window-tabs__tooltip"
            x-show="tooltipOpen" x-bind:style="{ visibility: tooltipReady ? 'visible' : 'hidden' }" x-text="tooltipText" x-cloak></span>
    </template>
    @endunless
</nav>
