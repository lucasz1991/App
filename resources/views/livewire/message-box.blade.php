<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page :title="__('app.messages')" :eyebrow="__('app.personal_data')" :count="$messages->total()">
        <div class="space-y-3 sm:space-y-5">
            {{-- Suche --}}
            <x-tables.toolbar
                id="message-list-filters"
                :filter-count="trim($search) !== '' ? 1 : 0"
                :title="__('app.search')"
                reset-action="resetFilters"
                search-for="message-search"
            >
                <x-slot:search>
                    <x-tables.search-field
                        id="message-search"
                        :results-count="$messages->count()"
                        wire:model.live.debounce.400ms="search"
                    />
                </x-slot:search>

                <x-slot:chips>
                    @if (trim($search) !== '')
                        <x-tables.filter-chip :label="__('app.search')" :value="$search" wire:click="$set('search', '')" />
                    @endif
                </x-slot:chips>
            </x-tables.toolbar>

            {{-- Nachrichten-Tabelle --}}
            <x-tables.table
                :columns="[
                    ['label' => __('app.from'),     'key' => 'from',       'width' => '25%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.subject'),  'key' => 'subject',    'width' => '25%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.message'),  'key' => 'snippet',    'width' => '30%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.date'),     'key' => 'created_at', 'width' => '20%', 'sortable' => false, 'hideOn' => 'sm'],
                ]"
                :items="$messages"
                :selected-items="$selectedMessages"
                selection-action="toggleMessageSelection"
                detail-action="openMessageDetail"
                row-view="components.tables.rows.messages.row"
                actions-view="components.tables.rows.messages.actions"
                class="mt-3 sm:mt-4"
            />

            @if ($messages->hasMorePages())
                <div class="text-center"
                     x-data="{ isClicked: false }"
                     @click="isClicked = true; setTimeout(() => isClicked = false, 100)">
                    <button :style="isClicked ? 'transform:scale(0.9)' : 'transform:scale(1)'"
                            wire:click="loadMore"
                            class="mb-2 me-2 transform rounded-lg bg-rt-surface px-5 py-2.5 text-sm font-medium text-rt-text shadow-rt-xs ring-1 ring-rt-border transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted hover:text-rt-red focus:z-10 focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent">
                        {{ __('app.load_more_messages') }}
                    </button>
                </div>
            @endif
        </div>
    </x-ui.page>

</div>
