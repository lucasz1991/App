{{--
    Kleines Saeulendiagramm aus reinem CSS fuer Tagesreihen in Kacheln.
    $values: Hauptreihe (Hoehe der hellen Saeule).
    $fill:   optionale zweite Reihe, als kraeftige Fuellung IN der Saeule
             (z. B. zugesagt von benoetigt, umgewandelt von eingegangen).
             Ohne $fill ist die Saeule selbst kraeftig gefaerbt.
    $labels: Tooltip je Saeule; $axis: Kurzbeschriftung unter der Saeule
             (leer lassen = keine Achse). $marks: Saeulen hervorheben
             (z. B. Wochenende, heute) als Liste von bool.
    $fillLabel/$valueLabel benennen die Reihen im Tooltip.
--}}
@props([
    'values', 'fill' => null, 'labels' => [], 'axis' => [], 'marks' => [],
    'valueLabel' => '', 'fillLabel' => '', 'height' => 52, 'warnShort' => false, 'ariaLabel' => '',
])
@php
    $max = max(1, max($values ?: [0]), $fill ? max($fill) : 0);
@endphp
<div {{ $attributes->class(['wv-columns']) }} style="--columns:{{ count($values) }};" role="img" aria-label="{{ $ariaLabel }}">
    <div class="wv-columns-plot" style="height:{{ $height }}px;">
        @foreach($values as $i => $value)
            @php
                $part = $fill[$i] ?? null;
                $short = $warnShort && $part !== null && $value > 0 && $part < $value;
                $tip = trim(($labels[$i] ?? '').': '.($fill !== null ? $part.' '.$fillLabel.' / ' : '').$value.' '.$valueLabel);
            @endphp
            <span class="wv-col" @if($marks[$i] ?? false) data-mark @endif @if($short) data-short @endif title="{{ $tip }}">
                <span class="wv-col-bar @if($fill === null) is-solid @endif" style="height:{{ $value > 0 ? max(6, round($value / $max * 100)) : 0 }}%;">
                    @if($fill !== null && $part > 0)<span class="wv-col-fill" style="height:{{ min(100, round($part / max(1, $value) * 100)) }}%;"></span>@endif
                </span>
                @if($value === 0 && ($fill[$i] ?? 0) === 0)<span class="wv-col-zero"></span>@endif
            </span>
        @endforeach
    </div>
    @if($axis)
        <div class="wv-columns-axis" aria-hidden="true">
            @foreach($axis as $i => $label)<span @if($marks[$i] ?? false) data-mark @endif>{{ $label }}</span>@endforeach
        </div>
    @endif
</div>
