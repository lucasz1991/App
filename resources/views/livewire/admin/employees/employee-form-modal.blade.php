<x-dialog-modal wire:model="showModal" maxWidth="4xl">
    <x-slot name="title">
        {{ $userId ? __('app.edit_employee') : __('app.create_employee') }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-5">
            <div class="text-sm text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.employee_form_intro') }}
            </div>

            <x-ui.accordion.tabs
                :tabs="[
                    'teamSecurity' => ['label' => __('app.team_and_security'), 'icon' => 'fad fa-shield-check'],
                    'personalData' => ['label' => __('app.personal_data'), 'icon' => 'fad fa-address-card'],
                ]"
                default="teamSecurity"
                persist-key="employee-form.tabs"
            >
                <x-ui.accordion.tab-panel for="teamSecurity" panel-class="space-y-4">
                    <div class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <div class="mb-3 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.basic_data') }}</div>

                        <div class="grid gap-4 md:grid-cols-2">
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

                    <div class="space-y-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <x-ui.forms.label :value="__('app.team')"/>
                                <x-ui.forms.select wire:model="primary_team_id" :placeholder="__('app.please_select')">
                                    @foreach($teams as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                                    @endforeach
                                </x-ui.forms.select>
                                <x-ui.forms.input-error for="primary_team_id"/>
                            </div>

                            <div>
                                <x-ui.forms.label :value="__('app.position')"/>
                                <x-ui.forms.input type="text" wire:model="position" :placeholder="__('app.position_placeholder')" autocomplete="organization-title"/>
                                <x-ui.forms.input-error for="position"/>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
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
                </x-ui.accordion.tab-panel>

                <x-ui.accordion.tab-panel for="personalData" panel-class="space-y-4">
                    <div class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <x-ui.forms.label :value="__('app.personnel_nr')"/>
                                <x-ui.forms.input type="text" wire:model="personnel_nr"/>
                                <x-ui.forms.input-error for="personnel_nr"/>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.birth_date')"/>
                                <x-ui.forms.input type="date" wire:model="birth_date" autocomplete="bday"/>
                                <x-ui.forms.input-error for="birth_date"/>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.phone')"/>
                                <x-ui.forms.input type="text" wire:model="phone" autocomplete="tel"/>
                                <x-ui.forms.input-error for="phone"/>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.mobile')"/>
                                <x-ui.forms.input type="text" wire:model="mobile" autocomplete="tel"/>
                                <x-ui.forms.input-error for="mobile"/>
                            </div>
                            <div class="md:col-span-2">
                                <x-ui.forms.label :value="__('app.street')"/>
                                <x-ui.forms.input type="text" wire:model="street" autocomplete="street-address"/>
                                <x-ui.forms.input-error for="street"/>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.postal_code')"/>
                                <x-ui.forms.input type="text" wire:model="postal_code" autocomplete="postal-code"/>
                                <x-ui.forms.input-error for="postal_code"/>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.city')"/>
                                <x-ui.forms.input type="text" wire:model="city"/>
                                <x-ui.forms.input-error for="city"/>
                            </div>
                            <div class="md:col-span-2">
                                <x-ui.forms.label :value="__('app.country')"/>
                                <x-ui.forms.input type="text" wire:model="country" autocomplete="country-name"/>
                                <x-ui.forms.input-error for="country"/>
                            </div>
                        </div>
                    </div>
                </x-ui.accordion.tab-panel>
            </x-ui.accordion.tabs>
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
