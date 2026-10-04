{{--
    Lokaler Avatar fuer Dashboard-Listen. Bewusst NICHT $user->profile_photo_url:
    Jetstreams Rueckfall dafuer ist ein Bild von ui-avatars.com - der Name ginge
    so bei jedem Seitenaufruf an einen Drittanbieter. Ohne eigenes Profilbild
    werden die Initialen deshalb hier im Browser gezeichnet; die Farbe ist pro
    Person stabil (aus der ID abgeleitet), damit Listen nicht flackern.
--}}
@props(['user', 'size' => 28])
@php
    $name = trim((string) ($user?->name ?? ''));
    $initials = collect(preg_split('/\s+/u', $name) ?: [])
        ->filter()
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->take(2)
        ->implode('') ?: '?';
    $hue = ((int) ($user?->id ?? 0) * 47) % 360;
@endphp
<span {{ $attributes->class(['wv-avatar']) }} style="--avatar-size:{{ $size }}px;--avatar-hue:{{ $hue }};" title="{{ $name }}">
    @if($user?->profile_photo_path)
        <img src="{{ $user->profile_photo_url }}" alt="" loading="lazy">
    @else
        <span aria-hidden="true">{{ $initials }}</span>
    @endif
</span>
