<x-ui.page
    :title="__('app.messages_and_emails')"
    :eyebrow="__('app.administration')"
    :description="__('app.entries_in_mail_log', ['count' => $mails->total()])"
>
    <x-slot:actions>
        @if (Auth::user()->isAdmin())
            <span class="inline-flex h-9 max-w-64 items-center gap-2 rounded-lg bg-rt-surface px-3 text-xs shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <i class="far fa-shield-alt shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                <span class="hidden shrink-0 font-semibold uppercase tracking-wide text-rt-soft dark:text-rt-dark-soft sm:inline">Super Admin</span>
                <span class="truncate font-semibold text-rt-text dark:text-rt-dark-text">{{ config('mail.super_admin') ?: __('app.not_set') }}</span>
            </span>
        @endif
    </x-slot:actions>

    @if (session()->has('message'))
        <x-ui.feedback.alert type="success">{{ session('message') }}</x-ui.feedback.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.feedback.alert type="danger">{{ session('error') }}</x-ui.feedback.alert>
    @endif

    <div data-anim="fade-up" data-anim-delay="0.05">
        <div class="mb-3 flex items-center justify-end">
            <x-tables.search-field
                :results-count="$mails->count()"
                wire:model.live.debounce.350ms="search"
            />
        </div>

        <x-tables.table
            :columns="[
                ['label' => 'ID', 'key' => 'id', 'width' => '12%', 'sortable' => true, 'hideOn' => 'none'],
                ['label' => __('app.date'), 'key' => 'created_at', 'width' => '20%', 'sortable' => true, 'hideOn' => 'md'],
                ['label' => __('app.delivery_type'), 'key' => 'type', 'width' => '20%', 'sortable' => false, 'hideOn' => 'none'],
                ['label' => __('app.recipients'), 'key' => 'recipients', 'width' => '24%', 'sortable' => false, 'hideOn' => 'lg'],
                ['label' => __('app.status'), 'key' => 'status', 'width' => '24%', 'sortable' => true, 'hideOn' => 'none'],
            ]"
            :items="$mails"
            :selected-items="$selectedMails"
            selection-action="toggleMailSelection"
            detail-action="toggleMailDetails"
            row-view="components.tables.rows.mails.row"
            actions-view="components.tables.rows.mails.actions"
            details-view="components.tables.rows.mails.details"
            :expanded-id="$expandedMailId"
            :sort-by="$sortBy"
            :sort-dir="$sortDirection"
        />

        <div class="pt-4">
            {{ $mails->links() }}
        </div>
    </div>
</x-ui.page>
