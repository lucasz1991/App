@props([
    // person: belegter Einsatzplatz (Foto oder Initialen), open: offener Einsatzplatz.
    'variant' => 'person',
    'user' => null,
])
@php
    $name = $user?->name ?: 'Eingeplante Person nicht verfügbar';
    $initials = $user
        ? (collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('') ?: '?')
        : '?';
@endphp
@if($variant === 'open')
    <span {{ $attributes->class('rt-service-avatar rt-service-avatar--open') }}><span aria-hidden="true">?</span><span class="rt-service-avatar__dot" aria-hidden="true"></span></span>
@else
    <span {{ $attributes->class('rt-service-avatar') }}>
        @if($user?->profile_photo_path)
            <img src="{{ $user->profile_photo_url }}" alt="" loading="lazy" onerror="this.hidden=true" />
        @endif
        <span aria-hidden="true">{{ $initials }}</span>
    </span>
@endif
