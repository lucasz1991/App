@props([
    // Ziel-Tab, der beim Klick geoeffnet wird.
    'tab',
    'label',
    'description' => null,
    'icon' => 'fad fa-sliders-h',
    // Optional: Accordion-Sektion, die im Zieltab direkt aufgeklappt wird.
    'section' => null,
    // Kurze Stichworte, die den Inhalt des Tabs greifbar machen.
    'items' => [],
])

{{-- Der Teaser liegt innerhalb des Tabs-Scopes, `selectTab` ist deshalb
     direkt erreichbar. Die Sektion wird ueber ein Fenster-Event gemeldet,
     weil das Ziel-Panel ein Geschwisterknoten ist. --}}
<button
    type="button"
    x-on:click="
        selectTab(@js((string) $tab), true);
        $dispatch('rt-settings-open-section', { tab: @js((string) $tab), section: @js($section) });
    "
    {{ $attributes->class('group relative flex min-w-0 flex-col gap-3 overflow-hidden rounded-2xl bg-rt-surface p-4 text-left shadow-rt-sm ring-1 ring-rt-border/70 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-md hover:ring-rt-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/45 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 dark:hover:ring-rt-dark-accent/40 sm:p-5') }}
>
    {{-- Ruhiger Farbschleier, der beim Hover aufzieht. --}}
    <span
        class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-rt-accent-soft/60 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100 dark:from-rt-dark-accent-soft/45"
        aria-hidden="true"
    ></span>

    <span class="relative flex items-start gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 transition duration-200 group-hover:scale-105 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
            <i class="{{ $icon }} fa-lg" aria-hidden="true"></i>
        </span>

        <span class="min-w-0 flex-1">
            <span class="block text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                {{ $label }}
            </span>
            @if (filled($description))
                <span class="mt-1 block text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    {{ $description }}
                </span>
            @endif
        </span>
    </span>

    @if (! empty($items))
        <span class="relative flex flex-wrap gap-1.5">
            @foreach ($items as $item)
                <span class="inline-flex items-center rounded-lg bg-rt-surface-muted px-2 py-1 text-xs font-medium text-rt-muted ring-1 ring-rt-border/50 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/50">
                    {{ $item }}
                </span>
            @endforeach
        </span>
    @endif

    <span class="relative mt-auto inline-flex items-center gap-2 pt-1 text-sm font-semibold text-rt-accent dark:text-rt-dark-accent">
        {{ __('app.open_section') }}
        <i
            class="far fa-arrow-right text-xs transition-transform duration-200 group-hover:translate-x-1"
            aria-hidden="true"
        ></i>
    </span>
</button>
