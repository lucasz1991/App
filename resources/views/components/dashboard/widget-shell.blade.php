@props(['item', 'editing' => false])
{{--
    Im Anpassen-Modus ist die ganze Karte der Ziehgriff (natives HTML5-Drag,
    siehe dashboardWidgetGrid in app.js) - Nachbarn ruecken schon waehrend
    des Ziehens live nach, wie beim Umsortieren von Apps auf dem iPhone. Der
    Rand-Griff rechts ist ein eigenes Pointer-Gesture fuer Klein/Gross, damit
    beide Gesten nicht kollidieren (siehe .widget-resize-handle).
--}}
<article
    class="widget-card"
    data-widget-item
    data-widget-key="{{ $item['key'] }}"
    data-widget-size="{{ $item['size'] }}"
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
                <div class="widget-size-toggle" role="group" aria-label="Größe von {{ $item['title'] }}">
                    <button type="button" wire:click="setWidgetSize('{{ $item['key'] }}','sm')" aria-pressed="{{ $item['size'] === 'sm' ? 'true' : 'false' }}">Klein</button>
                    <button type="button" wire:click="setWidgetSize('{{ $item['key'] }}','lg')" aria-pressed="{{ $item['size'] === 'lg' ? 'true' : 'false' }}">Groß</button>
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
            class="widget-resize-handle"
            data-widget-resize-handle
            draggable="false"
            role="slider"
            tabindex="0"
            aria-label="Größe von {{ $item['title'] }} durch Ziehen ändern"
            aria-orientation="horizontal"
            aria-valuemin="0"
            aria-valuemax="1"
            aria-valuenow="{{ $item['size'] === 'lg' ? 1 : 0 }}"
            aria-valuetext="{{ $item['size'] === 'lg' ? 'Groß' : 'Klein' }}"
        ></span>
    @endif
</article>
