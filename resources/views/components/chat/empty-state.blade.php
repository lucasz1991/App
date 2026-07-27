@props([
    'title',
    'description',
    'compact' => false,
])

<div {{ $attributes->class([
    'rt-chat-empty-state flex h-full flex-col items-center justify-center text-center',
    'px-5 py-10' => $compact,
    'px-6 py-14 sm:px-10' => ! $compact,
]) }}>
    <span class="rt-chat-empty-state__visual" aria-hidden="true">
        <span class="rt-chat-empty-state__orbit rt-chat-empty-state__orbit--one"></span>
        <span class="rt-chat-empty-state__orbit rt-chat-empty-state__orbit--two"></span>
        <span class="rt-chat-empty-state__core">
            <i class="fad fa-comment-dots"></i>
        </span>
    </span>
    <p class="mt-6 text-sm font-bold tracking-[-0.02em] text-rt-text dark:text-white">
        {{ $title }}
    </p>
    <p class="mt-2 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
        {{ $description }}
    </p>
    {{ $slot }}
</div>
