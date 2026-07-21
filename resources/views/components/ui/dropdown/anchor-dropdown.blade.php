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
  $anchorPos  = match($align){ 'left'=>'bottom-start','top'=>'top-end','none','false'=>'bottom-end', default=>'bottom-end' };
@endphp

<div
  {{ $attributes->class('relative inline-flex') }}
  x-data="{
    open: false,
    resizeHandler: null,
    scrollOnOpen: @js((bool)$scrollOnOpen),
    scrollOnTrigger: @js((bool)$scrollOnTrigger),
    headerOffset: @js((int)$headerOffset),
    matchTriggerWidth: @js((bool)$matchTriggerWidth),

    init(){
      this.resizeHandler = () => { if (this.open) this.setPanelWidth() };
      window.addEventListener('resize', this.resizeHandler, { passive: true });
    },

    destroy(){
      if (this.resizeHandler) window.removeEventListener('resize', this.resizeHandler);
    },

    setPanelWidth(){
      if (!this.matchTriggerWidth) return;
      const t = $refs.trigger, p = $refs.panel;
      if (!t || !p) return;
      const tw = t.getBoundingClientRect().width;
      p.style.width = tw + 'px';
      p.style.maxWidth = 'calc(100vw - 16px)';
    },

    scrollToTrigger(){
      const t = $refs.trigger;
      if(!t) return;
      const y = t.getBoundingClientRect().top + window.scrollY - this.headerOffset;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    },

    scrollPanelCentered(){
      const p = $refs.panel;
      if(!p) return;
      // Panel-Position nach Anchoring abwarten
      requestAnimationFrame(() => {
        const r = p.getBoundingClientRect();
        const centerOffset = (window.innerHeight - r.height) / 2;
        // headerOffset oberhalb trotzdem berücksichtigen
        const target = r.top + window.scrollY - Math.max(0, (this.headerOffset - centerOffset));
        window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
      });
    },
  }"
  x-init="
    $watch('open', (v) => {
      if (v) {
        $nextTick(() => {
          setPanelWidth();
          if (scrollOnOpen) {
            // Wenn explizit Trigger-Scroll gewünscht -> Trigger,
            // sonst Panel schön mittig.
            if (scrollOnTrigger) { scrollToTrigger(); }
            else { scrollPanelCentered(); }
          }
          // Panel-Scroll (Inhalt) nach oben
          if ($refs.panelScroll) { $refs.panelScroll.scrollTo({ top: 0, behavior: 'auto' }); }
        });
      }
    });
  "
  x-cloak
  @keydown.escape.window="open=false"
  @close.window.stop="open=false"
>


  {{-- Trigger --}}
<div class="inline-flex" x-ref="trigger" @click="
    open = !open;
    if (open) {
      $nextTick(() => {
        setPanelWidth();
        $dispatch('dropdown-open');
      });
    }
  "
>
    {{ $trigger }}
  </div>

  {{-- Overlay --}}
  @if($overlay)
    <div x-show="open" x-transition.opacity class="fixed inset-0 z-40 bg-black/40" @click="open=false" style="display:none;"></div>
  @endif

  {{-- Panel --}}
  <div
    x-show="open"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="transform opacity-0 scale-95"
    x-transition:enter-end="transform opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-75"
    x-transition:leave-start="transform opacity-100 scale-100"
    x-transition:leave-end="transform opacity-0 scale-95"
    x-anchor.{{ $anchorPos }}.offset.{{ $offset }}.flip.shift="$refs.trigger"
    class="z-[120] {{ $widthClass }} rounded-xl shadow-rt-md {{ $dropdownClasses }}"
    style="display:none; max-width:calc(100vw - 16px); max-height:calc(100vh - 16px);"
    @click.outside="open=false"
    @if($trap) x-trap.inert.noscroll="open" @endif
    x-ref="panel"
  >
    <div
      x-ref="panelScroll"
      role="menu"
      class="max-h-[min(28rem,calc(100vh-2rem))] overflow-y-auto rounded-xl border border-rt-border shadow-rt-md dark:border-rt-dark-border {{ $contentClasses }}"
      @click="if ($event.target.closest('a, button, [role=menuitem]')) open=false"
    >
      {{ $content }}
    </div>
  </div>
</div>
