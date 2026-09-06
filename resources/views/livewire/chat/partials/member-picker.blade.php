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
    class="rt-chat-member-picker rt-chat-stacked-surface min-w-0"
    data-chat-member-picker="{{ $rowPrefix }}"
    aria-label="{{ __('app.select_participants') }}"
>
    <x-ui.forms.search
        :id="$searchId"
        context="picker"
        wire:model.live.debounce.250ms="{{ $searchModel }}"
        :placeholder="__('app.search')"
        :status="$pickerPaginator->total()"
        :status-label="$pickerPaginator->total().' '.__('app.group_members')"
    />

    <div class="rt-chat-member-list mt-2 space-y-1" wire:loading.class="opacity-60" wire:target="{{ $searchModel }},previousPage,nextPage">
        @forelse ($pickerPaginator as $member)
            @php
                $isSelected = in_array((int) $member->id, $selectedIds, true);
                $memberInputId = $rowPrefix . '-' . $member->id;
            @endphp

            <div
                wire:key="{{ $rowPrefix }}-row-{{ $member->id }}"
                class="rt-chat-member-row {{ $isSelected ? 'is-selected' : '' }} flex min-h-14 min-w-0 items-center gap-2 rounded-xl px-2 py-1.5"
            >
                @php
                    $viewer = auth()->user();
                    $canReach = $member->isActive() && ! $member->is($viewer);
                    $canCallMember = $canReach
                        && ($viewer->isAdmin() || $viewer->hasRbacPermission('calls.start'))
                        && ($member->isAdmin() || $member->hasRbacPermission('calls.join'));
                @endphp

                <x-user.person-anchor-preview
                    :user="$member"
                    :profile-url="$viewer->canViewManagementDashboard()
                        ? route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $member->id)
                        : null"
                    :can-chat="$canReach"
                    :can-call="$canCallMember"
                    :can-message="$canReach && $viewer->can('users.messages.create')"
                    chat-action="startDirect"
                    call-action="startDirectCall"
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
                                :show-presence="true"
                                :show-context="true"
                                :selected="$isSelected"
                            />
                        </button>
                    </x-slot:trigger>
                </x-user.person-anchor-preview>

                @if ($pickerMode === 'select' && $selectionModel)
                    <span class="rt-chat-member-select shrink-0">
                        <x-ui.forms.checkbox
                            :id="$memberInputId"
                            value="{{ $member->id }}"
                            wire:model.live="{{ $selectionModel }}"
                            aria-label="{{ __('app.select_participants') }}: {{ $member->name }}"
                            class="shrink-0"
                        />
                    </span>
                @endif

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
