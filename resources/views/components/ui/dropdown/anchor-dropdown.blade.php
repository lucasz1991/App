@props([
  'align'             => 'right',
  'width'             => '48',
  'maxHeight'         => 448,
  'fixedHeight'       => false,
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
  'contentRole'       => 'menu',
  'contentLabel'      => null,
  'dropdownId'        => null,
  'layerGroup'        => null,
  'anchorSelector'    => null,
  'openOnHover'       => false,
  'hoverOpenDelay'    => 100,
  'hoverCloseDelay'   => 180,
  'externalTrigger'  => false,
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
  // Its exact width is assigned from the trigger after Alpine anchored it.
  $panelWidthClass = $matchesTriggerWidth ? 'w-auto' : $widthClass;
  $anchorPlacement = match((string) $align) {
    'left' => 'bottom-start',
    'top' => 'top-end',
    default => 'bottom-end',
  };
  $anchorOffset = max(0, (int) $offset);
  $anchorCaretX = str_ends_with($anchorPlacement, '-start')
    ? '1.75rem'
    : 'calc(100% - 1.75rem)';
  $anchorConnectorSize = max(6, $anchorOffset + 2);
  $requestedDropdownId = trim((string) $dropdownId);
  $safeDropdownId = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $requestedDropdownId), '-');
  $resolvedDropdownId = filled($safeDropdownId)
    ? 'rt-dropdown-'.$safeDropdownId
    : 'rt-dropdown-'.\Illuminate\Support\Str::uuid();
  $dropdownPanelId = $resolvedDropdownId.'-content';
  $resolvedLayerGroup = trim((string) $layerGroup);
@endphp

<div
  {{ $attributes->class('relative inline-flex') }}
  data-rt-dropdown-root
  data-rt-dropdown-id="{{ $resolvedDropdownId }}"
  x-data="rtAnchorDropdown(@js([
      'openOnHover' => (bool) $openOnHover,
      'hoverOpenDelay' => max(0, (int) $hoverOpenDelay),
      'hoverCloseDelay' => max(0, (int) $hoverCloseDelay),
      'layerGroup' => $resolvedLayerGroup,
      'layerId' => $resolvedDropdownId,
      'anchorSelector' => filled($anchorSelector) ? (string) $anchorSelector : null,
      'horizontalAlign' => str_ends_with($anchorPlacement, '-start') ? 'left' : 'right',
      'preferredPlacement' => str_starts_with($anchorPlacement, 'top') ? 'top' : 'bottom',
      'offset' => $anchorOffset,
      'maximumHeight' => max(160, min(960, (int) $maxHeight)),
      'fixedHeight' => (bool) $fixedHeight,
      'scrollOnOpen' => (bool) $scrollOnOpen,
      'scrollOnTrigger' => (bool) $scrollOnTrigger,
      'headerOffset' => (int) $headerOffset,
      'matchTriggerWidth' => $matchesTriggerWidth,
      'panelId' => $dropdownPanelId,
      'popupRole' => $contentRole === 'dialog' ? 'dialog' : 'menu',
    ]))"
  x-cloak
  @keydown.escape.window="handleWindowEscape($event)"
  @close.window.stop="close()"
  @rt-navigation:prepare.window="close()"
  @rt-dropdown-parent-close.stop="close()"
  @rt-topbar-layer-open.window="handleLayerOpen($event)"
  @if($externalTrigger)
    @rt-anchor-dropdown-open.window="openFromAnchor($event)"
    @rt-anchor-dropdown-focus.window="focusExternalPanel($event)"
    @rt-anchor-dropdown-close.window="if ($event.detail?.id === layerId) close(true)"
  @endif
>
  <div
    class="rt-ui-dropdown-trigger {{ $triggerClasses }}"
    x-ref="trigger"
    data-rt-dropdown-trigger
    @click="toggle()"
    @pointerenter="enterHover($event, 'trigger')"
    @pointerleave="leaveHover($event, 'trigger')"
    @focusin="retainHoverFocus()"
    @focusout="scheduleHoverClose()"
    @if($openOnHover) @keydown.arrow-down.prevent.stop="focusHoverPanel()" @endif
    @keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); close(true) }"
  >
    {{ $trigger }}
  </div>

  @if($overlay)
    <template x-teleport="body">
      <div x-show="open" x-ref="overlay" x-transition.opacity data-rt-dropdown-owner="{{ $resolvedDropdownId }}" class="pointer-events-auto fixed inset-0 z-[170] bg-black/40" @click="close()" style="display:none;" aria-hidden="true"></div>
    </template>
  @endif

  <template x-teleport="body">
    <div
      x-show="open"
      x-effect="open && schedulePosition($el)"
        x-transition:enter="rt-motion-pop-enter"
        x-transition:enter-start="rt-motion-pop-enter-from"
        x-transition:enter-end="rt-motion-pop-enter-to"
        x-transition:leave="rt-motion-pop-leave"
        x-transition:leave-start="rt-motion-pop-leave-from"
        x-transition:leave-end="rt-motion-pop-leave-to"
        x-bind:data-placement="placement"
        data-rt-dropdown-owner="{{ $resolvedDropdownId }}"
        class="rt-viewport-dropdown pointer-events-auto fixed z-[180] {{ $panelWidthClass }} rounded-xl {{ $dropdownClasses }}"
        style="display:none; margin:0; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px); --rt-dropdown-caret-x:{{ $anchorCaretX }}; --rt-dropdown-connector-size:{{ $anchorConnectorSize }}px;"
        data-rt-dropdown-panel
        @click.outside="handleOutsideClick($event)"
        @pointerenter="enterHover($event, 'panel')"
        @pointerleave="leaveHover($event, 'panel')"
        @focusin="retainHoverFocus()"
        @focusout="scheduleHoverClose()"
        @keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); close(true) }"
        @if($trap) x-trap.inert.noscroll="open" @endif
      x-ref="panel"
    >
        <span
          aria-hidden="true"
          class="rt-ui-dropdown-caret pointer-events-none absolute z-[1]"
          data-rt-dropdown-caret
        ></span>

        <div
          id="{{ $dropdownPanelId }}"
          x-ref="panelScroll"
          @if(filled($contentRole)) role="{{ $contentRole }}" @endif
          @if(filled($contentLabel)) aria-label="{{ $contentLabel }}" @endif
          class="rt-ui-surface rt-ui-dropdown-panel relative z-[2] max-h-[min(28rem,calc(100dvh-2rem))] {{ $fixedHeight ? 'overflow-hidden' : 'overflow-y-auto' }} rounded-xl border border-rt-border dark:border-rt-dark-border {{ $contentClasses }}"
          @click="handlePanelAction($event)"
        >
          {{ $content }}
        </div>
    </div>
  </template>
</div>
