@props(['lookups' => [], 'preview' => []])
@php($pending = $lookups ?: (isset($preview['lookup']) ? [$preview['lookup']] : []))
@if($pending)
    <div wire:key="worksite-{{ hash('sha256', implode('|', array_column($pending, 'signature'))) }}" x-data="rtWorksites(@js(route('places.resolve', [], false)), @js($pending))" class="text-xs text-rt-muted" role="status">
        <span x-show="busy">TomTom sucht den Standort …</span>
        <button type="button" x-cloak x-show="error" x-on:click="load()">Standortsuche erneut versuchen</button>
    </div>
@endif
@if(!empty($preview['lookupMessage']))
    <small class="text-rt-muted">{{ $preview['lookupMessage'] }}</small>
@endif
@if(!empty($preview['choice']) && !empty($preview['alternatives']))
    <details class="text-xs text-rt-muted" x-data="rtWorksiteChoice(@js(route('places.choose', [], false)), @js($preview['choice']))">
        <summary>Standort für diese Ansicht auswählen</summary>
        @foreach($preview['alternatives'] as $candidate)
            <button type="button" class="block py-1 text-left" x-bind:disabled="busy" x-on:click="choose(@js($candidate['latitude']), @js($candidate['longitude']))">{{ $candidate['place'] }}</button>
        @endforeach
        <span role="status" x-text="error"></span>
    </details>
@endif
@if(($preview['state'] ?? '') === 'outside' && isset($preview['latitude'], $preview['longitude']))
    <details class="text-xs text-rt-muted"><summary>Standort auf Karte öffnen</summary>
        <div x-data="rtIntakeLocationMap(@js(['latitude' => $preview['latitude'], 'longitude' => $preview['longitude'], 'zoom' => 14, 'interactive' => true]))">
            <div class="h-40" x-ref="canvas" wire:ignore></div>
            <span>{{ $preview['latitude'] }}, {{ $preview['longitude'] }}</span>
        </div>
    </details>
@endif
