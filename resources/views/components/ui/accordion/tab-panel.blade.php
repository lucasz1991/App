@props([
    'for' => null,
    'panelClass' => null,
    'contentClass' => null,
    'order' => null,
])

@php
    // Vor dem Lazy-Loading-Wrapper lagen Slot-Inhalte direkt im Panel. Der
    // Default-Abstand bleibt fuer Aufrufe ohne eigene Panel-Klasse erhalten;
    // explizite Layoutklassen werden dagegen getrennt am Content angewendet.
    $resolvedPanelClass = $panelClass ?? '';
    $resolvedContentClass = $contentClass ?? (is_null($panelClass) ? 'space-y-6' : '');
@endphp

{{-- Alle Panels liegen nebeneinander in der Inhaltsbahn (rt-tab-panels-track)
     und werden gemeinsam mit der Navigation verschoben. Sichtbarkeit steuert
     die Bahnposition, nicht x-show; inaktive Panels sind inert. --}}
<div
    x-cloak
    role="tabpanel"
    id="panel-{{ $for }}"
    data-tab-panel-id="{{ (string) $for }}"
    @if (! is_null($order)) data-tab-index="{{ (int) $order }}" style="order: {{ (int) $order }};" @endif
    aria-labelledby="tab-{{ $for }}"
    :aria-hidden="openTab !== @js((string) $for)"
    :inert="openTab !== @js((string) $for)"
    :data-active="openTab === @js((string) $for) ? 'true' : 'false'"
    :data-loaded="isTabLoaded(@js((string) $for)) ? 'true' : 'false'"
    class="rt-tab-panel {{ $resolvedPanelClass }}"
    wire:ignore.self
>
    <div
        x-show="isTabLoaded(@js((string) $for))"
        data-rt-tab-content
        class="rt-tab-panel-content {{ $resolvedContentClass }}"
        wire:ignore.self
    >
        {{ $slot }}
    </div>

    <div
        x-show="!isTabLoaded(@js((string) $for))"
        class="rt-tab-panel-skeleton"
        role="status"
        aria-label="{{ __('app.loading') }}"
        wire:ignore.self
    >
        <span class="rt-tab-skeleton-heading"></span>
        <span class="rt-tab-skeleton-line rt-tab-skeleton-line--wide"></span>
        <span class="rt-tab-skeleton-line"></span>
        <span class="rt-tab-skeleton-card"></span>
    </div>
</div>
