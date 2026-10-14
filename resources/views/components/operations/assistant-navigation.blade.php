@props([
    'tabs' => ['chat' => 'Chat', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'activity' => 'Aktivitäten'],
    'selected' => 'chat',
    'action' => 'setTab',
    'idPrefix' => 'ai-assist',
    'controls' => 'ai-assist-content',
    'reviewCount' => 0,
    'label' => 'Assistentenbereiche',
])
@php
    $icons = [
        'chat' => ['M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-6 4V6a2 2 0 0 1 2-2Z', 'M7 9h10M7 13h6'],
        'intake' => ['M5 4h14l3 10v5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-5L5 4Z', 'M2 14h6l2 3h4l2-3h6M8 8h8'],
        'actions' => ['m12 3 1.8 6.2L20 11l-6.2 1.8L12 19l-1.8-6.2L4 11l6.2-1.8L12 3Z', 'M19 17v5M16.5 19.5h5'],
        'activity' => ['M21 12a9 9 0 1 1-2.6-6.4L21 8', 'M21 3v5h-5M12 7v5l3 2'],
    ];
    $safeTabs = array_intersect_key(is_array($tabs) ? $tabs : [], $icons);
    $safeAction = in_array($action, ['setTab', 'setOperationsTab'], true) ? $action : null;
    $tabValue = array_key_exists($selected, $safeTabs) ? $selected : array_key_first($safeTabs);
    $count = max(0, (int) $reviewCount);
@endphp
<nav {{ $attributes->class(['rt-assistant-nav']) }} role="tablist" aria-label="{{ $label }}" aria-orientation="horizontal"
    data-assistant-navigation wire:key="{{ $idPrefix }}-navigation" wire:loading.attr="aria-busy"
    x-id="['assistant-nav-tooltip']"
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
        buttons() { return Array.from(this.$root.querySelectorAll('[data-assistant-nav-tab]')).filter(button => !button.disabled); },
        rememberFocus(event) { const button = event.target.closest('[data-assistant-nav-tab]'); if (button && !button.disabled) this.focusAfterLoad = button.dataset.tab; },
        handleKey(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || !event.target.closest('[data-assistant-nav-tab]')) return;
            event.preventDefault();
            if (this.$root.getAttribute('aria-busy') === 'true') return;
            const buttons = this.buttons();
            if (!buttons.length) return;
            let index = buttons.indexOf(event.target.closest('[data-assistant-nav-tab]'));
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
    x-on:focusin.window="if (focusAfterLoad !== null && $event.target !== document.body && !$root.contains($event.target)) focusAfterLoad = null">
    @foreach($safeTabs as $key => $tabLabel)
        @php($accessibleLabel = $key === 'intake' && $count > 0 ? $tabLabel.' · '.$count.' zur Prüfung' : $tabLabel)
        <button type="button" class="rt-assistant-nav__tab" role="tab" id="{{ $idPrefix }}-tab-{{ $key }}"
            aria-controls="{{ $controls }}" aria-selected="{{ $selected === $key ? 'true' : 'false' }}" aria-label="{{ $accessibleLabel }}"
            title="{{ $accessibleLabel }}" tabindex="{{ $tabValue === $key && $safeAction ? '0' : '-1' }}" data-assistant-nav-tab data-tab="{{ $key }}" data-tooltip-text="{{ $accessibleLabel }}"
            wire:key="{{ $idPrefix }}-nav-{{ $key }}" @if($safeAction) wire:click="{{ $safeAction }}('{{ $key }}')" wire:loading.attr="disabled" @else disabled @endif
            x-on:pointerenter="if ($event.pointerType !== 'touch') showTooltip($el)" x-on:pointerleave="if (document.activeElement !== $el) hideTooltip()"
            x-on:focus="if (!touchInteraction) showTooltip($el)" x-on:blur="hideTooltip()"
            x-bind:title="tooltipOpen && tooltipTarget === $el ? null : $el.dataset.tooltipText"
            x-bind:aria-describedby="tooltipOpen && tooltipTarget === $el ? $id('assistant-nav-tooltip') : null">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">@foreach($icons[$key] as $path)<path d="{{ $path }}" />@endforeach</svg>
            <span class="sr-only">{{ $tabLabel }}</span>
            @if($key === 'intake' && $count > 0)<span class="rt-assistant-nav__count" aria-hidden="true">{{ $count > 99 ? '99+' : $count }}</span>@endif
        </button>
    @endforeach
    <template x-teleport="body" wire:ignore>
        <span x-ref="tooltip" x-bind:id="$id('assistant-nav-tooltip')" role="tooltip" class="rt-assistant-nav__tooltip"
            x-show="tooltipOpen" x-bind:style="{ visibility: tooltipReady ? 'visible' : 'hidden' }" x-text="tooltipText" x-cloak></span>
    </template>
</nav>
