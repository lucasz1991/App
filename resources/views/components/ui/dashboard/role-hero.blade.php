@props([
    'title',
    'eyebrow' => null,
    'description' => null,
    'icon' => 'briefcase',
    'headingId' => 'dashboard-role-title',
])

<section
    {{ $attributes->class('relative overflow-hidden rounded-[1.75rem] bg-rt-text px-4 pb-4 pt-5 shadow-rt-md ring-1 ring-black/10 sm:px-6 sm:pb-6 sm:pt-7 dark:bg-rt-dark-surface dark:ring-rt-dark-border/80') }}
    aria-labelledby="{{ $headingId }}"
>
    <span class="absolute inset-y-0 left-0 w-1 bg-rt-red" aria-hidden="true"></span>

    <div @class([
        'relative grid gap-5',
        'lg:grid-cols-[minmax(0,1.55fr)_minmax(16rem,.65fr)] lg:items-stretch' => isset($aside),
    ])>
        <div class="flex min-w-0 flex-col justify-center">
            <div class="flex items-start gap-3 sm:gap-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-white/10 sm:h-12 sm:w-12" aria-hidden="true">
                    <i data-feather="{{ $icon }}" class="h-5 w-5 sm:h-6 sm:w-6"></i>
                </span>

                <div class="min-w-0">
                    @if (filled($eyebrow))
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-rt-red-light">
                            {{ $eyebrow }}
                        </p>
                    @endif

                    <h1 id="{{ $headingId }}" class="mt-1 max-w-3xl text-balance text-2xl font-bold tracking-[-0.035em] text-white sm:text-3xl lg:text-4xl">
                        {{ $title }}
                    </h1>

                    @if (filled($description))
                        <p class="mt-2 max-w-2xl text-pretty text-sm leading-6 text-slate-300 sm:text-base">
                            {{ $description }}
                        </p>
                    @endif
                </div>
            </div>

            @if (trim((string) $slot) !== '')
                <div class="mt-5">
                    {{ $slot }}
                </div>
            @endif

            @isset($actions)
                <div class="mt-5 flex flex-wrap items-center gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>

        @isset($aside)
            <aside class="rounded-2xl bg-white/[0.06] p-4 ring-1 ring-inset ring-white/10 sm:p-5">
                {{ $aside }}
            </aside>
        @endisset
    </div>

    @isset($metrics)
        <div class="relative mt-5 sm:mt-6">
            {{ $metrics }}
        </div>
    @endisset
</section>
