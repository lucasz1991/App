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

    $meaningfulName = static function (mixed $value): string {
        $name = trim((string) $value);

        return preg_match('/[\p{L}\p{N}]/u', $name) === 1 ? $name : '';
    };

    $personFirst = $meaningfulName($resolvedPerson?->vorname);
    $personLast = $meaningfulName($resolvedPerson?->nachname);
    $personName = match (true) {
        $personFirst !== '' && $personLast !== '' => $personLast . ', ' . $personFirst,
        $personLast !== '' => $personLast,
        default => $personFirst,
    };

    $profileFirst = $meaningfulName($resolvedUser?->profile?->first_name);
    $profileLast = $meaningfulName($resolvedUser?->profile?->last_name);
    $profileName = trim($profileFirst . ' ' . $profileLast);
    $accountName = $meaningfulName($resolvedUser?->name);

    $displayName = $personName
        ?: $accountName
        ?: $profileName
        ?: ($resolvedUser->email ?? $resolvedPerson?->email ?? '')
        ?: 'Unbekannt';
    $email = trim((string) ($resolvedUser->email ?? $resolvedPerson->email ?? ''));
    $isOnline = $hasUser && $resolvedUser->isOnline();

    $avatarUrl = 'https://ui-avatars.com/api/?name='
        . urlencode($displayName)
        . '&color=7F9CF5&background=EBF4FF&bold=true&size=96';

    $sizeClass = match ((int) $size) {
        6 => 'h-6 w-6 rounded-full',
        9 => 'h-9 w-9 rounded-full',
        10 => 'h-10 w-10 rounded-full',
        12 => 'h-12 w-12 rounded-full',
        default => 'h-8 w-8 rounded-full',
    };
@endphp

<div {{ $attributes->class(['flex min-w-0 items-center gap-2.5', 'opacity-90' => ! $hasUser]) }}>
    <span
        @class([
            'h-4 w-0.5 shrink-0 rounded-full transition-colors duration-200',
            'bg-rt-red dark:bg-rt-dark-accent' => $selected,
            'bg-transparent' => ! $selected,
        ])
        aria-hidden="true"
    ></span>
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

        @if ($hasUser)
            <span
                @class([
                    'absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-rt-surface dark:ring-rt-dark-surface',
                    'bg-emerald-500' => $isOnline,
                    'bg-slate-300 dark:bg-slate-600' => ! $isOnline,
                ])
                aria-hidden="true"
            ></span>
            <span class="sr-only">{{ $isOnline ? __('app.online') : __('app.offline') }}</span>
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
