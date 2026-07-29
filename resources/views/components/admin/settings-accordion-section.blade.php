@props([
    'section',
    'label',
    'description',
    'icon' => 'fad fa-sliders-h',
])

@php
    $sectionKey = \Illuminate\Support\Str::slug((string) $section);
    $triggerId = 'admin-system-'.$sectionKey.'-trigger';
    $panelId = 'admin-system-'.$sectionKey.'-panel';
@endphp

<section
    {{ $attributes->class('overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70') }}
    data-system-accordion-section="{{ $sectionKey }}"
>
    <h2>
        <button
            id="{{ $triggerId }}"
            type="button"
            x-on:click="toggleSystemSection(@js($sectionKey))"
            x-bind:aria-expanded="(openSystemSection === @js($sectionKey)).toString()"
            aria-controls="{{ $panelId }}"
            class="group flex min-h-[5.25rem] w-full items-center gap-3 px-4 py-4 text-left transition duration-200 ease-rt-spring hover:bg-rt-surface-muted/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-accent/35 dark:hover:bg-rt-dark-surface-muted/65 sm:gap-4 sm:px-5"
            x-bind:class="openSystemSection === @js($sectionKey) && 'bg-rt-accent-soft/45 dark:bg-rt-dark-accent-soft/35'"
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                <i class="{{ $icon }} fa-lg" aria-hidden="true"></i>
            </span>

            <span class="min-w-0 flex-1">
                <span class="block text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ $label }}
                </span>
                <span class="mt-0.5 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted sm:text-sm">
                    {{ $description }}
                </span>
            </span>

            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted transition duration-200 group-hover:text-rt-accent dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:group-hover:text-rt-dark-accent">
                <i
                    class="far fa-chevron-down text-xs transition-transform duration-200"
                    x-bind:class="openSystemSection === @js($sectionKey) && 'rotate-180'"
                    aria-hidden="true"
                ></i>
            </span>
        </button>
    </h2>

    <div
        id="{{ $panelId }}"
        x-show="openSystemSection === @js($sectionKey)"
        x-collapse.duration.280ms
        x-cloak
        role="region"
        aria-labelledby="{{ $triggerId }}"
        class="overflow-hidden"
    >
        <div class="border-t border-rt-border/60 bg-rt-surface-muted/35 p-3.5 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted/25 sm:p-5">
            {{ $slot }}
        </div>
    </div>
</section>
