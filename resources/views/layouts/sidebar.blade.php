<div class="rt-mobile-sidebar-backdrop fixed inset-x-0 bottom-0 top-[70px] z-20 bg-slate-950/45 backdrop-blur-[2px] print:hidden" aria-hidden="true"></div>

<aside
    id="app-sidebar"
    class="rt-ui-sidebar rt-shell-sidebar fixed bottom-0 top-[70px] z-30 h-[calc(100vh-70px)] vertical-menu pt-12 text-rt-text shadow-[6px_0_28px_-10px_rgba(15,23,42,0.10)] rtl:right-0 ltr:left-0 dark:text-rt-dark-text dark:shadow-[6px_0_28px_-10px_rgba(0,0,0,0.45)] print:hidden"
    aria-label="{{ __('app.mobile_navigation') }}"
    data-rt-shell-sidebar
>
    <div data-simplebar class="h-full">
        @if (($area ?? 'admin') === 'user')
            @include('layouts.user-sidebar')
        @else
            @include('layouts.admin-sidebar')
        @endif
    </div>
</aside>
