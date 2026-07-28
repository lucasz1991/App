@php
    $pickerMode = $pickerMode ?? 'select';
    $selectionModel = $selectionModel ?? null;
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id): int => (int) $id)->all();
    $searchId = $searchId ?? 'chat-member-search';
    $searchModel = $searchModel ?? 'search';
    $pageName = $pageName ?? 'page';
    $rowPrefix = $rowPrefix ?? 'chat-member';
@endphp

<section
    class="rt-chat-member-picker min-w-0"
    data-chat-member-picker="{{ $rowPrefix }}"
    aria-label="{{ __('app.select_participants') }}"
>
    <label for="{{ $searchId }}" class="sr-only">{{ __('app.search') }}</label>
    <div class="rt-chat-member-search relative">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-rt-muted dark:text-rt-dark-muted">
            <i class="far fa-search text-[11px]" aria-hidden="true"></i>
        </span>
        <input
            id="{{ $searchId }}"
            type="search"
            wire:model.live.debounce.250ms="{{ $searchModel }}"
            placeholder="{{ __('app.search') }}"
            autocomplete="off"
            class="h-10 w-full rounded-xl border-0 bg-transparent pl-9 pr-12 text-sm text-rt-text outline-none ring-0 placeholder:text-rt-soft focus:border-0 focus:ring-0 dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft"
        >
        <span
            class="pointer-events-none absolute inset-y-0 right-0 flex min-w-10 items-center justify-center px-2 text-[10px] font-bold tabular-nums text-rt-muted dark:text-rt-dark-muted"
            aria-label="{{ $pickerPaginator->total() }} {{ __('app.group_members') }}"
        >
            {{ $pickerPaginator->total() }}
        </span>
    </div>

    <div class="mt-2 space-y-1" wire:loading.class="opacity-60" wire:target="{{ $searchModel }},previousPage,nextPage">
        @forelse ($pickerPaginator as $member)
            @php
                $isSelected = in_array((int) $member->id, $selectedIds, true);
                $memberInputId = $rowPrefix . '-' . $member->id;
            @endphp

            <div
                wire:key="{{ $rowPrefix }}-row-{{ $member->id }}"
                class="rt-chat-member-row flex min-h-14 min-w-0 items-center gap-2 rounded-xl px-2 py-1.5"
            >
                @if ($pickerMode === 'select' && $selectionModel)
                    <x-ui.forms.checkbox
                        :id="$memberInputId"
                        value="{{ $member->id }}"
                        wire:model.live="{{ $selectionModel }}"
                        aria-label="{{ __('app.select_participants') }}: {{ $member->name }}"
                        class="shrink-0"
                    />
                @endif

                <x-user.person-anchor-preview
                    :user="$member"
                    :can-chat="$member->isActive()"
                    chat-action="startDirect"
                    trigger-classes="flex w-full"
                    class="min-w-0 flex-1"
                >
                    <x-slot:trigger>
                        <button
                            type="button"
                            class="group block w-full min-w-0 rounded-lg px-1 py-0.5 text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.open_person_preview') }}: {{ $member->name }}"
                            title="{{ __('app.open_person_preview') }}"
                            data-no-chat-swipe
                        >
                            <x-user.public-info
                                :user="$member"
                                :size="9"
                                :show-email="true"
                                :show-presence="false"
                                :selected="$isSelected"
                            />
                        </button>
                    </x-slot:trigger>
                </x-user.person-anchor-preview>

                @if ($pickerMode === 'direct')
                    <x-chat.icon-button
                        icon="far fa-arrow-right"
                        :label="__('app.new_chat') . ': ' . $member->name"
                        size="sm"
                        wire:click="startDirect({{ $member->id }})"
                        data-no-chat-swipe
                    />
                @endif
            </div>
        @empty
            <div class="flex min-h-24 flex-col items-center justify-center px-4 py-5 text-center">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg text-rt-muted dark:text-rt-dark-muted">
                    <i class="far fa-user-search text-sm" aria-hidden="true"></i>
                </span>
                <p class="mt-1.5 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.no_entries_found') }}
                </p>
            </div>
        @endforelse
    </div>

    <footer class="mt-2 flex min-h-9 items-center justify-between gap-3 border-t border-rt-border/70 pt-2 dark:border-rt-dark-border/70">
        <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">
            <i class="far fa-users text-[9px]" aria-hidden="true"></i>
            {{ $pickerPaginator->firstItem() ?? 0 }}–{{ $pickerPaginator->lastItem() ?? 0 }}
            / {{ $pickerPaginator->total() }}
        </span>

        @if ($pickerPaginator->lastPage() > 1)
            <nav class="flex items-center gap-1" aria-label="{{ __('Pagination Navigation') }}">
                <button
                    type="button"
                    wire:click="previousPage('{{ $pageName }}')"
                    wire:loading.attr="disabled"
                    @disabled($pickerPaginator->onFirstPage())
                    class="rt-chat-member-page-button"
                    aria-label="{{ __('app.previous') }}"
                >
                    <i class="far fa-chevron-left" aria-hidden="true"></i>
                </button>
                <span class="min-w-11 text-center text-[10px] font-bold tabular-nums text-rt-soft dark:text-rt-dark-soft">
                    {{ $pickerPaginator->currentPage() }} / {{ $pickerPaginator->lastPage() }}
                </span>
                <button
                    type="button"
                    wire:click="nextPage('{{ $pageName }}')"
                    wire:loading.attr="disabled"
                    @disabled(! $pickerPaginator->hasMorePages())
                    class="rt-chat-member-page-button"
                    aria-label="{{ __('app.next') }}"
                >
                    <i class="far fa-chevron-right" aria-hidden="true"></i>
                </button>
            </nav>
        @endif
    </footer>
</section>
