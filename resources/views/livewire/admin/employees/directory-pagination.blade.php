<nav role="navigation" aria-label="{{ __('pagination.pagination_navigation') }}" class="employee-directory__pager">
    <span class="employee-directory__range">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} <span>von {{ $paginator->total() }}</span></span>
    @if($paginator->hasPages())
        <div class="employee-directory__page-buttons">
            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled"
                @disabled($paginator->onFirstPage()) aria-label="{{ __('pagination.previous') }}"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
            <span class="employee-directory__mobile-page">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @foreach($elements as $element)
                @if(is_string($element))
                    <span class="employee-directory__page-number" aria-hidden="true">{{ $element }}</span>
                @else
                    @foreach($element as $page => $url)
                        <button class="employee-directory__page-number" type="button" wire:key="employee-page-{{ $page }}"
                            wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled"
                            @if($page === $paginator->currentPage()) aria-current="page" @endif
                            aria-label="{{ __('pagination.go_to_page', ['page'=>$page]) }}">{{ $page }}</button>
                    @endforeach
                @endif
            @endforeach
            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled"
                @disabled(!$paginator->hasMorePages()) aria-label="{{ __('pagination.next') }}"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
        </div>
    @endif
</nav>
