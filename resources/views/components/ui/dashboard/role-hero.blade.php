@props([
    'title',
    'eyebrow' => null,
    'description' => null,
    'icon' => 'briefcase',
    'headingId' => 'dashboard-role-title',
    'variant' => 'default',
])

@php($isPersonal = $variant === 'personal')

<section
    {{ $attributes->class([
        'relative overflow-hidden px-4 pb-4 pt-5 ring-1 sm:px-6 sm:pb-6 sm:pt-7',
        'rounded-2xl bg-rt-surface ring-rt-border/80 dark:bg-rt-dark-surface dark:ring-rt-dark-border/80' => $isPersonal,
        'rounded-[1.75rem] bg-rt-text shadow-rt-md ring-black/10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/80' => ! $isPersonal,
    ]) }}
    aria-labelledby="{{ $headingId }}"
    data-dashboard-role-hero-variant="{{ $variant }}"
>
    <span class="absolute inset-y-0 left-0 w-1 bg-rt-red" aria-hidden="true"></span>

    <div @class([
        'relative grid gap-5',
        'lg:grid-cols-[minmax(0,1.55fr)_minmax(16rem,.65fr)] lg:items-stretch' => isset($aside),
    ])>
        <div class="flex min-w-0 flex-col justify-center">
            <div class="flex items-start gap-3 sm:gap-4">
                <span @class([
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 sm:h-12 sm:w-12',
                    'bg-rt-accent-soft ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:ring-rt-dark-accent/15' => $isPersonal,
                    'bg-white/10 text-white ring-white/10' => ! $isPersonal,
                ]) aria-hidden="true">
                    @if ($isPersonal)
                        <span class="h-2.5 w-2.5 rounded-sm bg-rt-red"></span>
                    @else
                        <i data-feather="{{ $icon }}" class="h-5 w-5 sm:h-6 sm:w-6"></i>
                    @endif
                </span>

                <div class="min-w-0">
                    @if (filled($eyebrow))
                        <p @class([
                            'text-[0.6875rem] font-semibold uppercase tracking-[0.16em]',
                            'text-rt-red dark:text-rt-dark-accent' => $isPersonal,
                            'text-rt-red-light' => ! $isPersonal,
                        ])>
                            {{ $eyebrow }}
                        </p>
                    @endif

                    <h1 id="{{ $headingId }}" @class([
                        'mt-1 max-w-3xl break-words text-balance text-2xl tracking-[-0.035em] sm:text-3xl lg:text-4xl',
                        'font-semibold text-rt-text dark:text-white' => $isPersonal,
                        'font-bold text-white' => ! $isPersonal,
                    ])>
                        {{ $title }}
                    </h1>

                    @if (filled($description))
                        <p @class([
                            'mt-2 max-w-2xl text-pretty text-sm leading-6 sm:text-base',
                            'text-rt-muted dark:text-rt-dark-muted' => $isPersonal,
                            'text-slate-300' => ! $isPersonal,
                        ])>
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
            <aside @class([
                'rounded-xl p-4 ring-1 ring-inset sm:p-5',
                'bg-rt-surface-muted/45 ring-rt-border/70 dark:bg-rt-dark-surface-muted/35 dark:ring-rt-dark-border/70' => $isPersonal,
                'bg-white/[0.06] ring-white/10' => ! $isPersonal,
            ])>
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
