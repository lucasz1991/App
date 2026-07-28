@props([
    'person' => null,
    'user' => null,
    'size' => 8,
    'showEmail' => false,
    'selected' => false,
])

@php
    $resolvedPerson = $person ?? $user?->person ?? null;
    $resolvedUser = $user ?? $resolvedPerson?->user ?? null;
    $hasUser = (bool) $resolvedUser;

    $first = trim((string) ($resolvedPerson->vorname ?? ''));
    $last = trim((string) ($resolvedPerson->nachname ?? ''));
    $displayName = trim($last . ', ' . $first)
        ?: ($resolvedUser->name ?? '')
        ?: ($resolvedUser->email ?? '')
        ?: 'Unbekannt';
    $email = trim((string) ($resolvedUser->email ?? $resolvedPerson->email ?? ''));

    $avatarUrl = 'https://ui-avatars.com/api/?name='
        . urlencode($displayName)
        . '&color=7F9CF5&background=EBF4FF&bold=true&size=96';

    $sizeClass = match ((int) $size) {
        6 => 'h-6 w-6 rounded-lg',
        9 => 'h-9 w-9 rounded-xl',
        10 => 'h-10 w-10 rounded-xl',
        12 => 'h-12 w-12 rounded-xl',
        default => 'h-8 w-8 rounded-lg',
    };
@endphp

<div {{ $attributes->class(['flex min-w-0 items-center gap-2.5', 'opacity-90' => ! $hasUser]) }}>
    <span class="relative shrink-0">
        <img
            src="{{ $hasUser && ! empty($resolvedUser->profile_photo_url) ? $resolvedUser->profile_photo_url : $avatarUrl }}"
            alt="{{ $displayName }}"
            @class([
                $sizeClass,
                'object-cover shadow-rt-xs ring-1 transition duration-200',
                'ring-rt-red/70 dark:ring-rt-dark-accent/80' => $selected,
                'ring-rt-border/70 dark:ring-rt-dark-border/70' => ! $selected,
                'grayscale' => ! $hasUser,
            ])
        >

        @if ($selected)
            <span class="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-rt-red text-[8px] text-white shadow-rt-xs ring-2 ring-rt-surface dark:bg-rt-dark-accent dark:ring-rt-dark-surface" aria-hidden="true">
                <i class="far fa-check"></i>
            </span>
        @elseif ($hasUser)
            <span
                @class([
                    'absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-rt-surface dark:ring-rt-dark-surface',
                    'bg-emerald-500' => $resolvedUser?->isOnline(),
                    'bg-slate-300 dark:bg-slate-600' => ! $resolvedUser?->isOnline(),
                ])
                title="{{ $resolvedUser?->isOnline() ? __('app.online') : __('app.offline') }}"
                aria-hidden="true"
            ></span>
        @endif
    </span>

    <span class="min-w-0">
        <span class="block truncate text-sm font-semibold leading-5 text-rt-text dark:text-rt-dark-text">
            {{ $displayName }}
        </span>
        @if ($showEmail && $email !== '')
            <span class="block truncate text-xs leading-4 text-rt-muted dark:text-rt-dark-muted">
                {{ $email }}
            </span>
        @endif
    </span>
</div>
