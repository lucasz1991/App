<x-dialog-modal wire:model="showModal" maxWidth="2xl">
    <x-slot name="title">
        {{ $userId ? __('app.edit_employee') : __('app.create_employee') }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">
            <div class="text-sm text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.employee_form_intro') }}
            </div>

            <div class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <div class="text-sm font-semibold text-rt-text mb-3 dark:text-rt-dark-text">{{ __('app.basic_data') }}</div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.forms.label :value="__('app.name')"/>
                        <x-ui.forms.input type="text" wire:model="name"/>
                        <x-ui.forms.input-error for="name"/>
                    </div>

                    <div>
                        <x-ui.forms.label :value="__('app.email')"/>
                        <x-ui.forms.input type="email" wire:model="email"/>
                        <x-ui.forms.input-error for="email"/>
                    </div>
                </div>
            </div>

            <div class="rounded-xl bg-rt-surface-muted p-4 space-y-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <div class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.team_and_security') }}</div>

                <div class="space-y-1">
                    <x-ui.forms.label :value="__('app.team')"/>
                    <x-ui.forms.select wire:model="primary_team_id" :placeholder="__('app.please_select')">
                        @foreach($teams as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </x-ui.forms.select>
                    <x-ui.forms.input-error for="primary_team_id"/>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.forms.label :value="$userId ? __('app.new_password_optional') : __('app.password')"/>
                        <x-ui.forms.input type="password" wire:model="password" autocomplete="new-password"/>
                        <x-ui.forms.input-error for="password"/>
                    </div>
                    <div>
                        <x-ui.forms.label :value="__('app.confirm_password')"/>
                        <x-ui.forms.input type="password" wire:model="password_confirmation" autocomplete="new-password"/>
                    </div>
                </div>
            </div>
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic wire:click="close" class="mr-2" size="sm">
            <i class="far fa-times mr-2"></i>
            {{ __('app.close') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled" size="sm">
            <i class="fal fa-save mr-2" wire:loading.remove wire:target="save"></i>
            <i class="fal fa-spinner fa-spin mr-2 text-rt-red" wire:loading wire:target="save"></i>
            {{ __('app.save') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
