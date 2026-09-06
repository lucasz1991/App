@props([
    'href' => null,
    'active' => false,
    'unread' => false,
    'compact' => false,
    'showChevron' => true,
])

@php
    $classes = [
        'rt-chat-stacked-item group/item',
        'is-active' => $active,
        'is-unread' => $unread,
        'is-compact' => $compact,
    ];
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class($classes) }}
        data-chat-stacked-item
    >
@else
    <button
        type="button"
        {{ $attributes->class($classes) }}
        data-chat-stacked-item
    >
@endif
        <span class="rt-chat-stacked-item__rail" aria-hidden="true"></span>

        <span class="rt-chat-stacked-item__avatar">
            {{ $avatar }}
        </span>

        <span class="rt-chat-stacked-item__body">
            <span class="rt-chat-stacked-item__heading">
                <span class="rt-chat-stacked-item__title">{{ $title }}</span>
                @isset($context)
                    <span class="rt-chat-stacked-item__context">{{ $context }}</span>
                @endisset
            </span>

            @isset($meta)
                <span class="rt-chat-stacked-item__meta">{{ $meta }}</span>
            @endisset
        </span>

        @if (isset($time) || isset($status) || isset($badge))
            <span class="rt-chat-stacked-item__end">
                @isset($time)
                    <span class="rt-chat-stacked-item__time">{{ $time }}</span>
                @endisset
                @isset($status)
                    <span class="rt-chat-stacked-item__status">{{ $status }}</span>
                @endisset
                @isset($badge)
                    <span class="rt-chat-stacked-item__badge">{{ $badge }}</span>
                @endisset
            </span>
        @endif

        @if ($showChevron)
            <span class="rt-chat-stacked-item__chevron" aria-hidden="true">
                <i class="far fa-chevron-right"></i>
            </span>
        @endif
@if ($href)
    </a>
@else
    </button>
@endif
