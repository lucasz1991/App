<x-dialog-modal wire:model="showModal" maxWidth="md">
    <x-slot name="title">
        {{ __('app.invite_employee') }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-slate-400">
                {{ __('app.invite_employee_hint') }}
            </p>

            <div>
                <x-label for="invite-email" :value="__('app.email')" />
                <x-input id="invite-email" class="mt-1 block w-full" type="email" wire:model="email" />
                <x-input-error for="email" class="mt-1" />
            </div>

            <div>
                <x-label for="invite-role" :value="__('app.role')" />
                <select id="invite-role" wire:model="role"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                    <option value="staff">{{ __('app.role_staff') }}</option>
                    @if (auth()->user()->isAdmin())
                        <option value="admin">{{ __('app.role_admin') }}</option>
                    @endif
                </select>
                <x-input-error for="role" class="mt-1" />
            </div>
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic wire:click="close" class="mr-2">
            {{ __('app.close') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled">
            <i class="far fa-paper-plane mr-2"></i>
            {{ __('app.send_invitation') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
