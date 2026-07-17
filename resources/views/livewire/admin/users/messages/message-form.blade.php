<div
    x-data="{ showModal: @entangle('showMailModal') }"
    x-init="$watch('showModal', value => { if (!value) { $wire.handleModalClosed(); } })"
>
    <x-dialog-modal wire:model="showMailModal">
        <x-slot name="title">
            {{ __('app.compose_message') }}

            @if ($mailUserId)
                <span class="mt-1 block text-sm text-gray-500 dark:text-slate-400">
                    {{ __('app.to_recipient', ['recipient' => App\Models\User::find($mailUserId)?->email ?? __('app.user_not_found')]) }}
                </span>
            @elseif (! empty($directRecipients))
                <span class="mt-1 block text-sm text-gray-500 dark:text-slate-400">
                    {{ __('app.to_recipient', ['recipient' => implode(', ', $directRecipients)]) }}
                </span>
            @else
                <span class="mt-1 block text-sm text-gray-500 dark:text-slate-400">
                    {{ __('app.send_to_x_users', ['count' => count($selectedUsers)]) }}
                </span>
            @endif
        </x-slot>

        <x-slot name="content">
            <div class="mb-4 border-l-4 border-yellow-500 bg-yellow-100 p-4 text-yellow-700 dark:border-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-300" role="alert">
                <p class="font-bold">{{ __('app.important_notice') }}</p>
                <p>{{ __('app.compose_check_hint') }}</p>
            </div>

            @if ($forceMailOnly)
                <div class="mb-4 rounded border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                    {{ __('app.free_mail_only_hint') }}
                </div>
            @else
                <div class="mt-4">
                    <label class="inline-flex cursor-pointer items-center">
                        <input type="checkbox" value="" wire:model="mailWithMail" class="peer sr-only">
                        <div class="relative h-6 w-11 rounded-full bg-gray-200 after:absolute after:start-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-blue-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rtl:peer-checked:after:-translate-x-full dark:border-gray-600 dark:bg-gray-700 dark:peer-checked:bg-blue-600 dark:peer-focus:ring-blue-800"></div>
                        <span class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ __('app.also_send_as_email') }}</span>
                    </label>
                </div>
            @endif

            <div class="mt-4">
                <x-label for="mailSubject" :value="__('app.subject')" />
                <x-input id="mailSubject" type="text" wire:model="mailSubject" class="mt-1 block w-full" />
                <x-input-error for="mailSubject" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-label for="mailHeader" :value="__('app.heading')" />
                <x-input id="mailHeader" type="text" wire:model="mailHeader" class="mt-1 block w-full" />
                <x-input-error for="mailHeader" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-label for="mailBody" :value="__('app.message')" />
                <textarea id="mailBody" rows="6" wire:model="mailBody"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200"></textarea>
                <x-input-error for="mailBody" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-label for="mailLink" :value="__('app.link_optional')" />
                <x-input id="mailLink" type="url" wire:model="mailLink" class="mt-1 block w-full" />
                <x-input-error for="mailLink" class="mt-2" />
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
