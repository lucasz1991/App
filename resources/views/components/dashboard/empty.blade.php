{{--
    Einheitlicher Leerzustand fuer Widget-Details: Icon + kurzer Text statt
    nackter Textzeile, damit "keine Daten" genauso durchdacht aussieht wie
    ein gefuelltes Widget. $icon greift zur Bedeutung passend - "check-circle"
    fuer echte gute Nachrichten (Warteschlange leer, nichts wartet), das
    jeweilige Themen-Icon fuer schlichte Leere (noch keine Nachrichten/Kunden/...).
--}}
@props(['icon' => 'inbox'])
<div {{ $attributes->class(['widget-empty']) }}>
    <i data-feather="{{ $icon }}"></i>
    <p>{{ $slot }}</p>
</div>
