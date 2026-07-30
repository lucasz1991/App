@props([
    'title' => null,
    'help' => null,
    'routeName' => null,
    'variant' => 'surface',
])

@php
    $resolvedRoute = $routeName ?: request()->route()?->getName();
    $resolvedHelp = $help ?: app(\App\Support\PageHelpCatalog::class)->forRoute($resolvedRoute, $title);
    $resolvedTitle = $resolvedHelp['title'] ?? $title ?? config('app.name');
    $payload = [
        'title' => $resolvedTitle,
        'summary' => $resolvedHelp['summary'] ?? null,
        'points' => array_values($resolvedHelp['points'] ?? []),
    ];
    $baseClasses = 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl outline-none transition duration-200 ease-rt-spring focus-visible:ring-4';
    $variantClasses = $variant === 'inverse'
        ? 'bg-white/[0.07] text-white/75 ring-1 ring-inset ring-white/10 hover:-translate-y-px hover:bg-white/[0.12] hover:text-white focus-visible:ring-white/25'
        : 'border border-rt-border/80 bg-rt-surface text-rt-muted shadow-rt-xs hover:-translate-y-px hover:bg-rt-accent-soft hover:text-rt-accent focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/80 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent-soft dark:hover:text-rt-dark-accent';
@endphp

@if ($resolvedHelp)
    <button
        type="button"
        x-data
        x-on:click="$dispatch('rt-info:open', {{ \Illuminate\Support\Js::from($payload) }})"
        {{ $attributes->class($baseClasses.' '.$variantClasses) }}
        aria-label="{{ app()->getLocale() === 'de' ? 'Informationen zu dieser Seite' : 'Information about this page' }}"
        title="{{ app()->getLocale() === 'de' ? 'Seitenhilfe' : 'Page help' }}"
        data-page-info-button
    >
        <i class="far fa-info-circle text-sm" aria-hidden="true"></i>
    </button>
@endif
