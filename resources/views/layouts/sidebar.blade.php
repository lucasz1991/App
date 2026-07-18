<div class="fixed bottom-0 z-10 h-screen vertical-menu rtl:right-0 ltr:left-0 top-[70px] pt-12 bg-gradient-to-b from-rt-surface via-rt-surface-muted to-rt-sidebar text-rt-text shadow-[6px_0_28px_-10px_rgba(15,23,42,0.10)] dark:from-rt-dark-surface dark:via-rt-dark-sidebar dark:to-rt-dark-canvas dark:text-rt-dark-text dark:shadow-[6px_0_28px_-10px_rgba(0,0,0,0.45)] print:hidden">
    <div data-simplebar class="h-full">
        @if (($area ?? 'admin') === 'user')
            @include('layouts.user-sidebar')
        @else
            @include('layouts.admin-sidebar')
        @endif
    </div>
</div>
