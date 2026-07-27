@props([
    'src' => null,
    'name' => '',
    'size' => 'md',
    'signal' => false,
])

@php
    $sizes = [
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
            class="{{ $sizes[$size] ?? $sizes['md'] }} rounded-[0.9rem] object-cover"
        >
    @else
        <span
            aria-hidden="true"
            class="{{ $sizes[$size] ?? $sizes['md'] }} flex items-center justify-center rounded-[0.9rem] font-bold tracking-[-0.04em]"
        >
            {{ $initials !== '' ? $initials : 'RT' }}
        </span>
    @endif

    @if ($signal)
        <span class="rt-chat-avatar__signal" aria-hidden="true"></span>
    @endif
</span>
