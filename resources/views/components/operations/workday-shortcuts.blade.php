@props(['items' => [], 'sicknessUrl' => null, 'preview' => false, 'compact' => false])

@if($compact)
<section class="rt-workday-kpis" aria-label="Mein Arbeitstag im Überblick" data-workday-kpis>
    @foreach($items as $item)
        @php($action = isset($item['method']) ? $item['method'].'()' : "showTab('".$item['tab']."')")
        <button type="button" class="rt-workday-kpi" wire:key="workday-kpi-{{ $item['key'] }}"
            data-tone="{{ $item['tone'] ?? 'neutral' }}" aria-label="{{ $item['label'] }}: {{ $item['value'] }}. {{ $item['hint'] }}"
            @if($preview) disabled @else wire:click="{{ $action }}" wire:loading.attr="disabled" wire:target="showTab,openTasks" @endif>
            <strong>@if(($item['tone'] ?? '') === 'success')<i aria-hidden="true"></i>@endif{{ $item['value'] }}</strong>
            <span>{{ $item['label'] }}</span>
            <small>{{ $item['hint'] }}</small>
        </button>
    @endforeach
</section>
@else
<section class="rt-workday-shortcuts" aria-label="Auf einen Blick und Schnellaktionen"
    x-data="workdayShortcuts" wire:key="workday-shortcuts" wire:ignore.self
    :data-carousel-ready="ready ? 'true' : 'false'" data-workday-shortcuts data-no-sidebar-swipe
    @pointerenter="if ($event.pointerType === 'mouse') { hovered = true; schedule() }"
    @pointerleave="if ($event.pointerType === 'mouse') { hovered = false; schedule() }"
    @focusin="if (!$event.target.closest('[data-carousel-toggle]')) pause()">
    <header class="rt-workday-shortcuts__head">
        <h3>Auf einen Blick</h3>
        @if($sicknessUrl)
            <a class="rt-workday-shortcuts__sickness" href="{{ $preview ? '#' : $sicknessUrl }}"
                @if($preview) aria-disabled="true" x-on:click.prevent @else wire:navigate @endif>
                <i class="fas fa-notes-medical" aria-hidden="true"></i>Krankmeldung<i class="far fa-chevron-right" aria-hidden="true"></i>
            </a>
        @endif
    </header>
    <div class="rt-workday-shortcuts__viewport" x-ref="viewport" wire:ignore.self
        :aria-roledescription="ready ? 'Karussell' : null" aria-label="Persönlicher Status und Aktionen" role="group">
        <div class="swiper-wrapper rt-workday-shortcuts__track" wire:ignore.self>
            @foreach($items as $item)
                <article class="swiper-slide rt-workday-shortcut" wire:key="workday-shortcut-{{ $item['key'] }}" wire:ignore.self
                    :inert="ready && active !== {{ $loop->index }}" :aria-hidden="ready && active !== {{ $loop->index }} ? 'true' : null"
                    role="group" aria-label="{{ $item['label'] }}">
                    @if(isset($item['tab']))
                        <button type="button" class="rt-workday-shortcut__summary"
                            @if($preview) disabled @else wire:click="showTab('{{ $item['tab'] }}')" wire:loading.attr="disabled" wire:target="showTab" @endif>
                    @else
                        <a class="rt-workday-shortcut__summary" href="{{ $preview ? '#' : $item['href'] }}"
                            @if($preview) aria-disabled="true" x-on:click.prevent @else wire:navigate @endif>
                    @endif
                        <i class="{{ $item['icon'] }} rt-workday-shortcut__icon" aria-hidden="true"></i>
                        <span class="rt-workday-shortcut__label">{{ $item['label'] }}</span>
                        <strong @if($item['attention']) data-attention @endif>{{ $item['value'] }}</strong>
                        <span class="rt-workday-shortcut__hint">{{ $item['hint'] }}</span>
                    @if(isset($item['tab']))</button>@else</a>@endif
                    @if(isset($item['tab']))
                        <x-ui.buttons.button-basic mode="link" class="rt-workday-shortcut__action" :can="!$preview"
                            wire:click="showTab('{{ $item['tab'] }}')" wire:loading.attr="disabled" wire:target="showTab">
                            {{ $item['action'] }}<i class="far fa-arrow-right" aria-hidden="true"></i>
                        </x-ui.buttons.button-basic>
                    @else
                        <a class="rt-ui-button rt-ui-button-link rt-workday-shortcut__action" href="{{ $preview ? '#' : $item['action_href'] }}"
                            @if($preview) aria-disabled="true" x-on:click.prevent @else wire:navigate @endif>
                            {{ $item['action'] }}<i class="far fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
    <div class="rt-workday-shortcuts__controls" x-show="ready" x-cloak>
        <div class="rt-workday-shortcuts__pages" role="group" aria-label="Schnellzugriff auswählen">
            @foreach($items as $item)
                <button type="button" aria-label="{{ $item['label'] }} anzeigen" :aria-pressed="active === {{ $loop->index }}"
                    @click="select({{ $loop->index }})"><span aria-hidden="true"></span></button>
            @endforeach
        </div>
        <button type="button" class="rt-workday-shortcuts__toggle" data-carousel-toggle @click="toggle()"
            :disabled="reduced" :aria-label="reduced ? 'Automatischer Wechsel bei reduzierter Bewegung ausgeschaltet' : (paused ? 'Automatischen Wechsel starten' : 'Automatischen Wechsel pausieren')">
            <i :class="paused || reduced ? 'far fa-play' : 'far fa-pause'" aria-hidden="true"></i>
            <span x-text="reduced ? 'Aus' : (paused ? 'Start' : 'Pause')">Pause</span>
        </button>
    </div>
</section>
@endif
