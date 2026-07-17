<div class="fixed bottom-0 z-10 h-screen ltr:border-r rtl:border-l vertical-menu rtl:right-0 ltr:left-0 top-[70px] pt-12 border-rt-border bg-gradient-to-b from-rt-surface via-rt-surface-muted to-rt-sidebar text-rt-text shadow-[4px_0_24px_rgba(15,23,42,0.04)] dark:border-rt-dark-border dark:from-rt-dark-surface dark:via-rt-dark-sidebar dark:to-rt-dark-canvas dark:text-rt-dark-text dark:shadow-[4px_0_24px_rgba(0,0,0,0.2)] print:hidden">
    <div data-simplebar class="h-full">
        @if (($area ?? 'admin') === 'user')
            @include('layouts.user-sidebar')
        @else
            @include('layouts.admin-sidebar')
        @endif
    </div>
</div>
