<x-confirmation-modal id="rt-delete-chat-message-modal" wire:model="showDeleteMessageModal" maxWidth="sm">
    <x-slot name="title">
        {{ __('app.delete_message_title') }}
    </x-slot>

    <x-slot name="content">
        {{ __('app.delete_message_hint') }}
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic
            type="button"
            mode="basic"
            wire:click="$set('showDeleteMessageModal', false)"
        >
            {{ __('app.cancel') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic
            type="button"
            mode="danger"
            wire:click="confirmDeleteMessage"
            wire:loading.attr="disabled"
            wire:target="confirmDeleteMessage"
        >
            {{ __('app.delete') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-confirmation-modal>

<x-confirmation-modal id="rt-delete-chat-modal" wire:model="showDeleteChatModal" maxWidth="sm">
    @php
        $deleteTargetChat = $pendingDeleteChat ?? $selectedChat;
    @endphp

    <x-slot name="title">
        @if ($deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me))
            {{ __('app.leave_group') }}
        @elseif ($deleteTargetChat?->isGroup())
            {{ __('app.delete_group') }}
        @else
            {{ __('app.delete_chat') }}
        @endif
    </x-slot>

    <x-slot name="content">
        @if ($deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me))
            {{ __('app.leave_group_hint') }}
        @elseif ($deleteTargetChat?->isGroup())
            {{ __('app.delete_group_hint') }}
        @else
            {{ __('app.delete_direct_chat_hint') }}
        @endif
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic
            type="button"
            mode="basic"
            wire:click="$set('showDeleteChatModal', false)"
        >
            {{ __('app.cancel') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic
            type="button"
            mode="danger"
            wire:click="confirmDeleteChat"
            wire:loading.attr="disabled"
            wire:target="confirmDeleteChat"
        >
            @if ($deleteTargetChat?->isGroup() && ! $deleteTargetChat->canManageGroup($me))
                {{ __('app.leave_group') }}
            @else
                {{ __('app.delete') }}
            @endif
        </x-ui.buttons.button-basic>
    </x-slot>
</x-confirmation-modal>

<div class="rt-chat-group-modal">
    <x-dialog-modal id="rt-group-settings-modal" wire:model="showGroupSettings" maxWidth="lg">
        <x-slot name="title">
            <span class="rt-chat-modal-title flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-rt-accent dark:text-rt-dark-accent">
                    <i class="fad fa-users-cog" aria-hidden="true"></i>
                </span>
                <span>
                    <span class="block text-[10px] font-bold uppercase tracking-[0.13em] text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.manage_group') }}
                    </span>
                    <span class="mt-0.5 block text-lg font-extrabold tracking-[-0.035em]">
                        {{ __('app.group_settings') }}
                    </span>
                </span>
            </span>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-5">
                <div>
                    <x-ui.forms.label for="group-edit-name" :value="__('app.group_name')" />
                    <x-ui.forms.input
                        type="text"
                        id="group-edit-name"
                        wire:model="groupEditName"
                        autocomplete="off"
                        class="mt-1.5"
                    />
                    @error('groupEditName')
                        <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <div class="flex items-end justify-between gap-3">
                        <x-ui.forms.label :value="__('app.group_members')" />
                        <span class="text-[10px] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.invite_or_remove_members') }}
                        </span>
                    </div>

                    <div class="rt-chat-group-owner mt-2 flex min-h-14 items-center gap-3 rounded-xl px-3 py-2.5">
                        <x-chat.avatar :src="$me->profile_photo_url" :name="$me->name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ $me->name }}</span>
                            <span class="block text-[10px] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.group_owner') }}
                            </span>
                        </span>
                        <i class="far fa-lock text-xs text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                    </div>

                    <div class="rt-chat-participant-list mt-2 max-h-64 space-y-1 overflow-y-auto rounded-xl p-2">
                        @foreach ($groupCandidates as $candidate)
                            <div wire:key="group-settings-member-{{ $candidate->id }}" class="rt-chat-member-choice rounded-lg px-2">
                                <x-ui.forms.checkbox
                                    :id="'group-settings-member-' . $candidate->id"
                                    :label="$candidate->name"
                                    value="{{ $candidate->id }}"
                                    wire:model="groupMemberIds"
                                />
                            </div>
                        @endforeach
                    </div>
                    @error('groupMemberIds')
                        <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                    @enderror
                    @error('groupMemberIds.*')
                        <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.buttons.button-basic
                    type="button"
                    mode="basic"
                    wire:click="$set('showGroupSettings', false)"
                >
                    {{ __('app.cancel') }}
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic
                    type="button"
                    mode="primary"
                    wire:click="saveGroupSettings"
                    wire:loading.attr="disabled"
                    wire:target="saveGroupSettings"
                >
                    {{ __('app.save_group_changes') }}
                </x-ui.buttons.button-basic>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
