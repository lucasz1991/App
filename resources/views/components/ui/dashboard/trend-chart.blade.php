@props([
    'title',
    'description' => null,
    'labels' => [],
    'values' => [],
    'type' => 'line',
    'tone' => 'brand',
    'icon' => 'bar-chart-2',
    'summary' => null,
    'summaryLabel' => null,
    'emptyLabel' => null,
    'id' => null,
    'variant' => 'default',
])

@php
    $isMinimal = $variant === 'minimal';
    $series = collect($values)
        ->map(fn ($value) => max(0, (float) $value))
        ->values();
    $chartLabels = collect($labels)
        ->map(fn ($label) => (string) $label)
        ->take($series->count())
        ->values();
    $pointCount = min($series->count(), $chartLabels->count());
    $series = $series->take($pointCount)->values();
    $chartLabels = $chartLabels->take($pointCount)->values();
    $peak = max(1, (float) ($series->max() ?? 0));
    $hasSignal = $series->sum() > 0;
    $resolvedSummary = $summary ?? ($type === 'line' ? ($series->last() ?? 0) : $series->sum());
    $resolvedEmptyLabel = $emptyLabel ?: __('app.no_activity_yet');
    $chartId = $id ?: 'rt-dashboard-chart-' . substr(sha1(
        (string) $title . '|' . $type . '|' . $chartLabels->implode('|')
    ), 0, 12);

    $toneClasses = match ($tone) {
        'success' => 'text-emerald-500 dark:text-emerald-400',
        'warning' => 'text-amber-500 dark:text-amber-400',
        'neutral' => 'text-slate-500 dark:text-slate-300',
        default => 'text-rt-red dark:text-rt-red-light',
    };

    $coordinates = $series->map(function (float $value, int $index) use ($pointCount, $peak): array {
        $x = $pointCount <= 1 ? 50 : ($index / ($pointCount - 1)) * 100;
        $y = 44 - (($value / $peak) * 36);

        return [
            'x' => number_format($x, 3, '.', ''),
            'y' => number_format($y, 3, '.', ''),
            'value' => $value,
        ];
    });
    $linePoints = $coordinates
        ->map(fn (array $point) => $point['x'] . ',' . $point['y'])
        ->implode(' ');
    $areaPoints = '0,44 ' . $linePoints . ' 100,44';
    $slotWidth = $pointCount > 0 ? 100 / $pointCount : 100;
    $barWidth = min(7, max(2.25, $slotWidth * 0.58));
    $axisLabels = $pointCount > 0
        ? [
            ['label' => $chartLabels->first(), 'value' => $series->first()],
            [
                'label' => $pointCount > 2 ? $chartLabels[(int) floor(($pointCount - 1) / 2)] : '',
                'value' => $pointCount > 2 ? $series[(int) floor(($pointCount - 1) / 2)] : null,
            ],
            [
                'label' => $pointCount > 1 ? $chartLabels->last() : '',
                'value' => $pointCount > 1 ? $series->last() : null,
            ],
        ]
        : [];
@endphp

<figure
    {{ $attributes->except('id')->class([
        'relative min-w-0 overflow-hidden bg-rt-surface p-4 sm:p-5 dark:bg-rt-dark-surface',
        'rounded-xl border border-rt-border/80 dark:border-rt-dark-border/80' => $isMinimal,
        'rounded-[1.5rem] shadow-rt-sm ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70' => ! $isMinimal,
    ]) }}
    id="{{ $chartId }}"
    data-dashboard-chart
    data-dashboard-chart-type="{{ $type }}"
    data-dashboard-chart-variant="{{ $variant }}"
    aria-labelledby="{{ $chartId }}-title"
