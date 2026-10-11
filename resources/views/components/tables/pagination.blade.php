@props([
    'paginator',
    'label' => __('pagination.pagination_navigation'),
    'tableKey' => null,
])

@php
    // Numeric, length-aware pagination only. Queries and page state stay with
    // the original Livewire owner; this component only renders native actions.
    $pageName = $paginator->getPageName();
    $scopeKey = (string) ($tableKey ?? $pageName);
    $keyPrefix = 'table-pagination-'.substr(hash('sha256', $scopeKey.'|'.$pageName), 0, 12);
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $pageNumbers = $lastPage <= 7
        ? range(1, $lastPage)
        : collect([1, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage])
            ->filter(fn ($page) => $page >= 1 && $page <= $lastPage)
            ->unique()->sort()->values()->all();
    $action = fn ($method, $page = null) => $method.'('.($page === null ? '' : $page.', ').json_encode($pageName).')';
@endphp

<nav {{ $attributes->class(['rt-table-pagination']) }} role="navigation" aria-label="{{ $label }}"
    data-table-pagination data-table-pagination-key="{{ $scopeKey }}" data-table-page-name="{{ $pageName }}"
    x-data="{}"
    x-on:click="
        if (!$event.target.closest('button[data-table-page-action]:not(:disabled)')) return;
        const owner = $el.closest('[wire\\:id]');
        const key = $el.dataset.tablePaginationKey;
        const table = Array.from(owner?.querySelectorAll('[data-rt-premium-table][data-table-pagination-key]') ?? [])
            .find(candidate => candidate.closest('[wire\\:id]') === owner && candidate.dataset.tablePaginationKey === key);
        table?.querySelector('[data-table-scroll-body]')?.scrollTo({ top: 0, behavior: 'instant' });
    "
>
    <span class="rt-table-pagination__range" aria-live="polite" aria-atomic="true"
        aria-label="{{ __('pagination.showing_results', ['from' => $paginator->firstItem() ?? 0, 'to' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}">
        {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}
        <span>{{ __('pagination.of') }} {{ $paginator->total() }}</span>
    </span>
    @if($paginator->hasPages())
        <div class="rt-table-pagination__controls" data-table-page-menu wire:loading.attr="aria-busy">
            {{-- Same clipped moving surface as the area menu; native page state stays authoritative. --}}
            <span class="rt-area-menu__indicator rt-table-pagination__indicator" aria-hidden="true" wire:ignore
                x-data="rtAreaMenu({ optionSelector: '[data-table-page-indicator-option]', visualSelector: '[data-table-page-label]', allowDisabledActive: true })"></span>
            <button type="button" class="rt-table-pagination__button" data-table-page-action
                wire:key="{{ $keyPrefix }}-previous" wire:click="{{ $action('previousPage') }}" wire:loading.attr="disabled"
                @disabled($paginator->onFirstPage()) aria-label="{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}">
                <i class="far fa-chevron-left" aria-hidden="true"></i>
            </button>
            <span class="rt-table-pagination__mobile-page" aria-hidden="true" data-table-page-indicator-option aria-current="page">
                <span data-table-page-label>{{ $currentPage }} / {{ $lastPage }}</span>
            </span>
            @php $previousNumber = null; @endphp
            @foreach($pageNumbers as $page)
                @if($previousNumber !== null && $page > $previousNumber + 1)
                    <span class="rt-table-pagination__ellipsis" aria-hidden="true" wire:key="{{ $keyPrefix }}-gap-{{ $previousNumber }}">…</span>
                @endif
                <button type="button" class="rt-table-pagination__button rt-table-pagination__page-number" data-table-page-action data-table-page-indicator-option
                    wire:key="{{ $keyPrefix }}-page-{{ $page }}" wire:click="{{ $action('gotoPage', $page) }}" wire:loading.attr="disabled"
                    @if($page === $currentPage) aria-current="page" @endif @disabled($page === $currentPage)
                    aria-label="{{ __('pagination.go_to_page', ['page' => $page]) }}"><span data-table-page-label>{{ $page }}</span></button>
                @php $previousNumber = $page; @endphp
            @endforeach
            <button type="button" class="rt-table-pagination__button" data-table-page-action
                wire:key="{{ $keyPrefix }}-next" wire:click="{{ $action('nextPage') }}" wire:loading.attr="disabled"
                @disabled(!$paginator->hasMorePages()) aria-label="{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}">
                <i class="far fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
        <span class="sr-only">{{ __('pagination.go_to_page', ['page' => $currentPage]) }} / {{ $lastPage }}</span>
    @endif
</nav>
