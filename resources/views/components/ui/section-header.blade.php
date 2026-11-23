{{--
    Abschnittskopf (unterhalb des Seitenkopfs).

    variant:
      ops      Betriebsbereich: <header class="ops-toolbar">, Überschrift ohne
               eigene Typografie (.rt-ops h2/h3 aus operations-workspace.css),
               Eyebrow als .ops-kicker. Kontext-Overrides der Seiten-CSS greifen.
      default  Dashboard-Kopf mit optionalem Feather-Icon und Beschreibung.
      minimal  wie default, Icon als roter Strich, Überschrift halbfett.

    level:  Überschriftenebene 2–6.
    size:   nur variant=ops. null (Vorgabe) = Typografie der Ebene aus .rt-ops h2/h3;
            lg | base | sm = feste Stufe für kompakte Unterabschnitte (halbfett).
    Slot actions: Aktionen rechts im Kopf.
--}}
@props([
    'title',
    'eyebrow' => null,
    'description' => null,
    'level' => 2,
    'variant' => 'ops',
    'size' => null,
    'icon' => null,
    'id' => null,
])

@php
    $level = max(2, min(6, (int) $level));
    $tag = 'h'.$level;
    $variant = in_array($variant, ['ops', 'default', 'minimal'], true) ? $variant : 'ops';
    $hasActions = isset($actions) && $actions->isNotEmpty();
@endphp

@if ($variant === 'ops')
    @php
        $titleClass = match ($size) {
            'lg' => 'text-lg font-semibold',
            'base' => 'text-base font-semibold',
            'sm' => 'text-sm font-semibold',
            default => null,
        };
        $hasStack = filled($eyebrow) || filled($description);
    @endphp
    <header {{ $attributes->class('ops-toolbar') }}>
        @if ($hasStack)
            <div>
                @if (filled($eyebrow))
                    <p class="ops-kicker">{{ $eyebrow }}</p>
                @endif
                <{{ $tag }} @if ($id) id="{{ $id }}" @endif @if ($titleClass) class="{{ $titleClass }}" @endif>{{ $title }}</{{ $tag }}>
                @if (filled($description))
                    <p class="ops-muted">{{ $description }}</p>
                @endif
            </div>
        @else
            <{{ $tag }} @if ($id) id="{{ $id }}" @endif @if ($titleClass) class="{{ $titleClass }}" @endif>{{ $title }}</{{ $tag }}>
        @endif
        @if ($hasActions)
            {{ $actions }}
        @endif
    </header>
@else
    @php
        $headingId = $id ?: 'dashboard-section-'.\Illuminate\Support\Str::slug((string) $title);
        $isMinimal = $variant === 'minimal';
    @endphp
    <header
        {{ $attributes->class('flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between') }}
        data-dashboard-heading-variant="{{ $isMinimal ? 'minimal' : 'default' }}"
    >
        <div class="flex min-w-0 items-start gap-3">
            @if (filled($icon))
                @if ($isMinimal)
                    <span class="mt-1.5 h-5 w-1 shrink-0 rounded-sm bg-rt-red" aria-hidden="true"></span>
                @else
                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent" aria-hidden="true">
                        <i data-feather="{{ $icon }}" class="h-4 w-4"></i>
                    </span>
                @endif
            @endif

            <div class="min-w-0">
                @if (filled($eyebrow))
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red">{{ $eyebrow }}</p>
                @endif
                <{{ $tag }} id="{{ $headingId }}" @class([
                    'text-lg tracking-[-0.02em] text-rt-text dark:text-white',
                    'mt-1' => filled($eyebrow),
                    'font-semibold' => $isMinimal,
                    'font-bold' => ! $isMinimal,
                ])>
                    {{ $title }}
                </{{ $tag }}>

                @if (filled($description))
                    <p class="mt-1 max-w-2xl text-pretty text-sm leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ $description }}
                    </p>
                @endif
            </div>
        </div>

        @if ($hasActions)
            <div @class(['shrink-0', 'w-full sm:w-auto' => $isMinimal])>
                {{ $actions }}
            </div>
        @endif
    </header>
@endif
