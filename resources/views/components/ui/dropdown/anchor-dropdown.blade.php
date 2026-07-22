@props([
  'align'             => 'right',
  'width'             => '48',
  'contentClasses'    => 'py-1 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-white',
  'dropdownClasses'   => '',
  'offset'            => 8,
  'overlay'           => false,
  'trap'              => false,
  'scrollOnOpen'      => false,
  'scrollOnTrigger'   => false,
  'headerOffset'      => 0,
  'matchTriggerWidth' => false,
  'triggerClasses'    => 'inline-flex',
])

@php
  $widthClass = match((string) $width) {
    '40', 'w-40' => 'w-40',
    '48', 'w-48' => 'w-48',
    '56', 'w-56' => 'w-56',
    '64', 'w-64' => 'w-64',
    '72', 'w-72' => 'w-72',
    '80', 'w-80' => 'w-80',
    '96', 'w-96' => 'w-96',
    'auto', 'w-auto' => 'w-auto',
    'min', 'w-min' => 'w-min',
    'max', 'w-max' => 'w-max',
    'full', 'w-full' => 'w-full',
    default => 'w-48',
  };
  $matchesTriggerWidth = (bool) $matchTriggerWidth || $widthClass === 'w-full';
  // A teleported fixed panel cannot use Tailwind's viewport-relative w-full.
  // Its exact width is assigned from the trigger inside positionPanel().
  $panelWidthClass = $matchesTriggerWidth ? 'w-auto' : $widthClass;
@endphp

<div
  {{ $attributes->class('relative inline-flex') }}
  x-data="{
    open: false,
    placement: 'bottom',
    positionFrame: null,
    resizeHandler: null,
    scrollHandler: null,
    align: @js((string) $align),
    offset: @js(max(0, (int) $offset)),
    gutter: 12,
    scrollOnOpen: @js((bool) $scrollOnOpen),
    scrollOnTrigger: @js((bool) $scrollOnTrigger),
    headerOffset: @js((int) $headerOffset),
    matchTriggerWidth: @js($matchesTriggerWidth),

    init() {
      this.resizeHandler = () => this.schedulePosition();
      this.scrollHandler = () => this.schedulePosition();

      window.addEventListener('resize', this.resizeHandler, { passive: true });
      document.addEventListener('scroll', this.scrollHandler, true);

      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', this.resizeHandler, { passive: true });
        window.visualViewport.addEventListener('scroll', this.scrollHandler, { passive: true });
      }

      this.$watch('open', (isOpen) => {
        this.syncTriggerAccessibility();

        if (!isOpen) return;

        this.$nextTick(() => {
          this.positionPanel();

          if (this.$refs.panelScroll) {
            this.$refs.panelScroll.scrollTo({ top: 0, behavior: 'auto' });
          }

          if (this.scrollOnOpen) {
            this.scrollOnTrigger ? this.scrollToTrigger() : this.scrollPanelCentered();
          }

          this.schedulePosition();
        });
      });

      this.$nextTick(() => this.syncTriggerAccessibility());
    },

    destroy() {
      window.removeEventListener('resize', this.resizeHandler);
      document.removeEventListener('scroll', this.scrollHandler, true);

      if (window.visualViewport) {
        window.visualViewport.removeEventListener('resize', this.resizeHandler);
        window.visualViewport.removeEventListener('scroll', this.scrollHandler);
      }

      window.cancelAnimationFrame(this.positionFrame || 0);
    },

    clamp(value, minimum, maximum) {
      return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
    },

    toggle() {
      this.open = !this.open;

      if (this.open) {
        this.$dispatch('dropdown-open');
      }
    },

    close() {
      this.open = false;
    },

    schedulePosition() {
      if (!this.open) return;

      window.cancelAnimationFrame(this.positionFrame || 0);
      this.positionFrame = window.requestAnimationFrame(() => this.positionPanel());
    },

    positionPanel() {
      const trigger = this.$refs.trigger;
      const panel = this.$refs.panel;
      const panelScroll = this.$refs.panelScroll;

      if (!this.open || !trigger || !panel) return;

      const visualViewport = window.visualViewport;
      const viewportWidth = visualViewport ? visualViewport.width : (document.documentElement.clientWidth || window.innerWidth);
      const viewportHeight = visualViewport ? visualViewport.height : (document.documentElement.clientHeight || window.innerHeight);
      const viewportLeft = visualViewport ? visualViewport.offsetLeft : 0;
      const viewportTop = visualViewport ? visualViewport.offsetTop : 0;
      const viewportRight = viewportLeft + viewportWidth;
      const viewportBottom = viewportTop + viewportHeight;
      const maximumViewportWidth = Math.max(0, viewportWidth - (this.gutter * 2));
      const maximumViewportHeight = Math.max(0, viewportHeight - (this.gutter * 2));
      const triggerRect = trigger.getBoundingClientRect();

      panel.style.maxWidth = `${maximumViewportWidth}px`;
      panel.style.maxHeight = `${maximumViewportHeight}px`;
      panel.style.width = this.matchTriggerWidth
        ? `${Math.min(triggerRect.width, maximumViewportWidth)}px`
        : '';

      if (panelScroll) {
        panelScroll.style.maxHeight = '';
      }

      const panelRect = panel.getBoundingClientRect();
      const panelWidth = Math.min(panelRect.width, maximumViewportWidth);
      const preferredLeft = this.align === 'left' ? triggerRect.left : triggerRect.right - panelWidth;
      const left = this.clamp(
        preferredLeft,
        viewportLeft + this.gutter,
        viewportRight - this.gutter - panelWidth,
      );
      const spaceBelow = Math.max(0, viewportBottom - this.gutter - triggerRect.bottom - this.offset);
      const spaceAbove = Math.max(0, triggerRect.top - viewportTop - this.gutter - this.offset);

      this.placement = this.align === 'top'
        ? ((panelRect.height <= spaceAbove || spaceAbove >= spaceBelow) ? 'top' : 'bottom')
        : ((panelRect.height <= spaceBelow || spaceBelow >= spaceAbove) ? 'bottom' : 'top');

      const availableHeight = this.placement === 'bottom' ? spaceBelow : spaceAbove;
      const maxHeight = Math.min(maximumViewportHeight, availableHeight);
      const panelHeight = Math.min(panelRect.height, maxHeight);
      const preferredTop = this.placement === 'bottom'
        ? triggerRect.bottom + this.offset
        : triggerRect.top - this.offset - panelHeight;
      const top = this.clamp(
        preferredTop,
        viewportTop + this.gutter,
        viewportBottom - this.gutter - panelHeight,
      );
      const triggerCenter = triggerRect.left + (triggerRect.width / 2);
      const caretInset = Math.min(22, Math.max(8, panelWidth / 2));
      const caretX = this.clamp(triggerCenter - left, caretInset, panelWidth - caretInset);

      panel.style.left = `${Math.round(left)}px`;
      panel.style.top = `${Math.round(top)}px`;
      panel.style.maxHeight = `${Math.floor(maxHeight)}px`;
      panel.style.setProperty('--rt-dropdown-caret-x', `${Math.round(caretX)}px`);

      if (panelScroll) {
        panelScroll.style.maxHeight = `${Math.floor(maxHeight)}px`;
      }
    },

    syncTriggerAccessibility() {
      const trigger = this.$refs.trigger;
      if (!trigger) return;

      const control = trigger.querySelector('button, a, [role=button]');
      if (control) control.setAttribute('aria-expanded', this.open.toString());
    },

    scrollToTrigger() {
      const trigger = this.$refs.trigger;
      if (!trigger) return;

      const y = trigger.getBoundingClientRect().top + window.scrollY - this.headerOffset;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    },

    scrollPanelCentered() {
      const panel = this.$refs.panel;
      if (!panel) return;

      window.requestAnimationFrame(() => {
        const rect = panel.getBoundingClientRect();
        const centerOffset = (window.innerHeight - rect.height) / 2;
        const target = rect.top + window.scrollY - Math.max(0, this.headerOffset - centerOffset);
        window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
      });
    },
  }"
  x-cloak
  @keydown.escape.window="close()"
  @close.window.stop="close()"
