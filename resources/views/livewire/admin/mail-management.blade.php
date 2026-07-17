<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">Administration</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.messages_and_emails') }}</h1>
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.entries_in_mail_log', ['count' => $mails->total()]) }}</p>
        </div>
        @if (Auth::user()->isAdmin())
            <div class="rounded-xl border border-rt-border bg-rt-surface px-4 py-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
                <p class="text-xs font-semibold uppercase tracking-wide text-rt-accent dark:text-rt-dark-accent">Super Admin</p>
                <p class="font-semibold text-rt-text dark:text-white">{{ config('mail.super_admin') ?: __('app.not_set') }}</p>
            </div>
        @endif
    </div>

    @if (session()->has('message'))
        <x-ui.feedback.alert type="success">{{ session('message') }}</x-ui.feedback.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.feedback.alert type="danger">{{ session('error') }}</x-ui.feedback.alert>
    @endif

    <x-tables.table
        :columns="[
            ['label' => 'ID', 'key' => 'id', 'width' => '12%', 'sortable' => true, 'hideOn' => 'none'],
            ['label' => __('app.date'), 'key' => 'created_at', 'width' => '20%', 'sortable' => true, 'hideOn' => 'md'],
            ['label' => __('app.delivery_type'), 'key' => 'type', 'width' => '20%', 'sortable' => false, 'hideOn' => 'none'],
            ['label' => __('app.recipients'), 'key' => 'recipients', 'width' => '24%', 'sortable' => false, 'hideOn' => 'lg'],
            ['label' => __('app.status'), 'key' => 'status', 'width' => '24%', 'sortable' => true, 'hideOn' => 'none'],
        ]"
        :items="$mails"
        row-view="components.tables.rows.mails.row"
        actions-view="components.tables.rows.mails.actions"
        details-view="components.tables.rows.mails.details"
        :expanded-id="$expandedMailId"
        :sort-by="$sortBy"
        :sort-dir="$sortDirection"
    />

    {{ $mails->links() }}
</div>
