@props([
    'src' => null,
    'name' => '',
    'size' => 'md',
    'signal' => false,
])

@php
    $sizes = [
        'xs' => 'h-8 w-8 text-[9px]',
        'sm' => 'h-9 w-9 text-[11px]',
        'md' => 'h-11 w-11 text-xs',
        'lg' => 'h-12 w-12 text-sm',
    ];
    $initials = collect(preg_split('/\s+/u', trim((string) $name)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<span {{ $attributes->class(['rt-chat-avatar relative inline-flex shrink-0']) }}>
    @if ($src)
        <img
            src="{{ $src }}"
            alt="{{ $name }}"
            class="{{ $sizes[$size] ?? $sizes['md'] }} rounded-full object-cover"
        >
    @else
        <span
            aria-hidden="true"
            class="{{ $sizes[$size] ?? $sizes['md'] }} flex items-center justify-center rounded-full font-bold tracking-[-0.04em]"
        >
            {{ $initials !== '' ? $initials : 'RT' }}
        </span>
    @endif

    @if ($signal)
        <span class="rt-chat-avatar__signal" aria-hidden="true"></span>
    @endif
</span>
