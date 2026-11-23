{{-- Safety net: every ->links() in a Livewire component renders the shared
     table pager. New views call <x-tables.pagination> directly. --}}
@php
    $scrollTo = isset($scrollTo) ? $scrollTo : 'body';
@endphp
<x-tables.pagination :paginator="$paginator" :scroll-to="$scrollTo === false ? null : $scrollTo" />
