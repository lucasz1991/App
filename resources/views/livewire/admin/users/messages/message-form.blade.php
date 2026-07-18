<div
    x-data="{ showModal: @entangle('showMailModal') }"
    x-init="$watch('showModal', value => { if (!value) { $wire.handleModalClosed(); } })"
>
    <x-dialog-modal wire:model="showMailModal">
        <x-slot name="title">
            {{ __('app.compose_message') }}

            @if ($mailUserId)
                <span class="mt-1 block text-sm text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.to_recipient', ['recipient' => App\Models\User::find($mailUserId)?->email ?? __('app.user_not_found')]) }}
                </span>
            @elseif (! empty($directRecipients))
                <span class="mt-1 block text-sm text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.to_recipient', ['recipient' => implode(', ', $directRecipients)]) }}
                </span>
            @else
                <span class="mt-1 block text-sm text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.send_to_x_users', ['count' => count($selectedUsers)]) }}
                </span>
            @endif
        </x-slot>

        <x-slot name="content">
            <div class="mb-4 rounded-lg border-l-4 border-yellow-500 bg-yellow-50 p-4 text-yellow-800 dark:border-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-300" role="alert">
                <p class="font-bold">{{ __('app.important_notice') }}</p>
                <p>{{ __('app.compose_check_hint') }}</p>
            </div>

            @if ($forceMailOnly)
                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                    {{ __('app.free_mail_only_hint') }}
                </div>
            @else
                <div class="mt-4 flex items-center rounded-xl bg-rt-surface-muted px-4 py-3 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                    <x-ui.forms.checkbox toggle wire:model="mailWithMail" :label="__('app.also_send_as_email')" />
                </div>
            @endif

            <div class="mt-4 space-y-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <div>
                    <x-ui.forms.label for="mailSubject" :value="__('app.subject')" />
                    <x-ui.forms.input id="mailSubject" type="text" wire:model="mailSubject" class="mt-1 block" />
                    <x-input-error for="mailSubject" class="mt-2" />
                </div>

                <div>
                    <x-ui.forms.label for="mailHeader" :value="__('app.heading')" />
                    <x-ui.forms.input id="mailHeader" type="text" wire:model="mailHeader" class="mt-1 block" />
                    <x-input-error for="mailHeader" class="mt-2" />
                </div>

                <div>
                    <x-ui.forms.label for="mailBody" :value="__('app.message')" />
                    <textarea id="mailBody" rows="6" wire:model="mailBody"
                              class="mt-1 block w-full rounded-lg border-rt-border bg-rt-surface text-sm shadow-rt-xs transition-all duration-300 ease-rt-spring focus:border-rt-red focus:ring focus:ring-rt-red/30 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text"></textarea>
                    <x-input-error for="mailBody" class="mt-2" />
                </div>

                <div>
                    <x-ui.forms.label for="mailLink" :value="__('app.link_optional')" />
                    <x-ui.forms.input id="mailLink" type="url" wire:model="mailLink" class="mt-1 block" />
                    <x-input-error for="mailLink" class="mt-2" />
                </div>
            </div>

            <div class="mt-4">
                <x-ui.filepool.drop-zone :model="'fileUploads'" />
                <x-input-error for="fileUploads.*" class="mt-2" />
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="resetMailModal" wire:loading.attr="disabled">
                {{ __('app.cancel') }}
            </x-secondary-button>

            @php $canSendMail = auth()->user()?->isAdmin(); @endphp

            @if ($canSendMail)
                <x-button wire:click="sendMail" wire:loading.attr="disabled" class="ml-2">
                    {{ __('app.send') }}
                </x-button>
            @else
                <x-button disabled class="pointer-events-none ml-2 cursor-not-allowed opacity-60" title="{{ __('app.no_permission') }}">
                    {{ __('app.send') }}
                </x-button>
            @endif
        </x-slot>
    </x-dialog-modal>
</div>
