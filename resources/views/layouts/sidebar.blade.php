<div class="fixed bottom-0 z-10 h-screen ltr:border-r rtl:border-l vertical-menu rtl:right-0 ltr:left-0 top-[70px] pt-12 bg-slate-50 border-gray-50 dark:bg-slate-900 dark:border-slate-800 print:hidden">
    <div data-simplebar class="h-full">
        @if (($area ?? 'admin') === 'user')
            @include('layouts.user-sidebar')
        @else
            @include('layouts.admin-sidebar')
        @endif
    </div>
</div>
