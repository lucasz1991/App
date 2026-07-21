<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page :title="__('app.messages')" :eyebrow="__('app.personal_data')" :count="$messages->total()">
        <div class="space-y-3 sm:space-y-5">
            {{-- Suche --}}
            <div class="rounded-xl bg-rt-surface p-3 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-4 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="flex flex-col items-center justify-between space-y-3 md:flex-row md:space-y-0">
                    <div class="w-full md:w-1/2">
                        <label for="message-search" class="sr-only">{{ __('app.search') }}</label>
                        <div class="relative w-full">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="far fa-search text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                            </div>
                            <x-ui.forms.input type="text" id="message-search"
                                   wire:model.live.debounce.400ms="search"
                                   placeholder="{{ __('app.search') }}"
                                   class="block p-2 pl-10 text-sm" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- Nachrichten-Tabelle --}}
            <x-tables.table
                :columns="[
                    ['label' => __('app.from'),     'key' => 'from',       'width' => '25%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.subject'),  'key' => 'subject',    'width' => '25%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.message'),  'key' => 'snippet',    'width' => '30%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.date'),     'key' => 'created_at', 'width' => '20%', 'sortable' => false, 'hideOn' => 'sm'],
                ]"
                :items="$messages"
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
