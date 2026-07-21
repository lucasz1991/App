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
@endphp

<div
  {{ $attributes->class('relative inline-flex') }}
  x-data="viewportDropdown({
    align: @js((string) $align),
    offset: @js(max(0, (int) $offset)),
    gutter: 12,
    scrollOnOpen: @js((bool) $scrollOnOpen),
    scrollOnTrigger: @js((bool) $scrollOnTrigger),
    headerOffset: @js((int) $headerOffset),
    matchTriggerWidth: @js($matchesTriggerWidth),
  })"
  x-cloak
  @keydown.escape.window="close()"
  @close.window.stop="close()"
>
  {{-- Trigger --}}
  <div class="inline-flex" x-ref="trigger" @click="toggle()">
    {{ $trigger }}
  </div>

  {{-- Overlay --}}
  @if($overlay)
    <template x-teleport="body">
      <div x-show="open" x-transition.opacity class="fixed inset-0 z-[170] bg-black/40" @click="close()" style="display:none;"></div>
    </template>
  @endif

  {{-- Das Teleportieren verhindert Clipping durch Tabellen und Karten. --}}
  <template x-teleport="body">
    <div
      x-show="open"
      x-transition:enter="transition ease-out duration-200"
      x-transition:enter-start="transform opacity-0 scale-95"
      x-transition:enter-end="transform opacity-100 scale-100"
      x-transition:leave="transition ease-in duration-100"
      x-transition:leave-start="transform opacity-100 scale-100"
      x-transition:leave-end="transform opacity-0 scale-95"
      x-bind:id="panelId"
      x-bind:data-placement="placement"
      class="rt-viewport-dropdown fixed z-[180] {{ $widthClass }} rounded-xl shadow-rt-md {{ $dropdownClasses }}"
      style="display:none; left:12px; top:12px; margin:0; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px);"
      data-rt-dropdown-panel
      @click.outside="close()"
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
