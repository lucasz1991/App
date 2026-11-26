@props(['color' => 'slate', 'size' => 'md', 'icon' => null])

@php
    // Einheitliche Badge-Farben im RailTime-Designsystem. Rot nutzt den
    // Markenakzent (rt-red), die uebrigen bleiben semantische Info-Farben.
    // Fachliche Status (Auftraege, Schichten, Zuordnungen …) laufen ueber
    // x-operations.status, das diese Komponente rendert.
    $map = [
        'yellow' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        'amber'  => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        'blue'   => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
        'sky'    => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
        'cyan'   => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20 dark:bg-cyan-500/10 dark:text-cyan-300 dark:ring-cyan-500/30',
        'purple' => 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-300 dark:ring-purple-500/30',
        'green'  => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        'emerald'=> 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        'red'    => 'bg-rt-red/10 text-rt-red ring-rt-red/20 dark:bg-rt-red/15 dark:text-rt-red',
        'slate'  => 'bg-rt-surface-muted text-rt-muted ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60',
    ];

    $classes = $map[$color] ?? $map['slate'];

    // size="sm" ist die kompakte Variante fuer Status in Listen, Karten und
    // Kopfzeilen. Abstand, Radius und Schriftgroesse kommen dort aus app.css
    // (.rt-ui-badge[data-rt-size="sm"]), damit Seiten-CSS sie anpassen kann –
    // die Legacy-Utilities in legacy-allowlist.css tragen !important.
    $compact = $size === 'sm';
    $base = $compact
        ? 'rt-ui-badge inline-flex items-center gap-1 font-semibold ring-1 ring-inset '
        : 'rt-ui-badge inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ';

    $iconClass = filled($icon) ? (str_contains($icon, ' ') ? $icon : 'far '.$icon) : null;
@endphp

<span data-rt-tone="{{ $color }}" @if($compact) data-rt-size="sm" @endif {{ $attributes->class($base . $classes) }}>
    @if($iconClass)<i class="{{ $iconClass }}" aria-hidden="true"></i>@endif
    {{ $slot }}
</span>
