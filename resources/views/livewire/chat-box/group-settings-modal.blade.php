{{--
    Chat: Gruppenverwaltung (Bild, Name, Mitglieder) als Dialog.
    Erwartet: $showGroupSettings, $groupCandidates, $groupMemberIds, $groupPhotoUpload,
    $groupEditName, $selectedChat, $me.
--}}
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
            @if ($showGroupSettings && $groupCandidates)
                <div class="space-y-5">
                    {{-- Gruppenbild: Vorschau (neues Bild > aktuelles Bild > Initiale)
                         plus Dateiauswahl. Gespeichert wird erst mit "Speichern". --}}
                    <div class="flex items-center gap-4">
                        @php
                            $currentGroupPhoto = $selectedChat?->avatarUrlFor($me);
                        @endphp
                        <span class="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-rt-surface-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            @if ($groupPhotoUpload)
                                <img src="{{ $groupPhotoUpload->temporaryUrl() }}" alt="" class="h-full w-full object-cover" />
                            @elseif ($currentGroupPhoto)
                                <img src="{{ $currentGroupPhoto }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <span class="text-xl font-extrabold text-rt-muted dark:text-rt-dark-muted">
                                    {{ mb_substr(trim($groupEditName) !== '' ? $groupEditName : 'G', 0, 1) }}
                                </span>
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <x-ui.forms.label for="group-photo-upload" :value="__('app.group_photo')" />
                            <label
                                for="group-photo-upload"
                                class="mt-1.5 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-rt-border bg-rt-control px-3 py-1.5 text-xs font-semibold text-rt-text shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                            >
                                <i class="fad fa-camera" aria-hidden="true"></i>
                                {{ __('app.group_photo_choose') }}
                            </label>
                            <input
                                type="file"
                                id="group-photo-upload"
                                accept="image/*"
                                wire:model="groupPhotoUpload"
                                class="sr-only"
                            />
                            <p class="mt-1 text-[10px] leading-4 text-rt-muted dark:text-rt-dark-muted" wire:loading.remove wire:target="groupPhotoUpload">
                                {{ __('app.group_photo_hint') }}
                            </p>
                            <p class="mt-1 text-[10px] font-semibold text-rt-accent dark:text-rt-dark-accent" wire:loading wire:target="groupPhotoUpload">
                                {{ __('app.uploading') }}…
                            </p>
                            @error('groupPhotoUpload')
                                <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

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

                        <div class="rt-chat-group-owner mt-2 flex min-h-14 min-w-0 items-center gap-2 rounded-xl px-2.5 py-2">
                            <x-user.public-info
                                :user="$me"
                                :size="9"
                                :show-email="true"
                                :show-presence="false"
                                class="min-w-0 flex-1"
                            />
                            <span
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-rt-muted dark:text-rt-dark-muted"
                                title="{{ __('app.group_owner') }}"
                                aria-label="{{ __('app.group_owner') }}"
                            >
                                <i class="far fa-lock text-xs" aria-hidden="true"></i>
                            </span>
                            <span class="sr-only">
                                {{ __('app.group_owner') }}
                            </span>
                        </div>

                        <div class="mt-2">
                            @include('livewire.chat.partials.member-picker', [
                                'pickerPaginator' => $groupCandidates,
                                'pickerMode' => 'select',
                                'selectionModel' => 'groupMemberIds',
                                'selectedIds' => $groupMemberIds,
                                'searchId' => 'group-member-search',
                                'searchModel' => 'groupMemberSearch',
                                'pageName' => 'groupMembersPage',
                                'rowPrefix' => 'group-settings-member',
                            ])
                        </div>
                        @error('groupMemberIds')
                            <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                        @enderror
                        @error('groupMemberIds.*')
                            <p class="rt-chat-field-error mt-1.5 text-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endif
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.buttons.button-basic
                    type="button"
                    mode="secondary"
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
