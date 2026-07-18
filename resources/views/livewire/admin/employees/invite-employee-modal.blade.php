<x-dialog-modal wire:model="showModal" maxWidth="md">
    <x-slot name="title">
        {{ __('app.invite_employee') }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">
            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.invite_employee_hint') }}
            </p>

            <div class="space-y-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <div>
                    <x-ui.forms.label for="invite-email" :value="__('app.email')" />
                    <x-ui.forms.input id="invite-email" class="mt-1 block" type="email" wire:model="email" />
                    <x-input-error for="email" class="mt-1" />
                </div>

                <div>
                    <x-ui.forms.label for="invite-role" :value="__('app.role')" />
                    <x-ui.forms.select id="invite-role" wire:model="role" class="mt-1 block">
                        <option value="staff">{{ __('app.role_staff') }}</option>
                        @if (auth()->user()->isAdmin())
                            <option value="admin">{{ __('app.role_admin') }}</option>
                        @endif
                    </x-ui.forms.select>
                    <x-input-error for="role" class="mt-1" />
                </div>
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
