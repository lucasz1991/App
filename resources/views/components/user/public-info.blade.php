@props([
    'person' => null,
    'user'   => null,
    'size'   => 8,   // Tailwind-Größe: 6/8/10/12 ...
])

@php
    // Modelle auflösen
    $resolvedPerson = $person ?? $user?->person ?? null;
    $resolvedUser   = $user ?? $resolvedPerson?->user ?? null;
    $hasUser        = (bool) $resolvedUser;

    // Anzeige-Name
    $first = trim((string)($resolvedPerson->vorname ?? ''));
    $last  = trim((string)($resolvedPerson->nachname ?? ''));
    $displayName = trim($last.', '.$first)
        ?: ($resolvedUser->name ?? '')
        ?: ($resolvedUser->email ?? '')
        ?: 'Unbekannt';

    // Initialen
    $initials = '';
    if ($first !== '') { $initials .= mb_strtoupper(mb_substr($first, 0, 1)); }
    if ($last  !== '') { $initials .= mb_strtoupper(mb_substr($last, 0, 1)); }
    if ($initials === '' && !empty($resolvedUser?->name))  { $initials = mb_strtoupper(mb_substr($resolvedUser->name, 0, 1)); }
    if ($initials === '' && !empty($resolvedUser?->email)) { $initials = mb_strtoupper(mb_substr($resolvedUser->email, 0, 1)); }
    if ($initials === '') { $initials = '?'; }

    // Avatar-URL (Fallback)
    $avatarBg    = 'EBF4FF';
    $avatarColor = '7F9CF5'; // gray-800
    $avatarSize  = 96;
    $avatarUrl   = 'https://ui-avatars.com/api/?name='
        . urlencode($displayName)
        . "&color={$avatarColor}&background={$avatarBg}&bold=true&size={$avatarSize}";
@endphp
<div class="flex items-center gap-2 {{ !$hasUser ? 'opacity-90' : '' }}">
    @if($hasUser && !empty($resolvedUser->profile_photo_url))
        <img
            src="{{ $resolvedUser->getBaseProfilePhotoUrlAttribute() }}"
            class="w-{{ $size }} h-{{ $size }} rounded-full object-cover"
            >
    @else
        <img
            src="{{ $avatarUrl }}"
            class="w-{{ $size }} h-{{ $size }} rounded-full object-cover {{ !$hasUser ? 'grayscale' : '' }}"
            >
    @endif

    <span class="inline-flex items-center gap-1.5 text-sm font-medium text-rt-text dark:text-rt-dark-text">
        <span>{{ $displayName }}</span>
        @if($hasUser)
            @if($resolvedUser?->isOnline())
                <span class="h-2 w-2 rounded-full bg-green-300" title="Online"></span>
            @else
                <span class="h-2 w-2 rounded-full bg-rt-soft dark:bg-rt-dark-soft" title="Offline"></span>
            @endif
        @endif
    </span>
</div>
