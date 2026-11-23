{{--
    Standardkarte der App.

    variant:
      default  Rahmen, Fläche, Schatten (rt-ui-surface)
      muted    wie default, aber auf der gedämpften Fläche
      flush    Rahmen ohne Innenabstand (Tabellen, Listen bis an den Rand)
      ops      Betriebskarte: rendert die Klasse .ops-panel, damit die
               Grundregel (operations-workspace.css) und alle Kontext-Overrides
               der Bereichs-Stylesheets (Kundenportal, Personal, Disposition …)
               unverändert greifen. Innenabstand kommt aus der CSS-Klasse.

    padding: Tailwind-Abstandsklassen. null = Vorgabe der Variante.
    as:      HTML-Element (section, div, article, fieldset …).

    Kopf (optional): Prop oder Slot title, Slot/Prop description, Slot actions
    oder ein freier Slot header. Mit Kopf liegt der Innenabstand auf dem Rumpf.
--}}
@props([
    'variant' => 'default',
    'padding' => null,
    'as' => 'section',
    'title' => null,
    'description' => null,
    'level' => 3,
])

@php
    $isFilled = static fn ($value): bool => $value instanceof \Illuminate\View\ComponentSlot
        ? $value->isNotEmpty()
        : filled($value);

    $variant = in_array($variant, ['default', 'muted', 'flush', 'ops'], true) ? $variant : 'default';
    $hasHeader = $isFilled($header ?? null) || $isFilled($title) || $isFilled($actions ?? null);
    $level = max(2, min(6, (int) $level));
    $headingTag = 'h'.$level;
    $tag = preg_match('/^[a-z][a-z0-9-]*$/', (string) $as) ? $as : 'section';

    if ($variant === 'ops') {
        $frame = 'ops-panel';
        $bodyPadding = $padding ?? '';
        $framePadding = $hasHeader ? '' : (string) $padding;
    } else {
        $surface = $variant === 'muted'
            ? 'bg-rt-surface-muted dark:bg-rt-dark-surface-muted'
            : 'bg-rt-surface dark:bg-rt-dark-surface';
        $frame = "rt-ui-surface rounded-xl border border-rt-border {$surface} text-rt-text shadow-sm dark:border-rt-dark-border dark:text-rt-dark-text";

        if ($hasHeader || $variant === 'flush') {
            $frame .= ' overflow-hidden';
        }

        $defaultPadding = match (true) {
            $variant === 'flush' => '',
            $hasHeader => 'px-5 py-5 sm:px-6 sm:py-6',
            default => 'p-6',
        };
        $bodyPadding = $padding ?? $defaultPadding;
        $framePadding = $hasHeader ? '' : $bodyPadding;
    }
@endphp

<{{ $tag }} {{ $attributes->class(trim("{$frame} {$framePadding}")) }} data-surface-variant="{{ $variant }}">
    @if ($hasHeader)
        @if ($isFilled($header ?? null))
            {{ $header }}
        @elseif ($variant === 'ops')
            <header class="ops-toolbar">
                <div class="min-w-0">
                    <{{ $headingTag }}>{{ $title }}</{{ $headingTag }}>
                    @if ($isFilled($description))
                        <p class="ops-muted">{{ $description }}</p>
                    @endif
                </div>
                @if ($isFilled($actions ?? null))
                    <div class="ops-actions">{{ $actions }}</div>
                @endif
            </header>
        @else
            <header class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-rt-border/70 bg-rt-surface-muted/45 px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/30 sm:gap-5 sm:px-6 sm:py-5">
                <div class="min-w-0">
                    @if ($isFilled($title))
                        <{{ $headingTag }} class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                            {{ $title }}
                        </{{ $headingTag }}>
                    @endif
                    @if ($isFilled($description))
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                            {{ $description }}
                        </p>
                    @endif
                </div>

                @if ($isFilled($actions ?? null))
                    <div class="flex min-h-7 shrink-0 items-center justify-end gap-2">
                        {{ $actions }}
                    </div>
                @endif
            </header>
        @endif

        @if (filled($bodyPadding))
            <div class="{{ $bodyPadding }}">
                {{ $slot }}
            </div>
        @else
            {{ $slot }}
        @endif
    @else
        {{ $slot }}
    @endif
</{{ $tag }}>
