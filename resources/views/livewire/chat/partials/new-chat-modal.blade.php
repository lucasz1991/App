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
                    type="button"
                    role="tab"
                    aria-selected="{{ $newChatTab === 'direct' ? 'true' : 'false' }}"
                    wire:click="$set('newChatTab', 'direct')"
                    class="rt-chat-modal-tab {{ $newChatTab === 'direct' ? 'is-active' : '' }} min-h-10 rounded-lg px-3 py-2 text-xs font-bold"
                >
                    <i class="far fa-user mr-1.5" aria-hidden="true"></i>
                    {{ __('app.new_chat') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $newChatTab === 'group' ? 'true' : 'false' }}"
                    wire:click="$set('newChatTab', 'group')"
                    class="rt-chat-modal-tab {{ $newChatTab === 'group' ? 'is-active' : '' }} min-h-10 rounded-lg px-3 py-2 text-xs font-bold"
                >
                    <i class="far fa-users mr-1.5" aria-hidden="true"></i>
                    {{ __('app.new_group') }}
                </button>
            </div>

            @if ($newChatTab === 'direct')
                <div class="rt-chat-contact-list max-h-80 space-y-1 overflow-y-auto pr-1" role="tabpanel">
                    @forelse ($contacts as $contact)
                        <button
                            type="button"
                            wire:key="contact-{{ $contact->id }}"
                            wire:click="startDirect({{ $contact->id }})"
                            class="rt-chat-contact group flex min-h-14 w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left"
                        >
                            <x-chat.avatar
                                :src="$contact->profile_photo_url"
                                :name="$contact->name"
                                size="sm"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">{{ $contact->name }}</span>
                                <span class="mt-0.5 block truncate text-[10px] text-rt-muted dark:text-rt-dark-muted">{{ $contact->email }}</span>
                            </span>
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-rt-muted dark:text-rt-dark-muted">
                                <i class="far fa-arrow-right text-[10px]" aria-hidden="true"></i>
                            </span>
                        </button>
                    @empty
                        <x-chat.empty-state
                            compact
                            :title="__('app.chats')"
                            :description="__('app.no_chats_yet')"
                        />
                    @endforelse
                </div>
            @else
                <div class="space-y-4" role="tabpanel">
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
                        <div class="rt-chat-participant-list mt-1.5 max-h-56 space-y-1 overflow-y-auto rounded-xl p-2">
                            @foreach ($contacts as $contact)
                                <div wire:key="gp-row-{{ $contact->id }}" class="rounded-lg px-1.5 py-1">
                                    <x-ui.forms.checkbox
                                        :id="'gp-' . $contact->id"
                                        :label="$contact->name"
                                        value="{{ $contact->id }}"
                                        wire:model="groupParticipants"
                                    />
                                </div>
                            @endforeach
                        </div>
                        @error('groupParticipants')
                            <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
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
