<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
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
  'overlapAnchor'     => false,
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
  'overlapAnchor'     => false,
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
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
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
    'launcher' => 'side-auto',
    default => 'bottom-end',
  };
  $horizontalAlign = str_ends_with($anchorPlacement, '-start')
    ? 'left'
    : (str_ends_with($anchorPlacement, '-auto') ? 'auto' : 'right');
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
?>

<div
  <?php echo e($attributes->class('relative inline-flex')); ?>

  data-rt-dropdown-root
  data-rt-dropdown-id="<?php echo e($resolvedDropdownId); ?>"
  x-data="rtAnchorDropdown(<?php echo \Illuminate\Support\Js::from([
      'openOnHover' => (bool) $openOnHover,
      'hoverOpenDelay' => max(0, (int) $hoverOpenDelay),
      'hoverCloseDelay' => max(0, (int) $hoverCloseDelay),
      'layerGroup' => $resolvedLayerGroup,
      'layerId' => $resolvedDropdownId,
      'anchorSelector' => filled($anchorSelector) ? (string) $anchorSelector : null,
      'horizontalAlign' => $horizontalAlign,
      'preferredPlacement' => str_starts_with($anchorPlacement, 'side')
        ? 'side'
        : (str_starts_with($anchorPlacement, 'top') ? 'top' : 'bottom'),
      'offset' => $anchorOffset,
      'maximumHeight' => max(160, min(960, (int) $maxHeight)),
      'fixedHeight' => (bool) $fixedHeight,
      'scrollOnOpen' => (bool) $scrollOnOpen,
      'scrollOnTrigger' => (bool) $scrollOnTrigger,
      'headerOffset' => (int) $headerOffset,
      'matchTriggerWidth' => $matchesTriggerWidth,
      'overlapAnchor' => (bool) $overlapAnchor,
      'panelId' => $dropdownPanelId,
      'popupRole' => $contentRole === 'dialog' ? 'dialog' : 'menu',
    ])->toHtml() ?>)"
  x-cloak
  @keydown.escape.window="handleWindowEscape($event)"
  @close.window.stop="close()"
  @rt-navigation:prepare.window="close()"
  @rt-dropdown-parent-close.stop="close()"
  @rt-topbar-layer-open.window="handleLayerOpen($event)"
  <?php if($externalTrigger): ?>
    @rt-anchor-dropdown-open.window="openFromAnchor($event)"
    @rt-anchor-dropdown-focus.window="focusExternalPanel($event)"
    @rt-anchor-dropdown-close.window="if ($event.detail?.id === layerId) close(true)"
  <?php endif; ?>
>
  <div
    class="rt-ui-dropdown-trigger <?php echo e($triggerClasses); ?>"
    x-ref="trigger"
    data-rt-dropdown-trigger
    <?php if (! ($externalTrigger)): ?> @click="toggle()" <?php endif; ?>
    @pointerenter="enterHover($event, 'trigger')"
    @pointerleave="leaveHover($event, 'trigger')"
    @focusin="retainHoverFocus()"
    @focusout="scheduleHoverClose()"
    <?php if($openOnHover): ?> @keydown.arrow-down.prevent.stop="focusHoverPanel()" <?php endif; ?>
    @keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); close(true) }"
  >
    <?php echo e($trigger); ?>

  </div>

  <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($overlay): ?>
    <template x-teleport="body">
      <div x-show="open" x-ref="overlay" x-transition.opacity data-rt-dropdown-owner="<?php echo e($resolvedDropdownId); ?>" class="pointer-events-auto fixed inset-0 z-[170] bg-black/40" @click="close()" style="display:none;" aria-hidden="true"></div>
    </template>
  <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

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
        data-rt-dropdown-owner="<?php echo e($resolvedDropdownId); ?>"
        class="rt-viewport-dropdown pointer-events-auto fixed z-[180] <?php echo e($panelWidthClass); ?> rounded-xl <?php echo e($dropdownClasses); ?>"
        style="display:none; margin:0; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px); --rt-dropdown-caret-x:<?php echo e($anchorCaretX); ?>; --rt-dropdown-connector-size:<?php echo e($anchorConnectorSize); ?>px;"
        data-rt-dropdown-panel
        @click.outside="handleOutsideClick($event)"
        @pointerenter="enterHover($event, 'panel')"
        @pointerleave="leaveHover($event, 'panel')"
        @focusin="retainHoverFocus()"
        @focusout="scheduleHoverClose()"
        @keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); close(true) }"
        <?php if($trap): ?> x-trap.inert.noscroll="open" <?php endif; ?>
      x-ref="panel"
    >
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($overlapAnchor)): ?>
          <span
            aria-hidden="true"
            class="rt-ui-dropdown-caret pointer-events-none absolute z-[1]"
            data-rt-dropdown-caret
          ></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <div
          id="<?php echo e($dropdownPanelId); ?>"
          x-ref="panelScroll"
          <?php if(filled($contentRole)): ?> role="<?php echo e($contentRole); ?>" <?php endif; ?>
          <?php if(filled($contentLabel)): ?> aria-label="<?php echo e($contentLabel); ?>" <?php endif; ?>
          class="rt-ui-surface rt-ui-dropdown-panel relative z-[2] max-h-[min(28rem,calc(100dvh-2rem))] <?php echo e($fixedHeight ? 'overflow-hidden' : 'overflow-y-auto'); ?> rounded-xl border border-rt-border dark:border-rt-dark-border <?php echo e($contentClasses); ?>"
          @click="handlePanelAction($event)"
        >
          <?php echo e($content); ?>

        </div>
    </div>
  </template>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/dropdown/anchor-dropdown.blade.php ENDPATH**/ ?>