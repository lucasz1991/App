<div class="rt-chat-new-modal">
    <x-dialog-modal wire:model="showNewChat" maxWidth="md">
        <x-slot name="title">
            <span class="rt-chat-modal-title flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-rt-accent dark:text-rt-dark-accent">
                    <i class="fad fa-comment-plus" aria-hidden="true"></i>
                </span>
                <span>
                    <span class="block text-[10px] font-bold uppercase tracking-[0.13em] text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.chat_and_messages') }}
                    </span>
                    <span class="mt-0.5 block text-lg font-extrabold tracking-[-0.035em]">
                        {{ $newChatTab === 'group' ? __('app.new_group') : __('app.new_chat') }}
                    </span>
                </span>
            </span>
        </x-slot>

        <x-slot name="content">
            <div
                class="rt-chat-modal-tabs mb-5 grid grid-cols-2 gap-1 rounded-xl p-1"
                role="tablist"
                aria-label="{{ __('app.chat_and_messages') }}"
            >
                <button
                    id="rt-new-chat-tab-direct"
                    type="button"
                    role="tab"
                    aria-selected="{{ $newChatTab === 'direct' ? 'true' : 'false' }}"
                    aria-controls="rt-new-chat-panel-direct"
                    tabindex="{{ $newChatTab === 'direct' ? '0' : '-1' }}"
                    wire:click="$set('newChatTab', 'direct')"
                    class="rt-chat-modal-tab {{ $newChatTab === 'direct' ? 'is-active' : '' }} min-h-10 rounded-lg px-3 py-2 text-xs font-bold"
                >
                    <i class="far fa-user mr-1.5" aria-hidden="true"></i>
                    {{ __('app.new_chat') }}
                </button>
                <button
                    id="rt-new-chat-tab-group"
                    type="button"
                    role="tab"
                    aria-selected="{{ $newChatTab === 'group' ? 'true' : 'false' }}"
                    aria-controls="rt-new-chat-panel-group"
                    tabindex="{{ $newChatTab === 'group' ? '0' : '-1' }}"
                    wire:click="$set('newChatTab', 'group')"
                    class="rt-chat-modal-tab {{ $newChatTab === 'group' ? 'is-active' : '' }} min-h-10 rounded-lg px-3 py-2 text-xs font-bold"
                >
                    <i class="far fa-users mr-1.5" aria-hidden="true"></i>
                    {{ __('app.new_group') }}
                </button>
            </div>

            @if ($showNewChat)
                @if ($newChatTab === 'direct')
                    <div
                        id="rt-new-chat-panel-direct"
                        role="tabpanel"
                        aria-labelledby="rt-new-chat-tab-direct"
                    >
                        @include('livewire.chat.partials.member-picker', [
                            'pickerPaginator' => $contacts,
                            'pickerMode' => 'direct',
                            'searchId' => 'direct-contact-search',
                            'searchModel' => 'directContactSearch',
                            'pageName' => 'directContactsPage',
                            'rowPrefix' => 'direct-contact',
                        ])
                    </div>
                @else
                    <div
                        id="rt-new-chat-panel-group"
                        class="space-y-4"
                        role="tabpanel"
                        aria-labelledby="rt-new-chat-tab-group"
                    >
                        <div>
                            <x-ui.forms.label for="group-name" :value="__('app.group_name')" />
                            <x-ui.forms.input
                                type="text"
                                id="group-name"
                                wire:model="groupName"
                                autocomplete="off"
                                class="mt-1.5 rounded-xl"
                            />
                            @error('groupName')
                                <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <x-ui.forms.label :value="__('app.select_participants')" />
                            <div class="mt-1.5">
                                @include('livewire.chat.partials.member-picker', [
                                    'pickerPaginator' => $groupParticipantsPaginator,
                                    'pickerMode' => 'select',
                                    'selectionModel' => 'groupParticipants',
                                    'selectedIds' => $groupParticipants,
                                    'searchId' => 'group-participant-search',
                                    'searchModel' => 'groupParticipantSearch',
                                    'pageName' => 'groupParticipantsPage',
                                    'rowPrefix' => 'group-participant',
                                ])
                            </div>
                            @error('groupParticipants')
                                <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif
            @endif
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.buttons.button-basic type="button" mode="basic" wire:click="$toggle('showNewChat')">
                    {{ __('app.cancel') }}
                </x-ui.buttons.button-basic>
                @if ($newChatTab === 'group')
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="createGroup">
                        {{ __('app.save') }}
                    </x-ui.buttons.button-basic>
                @endif
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