>
  <div class="{{ $triggerClasses }}" x-ref="trigger" @click="toggle()">
    {{ $trigger }}
  </div>

  @if($overlay)
    <template x-teleport="body">
      <div x-show="open" x-transition.opacity class="fixed inset-0 z-[170] bg-black/40" @click="close()" style="display:none;"></div>
    </template>
  @endif

  <template x-teleport="body">
    <div
      x-show="open"
      x-transition:enter="transition ease-out duration-200"
      x-transition:enter-start="transform opacity-0 scale-95"
      x-transition:enter-end="transform opacity-100 scale-100"
      x-transition:leave="transition ease-in duration-100"
      x-transition:leave-start="transform opacity-100 scale-100"
      x-transition:leave-end="transform opacity-0 scale-95"
      x-bind:data-placement="placement"
      class="rt-viewport-dropdown fixed z-[180] {{ $panelWidthClass }} rounded-xl shadow-rt-md {{ $dropdownClasses }}"
      style="display:none; left:12px; top:12px; margin:0; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px);"
      data-rt-dropdown-panel
      @click.outside="if (!$refs.trigger.contains($event.target)) close()"
      @if($trap) x-trap.inert.noscroll="open" @endif
      x-ref="panel"
    >
      <span
        aria-hidden="true"
        class="rt-ui-dropdown-caret pointer-events-none absolute z-[1] h-2.5 w-2.5 -translate-x-1/2 rotate-45 border border-rt-border bg-rt-surface dark:border-rt-dark-border dark:bg-rt-dark-surface"
        data-rt-dropdown-caret
      ></span>

      <div
        x-ref="panelScroll"
        role="menu"
        class="rt-ui-surface rt-ui-dropdown-panel relative z-[2] max-h-[min(28rem,calc(100dvh-2rem))] overflow-y-auto rounded-xl border border-rt-border shadow-rt-md dark:border-rt-dark-border {{ $contentClasses }}"
        @click="if ($event.target.closest('a, button, [role=menuitem]')) close()"
      >
        {{ $content }}
      </div>
    </div>
  </template>
</div>
