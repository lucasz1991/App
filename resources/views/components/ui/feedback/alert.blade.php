@props(['type' => 'info', 'title' => null, 'inline' => false])

@php
    $styles = match ($type) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100',
        default => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-400/30 dark:bg-sky-400/10 dark:text-sky-100',
    };
    $iconStyles = match ($type) {
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-200',
        'danger' => 'bg-rose-100 text-rose-700 dark:bg-rose-400/15 dark:text-rose-200',
        default => 'bg-sky-100 text-sky-700 dark:bg-sky-400/15 dark:text-sky-200',
    };
    $icon = match ($type) {
        'success' => 'fa-check-circle',
        'warning' => 'fa-exclamation-triangle',
        'danger' => 'fa-times-circle',
        default => 'fa-info-circle',
    };
@endphp

<div data-rt-tone="{{ $type }}" {{ $attributes->class(["rt-ui-alert flex rounded-xl border {$styles}", 'gap-3 p-4 shadow-rt-xs' => ! $inline]) }} @if($inline) style="align-items:center;gap:.5rem;padding:.25rem .75rem;box-shadow:none" @endif role="alert">
    <style>
        @keyframes rt-alert-enter {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: no-preference) {
            .rt-ui-alert { animation: rt-alert-enter 240ms ease-out; }
        }
        .rt-ui-alert__action {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            flex-shrink: 0; margin-left: auto; min-height: 2rem; padding: .375rem .75rem;
            border: 1px solid color-mix(in srgb, currentColor 18%, transparent);
            border-radius: .5rem; background: color-mix(in srgb, currentColor 9%, transparent);
            color: inherit; font: inherit; font-weight: 600; line-height: 1.25;
            cursor: pointer; transition: background-color 160ms ease, border-color 160ms ease;
        }
        .rt-ui-alert__action:hover:not(:disabled) { background: color-mix(in srgb, currentColor 16%, transparent); border-color: color-mix(in srgb, currentColor 30%, transparent); }
        .rt-ui-alert__action:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
        .rt-ui-alert__action:disabled { opacity: .5; cursor: not-allowed; }
        @media (prefers-reduced-motion: reduce) {
            .rt-ui-alert { animation: none; }
            .rt-ui-alert__action { transition: none; }
        }
    </style>
    <span @class(['flex shrink-0 items-center justify-center', "h-9 w-9 rounded-lg {$iconStyles}" => ! $inline]) @if($inline) style="width:1.25rem;height:1.25rem" @endif aria-hidden="true">
        <i class="far {{ $icon }}"></i>
    </span>
    <div @class(['min-w-0 text-sm', 'flex-1' => $inline])>
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>
