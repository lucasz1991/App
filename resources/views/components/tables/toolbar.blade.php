@props([
    'filterCount' => 0,
    'singleLine' => false,
])

@php
    $filterCount = (int) $filterCount;
    $singleLine = filter_var($singleLine, FILTER_VALIDATE_BOOL);
@endphp

{{--
    Einheitliche Tabellen-Werkzeugleiste:
    - Massenaktionen links
    - Suche als direkt erreichbare Primärfunktion
    - weitere Filter im gemeinsamen, viewport-sicheren Anchor-Dropdown
--}}
<div
    {{ $attributes->class([
        'flex min-w-0 items-center gap-2 sm:gap-3',
        'flex-nowrap' => $singleLine,
        'flex-wrap' => ! $singleLine,
    ]) }}
    data-tables-toolbar
>
    @isset($bulk)
        <div class="shrink-0">
            {{ $bulk }}
        </div>
    @endisset

    @isset($search)
        <div @class([
            'min-w-0',
            'flex-1' => $singleLine,
            'order-last w-full sm:order-none sm:min-w-[13rem] sm:flex-1 lg:max-w-sm' => ! $singleLine,
        ])>
            {{ $search }}
        </div>
    @endisset

    <div class="ml-auto shrink-0">
        <x-ui.dropdown.anchor-dropdown
            align="right"
            width="80"
            :offset="8"
            content-role="dialog"
            content-classes="bg-rt-surface p-3 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
            trigger-classes="inline-flex"
        >
            <x-slot:trigger>
                <button
                    type="button"
                    class="rt-ui-button rt-ui-button-secondary inline-flex h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3.5 text-sm font-semibold text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 hover:text-rt-accent focus-visible:border-rt-accent focus-visible:ring-4 focus-visible:ring-rt-accent/15 active:scale-[0.98] dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:hover:text-rt-dark-accent dark:focus-visible:ring-rt-dark-accent/20"
                    aria-label="{{ __('app.filters') }}"
                >
                    <i class="far fa-sliders-h text-sm text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                    <span @class(['hidden sm:inline' => $singleLine])>{{ __('app.filters') }}</span>
                    @if ($filterCount > 0)
                        <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-md bg-rt-red px-1.5 py-0.5 text-xs font-bold leading-none text-white">
                            {{ $filterCount }}
                        </span>
                    @endif
                    <i
                        @class([
                            'far fa-chevron-down text-[10px] text-rt-muted transition-transform duration-200 dark:text-rt-dark-muted',
                            'hidden sm:inline-block' => $singleLine,
                        ])
                        :class="open && 'rotate-180'"
                        aria-hidden="true"
                    ></i>
                </button>
            </x-slot:trigger>

            <x-slot:content>
                <div class="space-y-2" aria-label="{{ __('app.filters') }}">
                    {{ $slot }}
                </div>
            </x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
    </div>
</div>
