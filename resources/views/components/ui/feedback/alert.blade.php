@props(['type' => 'info', 'title' => null, 'inline' => false, 'dismissible' => true])

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

<div class="rt-ui-alert-viewport" style="overflow:clip">
<div x-data="{
    visible: false,
    revealTimer: null,
    init() {
        this.revealTimer = setTimeout(() => {
            this.visible = true
            this.$nextTick(() => {
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    this.$el.animate(
                        [{ transform: 'translateX(100%)' }, { transform: 'translateX(0)' }],
                        { duration: 650, easing: 'cubic-bezier(.22, 1, .36, 1)' }
                    )
                }
            })
        }, 1000)
    },
    destroy() {
        clearTimeout(this.revealTimer)
        this.$el.getAnimations().forEach(animation => animation.cancel())
    }
}" x-show.important="visible" x-cloak @if (!$attributes->has('wire:ignore.self')) wire:ignore.self @endif data-rt-tone="{{ $type }}" {{ $attributes->class(["rt-ui-alert relative flex rounded-xl border {$styles}", 'gap-3 p-4 shadow-rt-xs' => ! $inline, 'rt-ui-alert--dismissible' => $dismissible]) }} @if($inline) style="align-items:center;gap:.5rem;padding:.25rem .75rem;box-shadow:none" @endif role="alert">
    <style>
        .rt-ui-alert--dismissible { padding-right: 2.75rem !important; }
        .rt-ui-alert__close { position: absolute; top: .375rem; right: .375rem; display: flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; padding: 0; border: 0; border-radius: .375rem; color: inherit; background: transparent; opacity: .55; cursor: pointer; }
        .rt-ui-alert__close:hover { opacity: 1; background: color-mix(in srgb, currentColor 9%, transparent); }
        .rt-ui-alert__close:focus-visible { opacity: 1; outline: 2px solid currentColor; outline-offset: 1px; }
        .rt-ui-alert__warning-icon { display: flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: color-mix(in srgb, currentColor 12%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 4%, transparent); }
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
            .rt-ui-alert__action { transition: none; }
            .rt-ui-alert__warning-icon { animation: none !important; }
        }
    </style>
    <span @class(['flex shrink-0 items-center justify-center', "h-9 w-9 rounded-lg {$iconStyles}" => ! $inline]) @if($inline) style="width:1.75rem;height:1.75rem" @endif aria-hidden="true">
        @if ($type === 'warning')
            <span class="rt-ui-alert__warning-icon animate-pulse motion-reduce:animate-none"><i class="far fa-exclamation" aria-hidden="true"></i></span>
        @else
            <i class="far {{ $icon }}"></i>
        @endif
    </span>
    @if ($dismissible)
        <button type="button" class="rt-ui-alert__close" x-on:click="visible = false" aria-label="Hinweis schließen" title="Schließen">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m4 4 8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg>
        </button>
    @endif
    <div @class(['min-w-0 text-sm', 'flex-1' => $inline])>
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>
</div>
