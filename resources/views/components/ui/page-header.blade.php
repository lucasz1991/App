@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'count' => null,
    'help' => null,
    'backUrl' => null,
    'showBack' => true,
])

<header
    {{ $attributes->class('relative flex min-w-0 items-center gap-3 sm:gap-4') }}
    data-anim="fade-up"
    data-page-header
>
    @if($showBack)
        <x-ui.buttons.backbutton :href="$backUrl" />
    @endif

    <div class="min-w-0 flex-1">
        <h1 class="min-w-0 truncate text-xl font-semibold leading-tight tracking-[-0.03em] text-rt-text sm:text-2xl dark:text-rt-dark-text" title="{{ $title }}">
            {{ $title }}
        </h1>
    </div>

    @if (isset($actions) || $help)
        <div class="flex shrink-0 flex-nowrap items-center justify-end gap-1.5" data-page-header-actions>
            @isset($actions)
                <div class="flex min-w-0 flex-nowrap items-center justify-end gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    {{ $actions }}
                </div>
            @endisset
            @if ($help)
                <x-ui.page-info-button
                    :title="$title"
                    :help="$help"
                    data-page-header-control
                />
            @endif
        </div>
    @endif
</header>
