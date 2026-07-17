<div class="w-full relative" wire:loading.class="cursor-wait">
    <div class="mb-6 flex items-center gap-3">
        <h1 class="text-2xl font-semibold leading-tight text-gray-800 dark:text-white">
            {{ __('app.messages') }}
        </h1>
        <i class="far fa-envelope-open text-2xl text-gray-300 dark:text-slate-600" aria-hidden="true"></i>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:bg-slate-800 dark:border-slate-700">
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-800 dark:bg-emerald-900/30 dark:text-emerald-300" role="alert">
            <p>
                <span class="text-base font-medium">{{ __('app.inbox_intro_title', ['app' => config('app.name')]) }}</span><br>
                {{ __('app.inbox_intro_text') }}
            </p>
        </div>

        <div class="mt-8 space-y-5">
            {{-- Suche --}}
            <div class="flex flex-col items-center justify-between space-y-3 md:flex-row md:space-y-0">
                <div class="w-full md:w-1/2">
                    <label for="message-search" class="sr-only">{{ __('app.search') }}</label>
                    <div class="relative w-full">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <i class="far fa-search text-gray-500 dark:text-slate-400" aria-hidden="true"></i>
                        </div>
                        <input type="text" id="message-search"
                               wire:model.live.debounce.400ms="search"
                               placeholder="{{ __('app.search') }}"
                               class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 pl-10 text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:placeholder-slate-500">
                    </div>
                </div>
            </div>

            {{-- Nachrichten-Tabelle --}}
            <x-tables.table
                :columns="[
                    ['label' => __('app.from'),     'key' => 'from',       'width' => '25%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.subject'),  'key' => 'subject',    'width' => '25%', 'sortable' => false, 'hideOn' => 'none'],
                    ['label' => __('app.message'),  'key' => 'snippet',    'width' => '30%', 'sortable' => false, 'hideOn' => 'md'],
                    ['label' => __('app.date'),     'key' => 'created_at', 'width' => '20%', 'sortable' => false, 'hideOn' => 'none'],
                ]"
                :items="$messages"
                row-view="components.tables.rows.user-messages.row"
                actions-view="components.tables.rows.user-messages.actions"
            />

            @if ($messages->hasMorePages())
                <div class="mt-8 text-center"
                     x-data="{ isClicked: false }"
                     @click="isClicked = true; setTimeout(() => isClicked = false, 100)">
                    <button :style="isClicked ? 'transform:scale(0.9)' : 'transform:scale(1)'"
                            wire:click="loadMore"
                            class="mb-2 me-2 transform rounded-lg border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-900 transition-all duration-100 hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:outline-none focus:ring-4 focus:ring-gray-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:ring-slate-700">
                        {{ __('app.load_more_messages') }}
                    </button>
                </div>
            @endif
        </div>
    </div>

    <x-ui.messages.message-show-modal
        model="showMessageModal"
        :message="$selectedMessage"
    />
</div>
