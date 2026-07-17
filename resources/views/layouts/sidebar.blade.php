<div class="fixed bottom-0 z-10 h-screen ltr:border-r rtl:border-l vertical-menu rtl:right-0 ltr:left-0 top-[70px] pt-12 border-slate-200 bg-gradient-to-b from-white via-slate-50 to-slate-100 text-slate-700 shadow-[4px_0_24px_rgba(15,23,42,0.04)] dark:border-slate-800 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 dark:text-slate-200 dark:shadow-[4px_0_24px_rgba(0,0,0,0.2)] print:hidden">
    <div data-simplebar class="h-full">
        @if (($area ?? 'admin') === 'user')
            @include('layouts.user-sidebar')
        @else
            @include('layouts.admin-sidebar')
        @endif
    </div>
</div>
