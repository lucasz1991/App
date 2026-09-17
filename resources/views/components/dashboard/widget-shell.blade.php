@props(['item', 'editing' => false])
{{--
    Im Anpassen-Modus ist die ganze Karte der Ziehgriff (natives HTML5-Drag,
    siehe dashboardWidgetGrid in app.js) - Nachbarn ruecken schon waehrend
    des Ziehens live nach, wie beim Umsortieren von Apps auf dem iPhone. Die
    beiden Rand-Griffe (rechts = Breite, unten = Hoehe) sind eigene
    Pointer-Gesten, damit sie nicht mit dem Kartenzug kollidieren (siehe
    .widget-resize-handle-width/-height).
--}}
<article
    class="widget-card"
    data-widget-item
    data-widget-key="{{ $item['key'] }}"
    data-widget-size="{{ $item['size'] }}"
    data-widget-rows="{{ $item['rows'] }}"
    data-editing="{{ $editing ? 'true' : 'false' }}"
    draggable="{{ $editing ? 'true' : 'false' }}"
    wire:key="widget-{{ $item['key'] }}"
>
    <header class="widget-card-head">
        <span class="widget-card-ico"><i data-feather="{{ $item['icon'] }}"></i></span>
        <h3>{{ $item['title'] }}</h3>
        @if($editing)
            <div class="widget-card-controls">
                <div class="widget-move-buttons">
                    <button type="button" wire:click="moveWidget('{{ $item['key'] }}', -1)" aria-label="{{ $item['title'] }} nach vorn"><i data-feather="chevron-left"></i></button>
                    <button type="button" wire:click="moveWidget('{{ $item['key'] }}', 1)" aria-label="{{ $item['title'] }} nach hinten"><i data-feather="chevron-right"></i></button>
                </div>
                <div class="widget-size-toggle" role="group" aria-label="Breite von {{ $item['title'] }}">
                    <button type="button" wire:click="setWidgetSize('{{ $item['key'] }}','sm')" aria-pressed="{{ $item['size'] === 'sm' ? 'true' : 'false' }}">Klein</button>
                    <button type="button" wire:click="setWidgetSize('{{ $item['key'] }}','lg')" aria-pressed="{{ $item['size'] === 'lg' ? 'true' : 'false' }}">Groß</button>
                </div>
                <div class="widget-rows-toggle" role="group" aria-label="Höhe von {{ $item['title'] }}">
                    <button type="button" wire:click="setWidgetRows('{{ $item['key'] }}', 1)" aria-pressed="{{ $item['rows'] === 1 ? 'true' : 'false' }}">1 Zeile</button>
                    <button type="button" wire:click="setWidgetRows('{{ $item['key'] }}', 2)" aria-pressed="{{ $item['rows'] === 2 ? 'true' : 'false' }}">2 Zeilen</button>
                </div>
                <button type="button" class="widget-remove" wire:click="hideWidget('{{ $item['key'] }}')" aria-label="{{ $item['title'] }} vom Dashboard entfernen">
                    <i data-feather="x"></i>
                </button>
            </div>
        @endif
    </header>
    <div class="widget-card-body">
        {{ $slot }}
    </div>
    @if($editing)
        <span
            class="widget-resize-handle widget-resize-handle-width"
            data-widget-resize-handle="width"
            draggable="false"
            role="slider"
            tabindex="0"
            aria-label="Breite von {{ $item['title'] }} durch Ziehen ändern"
            aria-orientation="horizontal"
            aria-valuemin="0"
            aria-valuemax="1"
            aria-valuenow="{{ $item['size'] === 'lg' ? 1 : 0 }}"
            aria-valuetext="{{ $item['size'] === 'lg' ? 'Groß' : 'Klein' }}"
        ></span>
        <span
            class="widget-resize-handle widget-resize-handle-height"
            data-widget-resize-handle="height"
            draggable="false"
            role="slider"
            tabindex="0"
            aria-label="Höhe von {{ $item['title'] }} durch Ziehen ändern"
            aria-orientation="vertical"
            aria-valuemin="1"
            aria-valuemax="2"
            aria-valuenow="{{ $item['rows'] }}"
            aria-valuetext="{{ $item['rows'] === 2 ? '2 Zeilen' : '1 Zeile' }}"
        ></span>
    @endif
</article>