>
    <div class="flex min-w-0 items-start justify-between gap-4">
        <figcaption class="flex min-w-0 items-start gap-3">
            @if ($isMinimal)
                <span class="mt-1.5 h-5 w-1 shrink-0 rounded-sm bg-rt-red" aria-hidden="true"></span>
            @else
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70" aria-hidden="true">
                    <i data-feather="{{ $icon }}" class="h-5 w-5"></i>
                </span>
            @endif
            <span class="min-w-0">
                <span id="{{ $chartId }}-title" class="block text-sm font-bold tracking-[-0.015em] text-rt-text dark:text-white">
                    {{ $title }}
                </span>
                @if (filled($description))
                    <span class="mt-1 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ $description }}
                    </span>
                @endif
            </span>
        </figcaption>

        <dl class="shrink-0 text-right">
            <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">
                {{ $summaryLabel ?: __('app.current') }}
            </dt>
            <dd
                class="mt-1 text-xl font-bold tabular-nums tracking-[-0.04em] text-rt-text dark:text-white"
                data-dashboard-chart-summary="{{ (float) $resolvedSummary }}"
            >
                {{ number_format((float) $resolvedSummary, 0, ',', '.') }}
            </dd>
        </dl>
    </div>

    @if ($pointCount > 0 && $hasSignal)
        <div class="mt-5 min-w-0" data-dashboard-chart-plot>
            <svg
                class="{{ $isMinimal ? 'h-28 sm:h-32' : 'h-36' }} w-full overflow-visible {{ $toneClasses }}"
                viewBox="0 0 100 48"
                preserveAspectRatio="none"
                role="img"
                aria-label="{{ $title }}: {{ number_format((float) $resolvedSummary, 0, ',', '.') }}"
            >
                <title>{{ $title }}</title>
                @unless ($isMinimal)
                    <defs>
                        <linearGradient id="{{ $chartId }}-fill" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="currentColor" stop-opacity="0.22"></stop>
                            <stop offset="100%" stop-color="currentColor" stop-opacity="0.015"></stop>
                        </linearGradient>
                    </defs>
                @endunless

                <g class="text-rt-border dark:text-rt-dark-border {{ $isMinimal ? 'opacity-60' : '' }}" fill="none" stroke="currentColor" stroke-width="0.45" vector-effect="non-scaling-stroke">
                    <path d="M0 8 H100"></path>
                    <path d="M0 20 H100"></path>
                    <path d="M0 32 H100"></path>
                    <path d="M0 44 H100"></path>
                </g>

                <g class="{{ $toneClasses }}">
                    @if ($type === 'bar')
                        @foreach ($coordinates as $index => $point)
                            @php
                                $height = ($point['value'] / $peak) * 36;
                                $renderedHeight = max($height, 0.8);
                                $x = ($index * $slotWidth) + (($slotWidth - $barWidth) / 2);
                            @endphp
                            <rect
                                x="{{ number_format($x, 3, '.', '') }}"
                                y="{{ number_format(44 - $renderedHeight, 3, '.', '') }}"
                                width="{{ number_format($barWidth, 3, '.', '') }}"
                                height="{{ number_format($renderedHeight, 3, '.', '') }}"
                                rx="1.15"
                                fill="currentColor"
                                opacity="{{ $index === $pointCount - 1 ? '1' : '0.72' }}"
                                data-dashboard-chart-point="{{ (float) $point['value'] }}"
                            >
                                <title>{{ $chartLabels[$index] }}: {{ number_format($point['value'], 0, ',', '.') }}</title>
                            </rect>
                        @endforeach
                    @else
                        @unless ($isMinimal)
                            <polygon points="{{ $areaPoints }}" fill="url(#{{ $chartId }}-fill)"></polygon>
                        @endunless
                        <polyline
                            points="{{ $linePoints }}"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.25"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        ></polyline>
                        @foreach ($coordinates as $index => $point)
                            <circle
                                cx="{{ $point['x'] }}"
                                cy="{{ $point['y'] }}"
                                r="{{ $index === $pointCount - 1 ? '1.7' : '1.05' }}"
                                fill="currentColor"
                                class="stroke-rt-surface dark:stroke-rt-dark-surface"
                                stroke-width="0.75"
                                vector-effect="non-scaling-stroke"
                                data-dashboard-chart-point="{{ (float) $point['value'] }}"
                            >
                                <title>{{ $chartLabels[$index] }}: {{ number_format($point['value'], 0, ',', '.') }}</title>
                            </circle>
                        @endforeach
                    @endif
                </g>
            </svg>

            <div class="mt-1 grid grid-cols-3 gap-2 text-[10px] leading-4 text-rt-soft dark:text-rt-dark-soft" aria-hidden="true">
                @foreach ($axisLabels as $index => $entry)
                    <span @class([
                        'min-w-0 truncate',
                        'text-center' => $index === 1,
                        'text-right' => $index === 2,
                    ])>
                        {{ $entry['label'] }}
                        @if ($pointCount <= 4 && $entry['value'] !== null)
                            <strong class="ml-1 font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">
                                {{ number_format($entry['value'], 0, ',', '.') }}
                            </strong>
                        @endif
                    </span>
                @endforeach
            </div>

            <ol class="sr-only">
                @foreach ($series as $index => $value)
                    <li>{{ $chartLabels[$index] }}: {{ number_format($value, 0, ',', '.') }}</li>
                @endforeach
            </ol>
        </div>
    @else
        <div class="mt-5 flex min-h-28 flex-col items-center justify-center rounded-lg border border-dashed border-rt-border bg-transparent px-4 text-center dark:border-rt-dark-border" data-dashboard-chart-empty>
            @unless ($isMinimal)
                <i data-feather="minus" class="h-5 w-5 text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
            @endunless
            <p class="{{ $isMinimal ? '' : 'mt-2' }} text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                {{ $resolvedEmptyLabel }}
            </p>
        </div>
    @endif
</figure>
