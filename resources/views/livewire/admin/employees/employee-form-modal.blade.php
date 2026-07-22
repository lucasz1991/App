<x-dialog-modal wire:model="showModal" maxWidth="4xl">
    <x-slot name="title">{{ $userId ? __('app.edit_employee') : __('app.create_employee') }}</x-slot>

    <x-slot name="content">
        @php
            $formTabs = [
                'teamSecurity' => ['label' => __('app.team_and_security'), 'icon' => 'fad fa-shield-check'],
                'personalData' => ['label' => __('app.personal_data'), 'icon' => 'fad fa-address-card'],
            ];
            if ($canViewMasterData) {
                $formTabs['masterData'] = ['label' => __('app.employment_data'), 'icon' => 'fad fa-briefcase'];
            }
            if ($canViewCompensation) {
                $formTabs['compensation'] = ['label' => __('app.compensation_data'), 'icon' => 'fad fa-coins'];
            }
        @endphp

        <div class="space-y-5">
            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.employee_form_intro') }}</p>

            <x-ui.accordion.tabs :tabs="$formTabs" default="teamSecurity" persist-key="employee-form.tabs">
                <x-ui.accordion.tab-panel for="teamSecurity" panel-class="space-y-4">
                    <div class="grid gap-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 md:grid-cols-2">
                        <div>
                            <x-ui.forms.label :value="__('app.display_name')" />
                            <x-ui.forms.input type="text" wire:model="name" />
                            <x-ui.forms.input-error for="name" />
                        </div>
                        <div>
                            <x-ui.forms.label :value="__('app.email')" />
                            <x-ui.forms.input type="email" wire:model="email" />
                            <x-ui.forms.input-error for="email" />
                        </div>
                        <div>
                            <x-ui.forms.label :value="__('app.team')" />
                            <x-ui.forms.select wire:model="primary_team_id" :placeholder="__('app.please_select')">
                                @foreach ($teams as $team)
                                    <option value="{{ $team->id }}">{{ $team->name }}</option>
                                @endforeach
                            </x-ui.forms.select>
                            <x-ui.forms.input-error for="primary_team_id" />
                        </div>
                        @if ($canViewMasterData)
                            <div>
                                <x-ui.forms.label :value="__('app.position')" />
                                <x-ui.forms.input type="text" wire:model="position" :disabled="!$canEditMasterData" autocomplete="organization-title" />
                                <x-ui.forms.input-error for="position" />
                            </div>
                        @endif
                        <div>
                            <x-ui.forms.label :value="$userId ? __('app.new_password_optional') : __('app.password')" />
                            <x-ui.forms.input type="password" wire:model="password" autocomplete="new-password" />
                            <x-ui.forms.input-error for="password" />
                        </div>
                        <div>
                            <x-ui.forms.label :value="__('app.confirm_password')" />
                            <x-ui.forms.input type="password" wire:model="password_confirmation" autocomplete="new-password" />
                        </div>
                    </div>
                </x-ui.accordion.tab-panel>

                <x-ui.accordion.tab-panel for="personalData" panel-class="space-y-4">
                    <div class="grid gap-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 md:grid-cols-2">
                        @foreach ([
                            ['first_name', 'first_name', 'text', 'given-name'],
                            ['last_name', 'last_name', 'text', 'family-name'],
                            ['birth_date', 'birth_date', 'date', 'bday'],
                            ['birth_place', 'birth_place', 'text', 'off'],
                            ['birth_name', 'birth_name', 'text', 'off'],
                            ['nationality', 'nationality', 'text', 'country-name'],
                            ['education', 'school_education', 'text', 'off'],
                            ['phone', 'phone', 'text', 'tel'],
                            ['mobile', 'mobile', 'text', 'tel'],
                            ['street', 'street', 'text', 'street-address'],
                            ['postal_code', 'postal_code', 'text', 'postal-code'],
                            ['city', 'city', 'text', 'address-level2'],
                            ['country', 'country', 'text', 'country-name'],
                        ] as [$field, $label, $type, $autocomplete])
                            <div @class(['md:col-span-2' => in_array($field, ['education', 'street'], true)])>
                                <x-ui.forms.label :value="__('app.'.$label)" />
                                <x-ui.forms.input :type="$type" wire:model="{{ $field }}" :autocomplete="$autocomplete" />
                                <x-ui.forms.input-error :for="$field" />
                            </div>
                        @endforeach
                    </div>
                </x-ui.accordion.tab-panel>

                @if ($canViewMasterData)
                    <x-ui.accordion.tab-panel for="masterData" panel-class="space-y-4">
                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                            <i class="far fa-lock mr-2"></i>{{ $canEditMasterData ? __('app.encrypted_master_data_hint') : __('app.read_only_permission_hint') }}
                        </div>
                        <div class="grid gap-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 md:grid-cols-2">
                            <div>
                                <x-ui.forms.label :value="__('app.personnel_nr')" />
                                <x-ui.forms.input type="text" wire:model="personnel_nr" :disabled="!$canEditMasterData" />
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.entry_date')" />
                                <x-ui.forms.input type="date" wire:model="entry_date" :disabled="!$canEditMasterData" />
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.multiple_employment')" />
                                <x-ui.forms.select wire:model="multiple_employment" :disabled="!$canEditMasterData">
                                    <option value="">{{ __('app.please_select') }}</option>
                                    <option value="0">{{ __('app.no') }}</option>
                                    <option value="1">{{ __('app.yes') }}</option>
                                </x-ui.forms.select>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.employment_type')" />
                                <x-ui.forms.select wire:model="employment_type" :disabled="!$canEditMasterData">
                                    <option value="">{{ __('app.please_select') }}</option>
                                    <option value="employee">{{ __('app.salaried_employee') }}</option>
                                    <option value="worker">{{ __('app.worker') }}</option>
                                </x-ui.forms.select>
                            </div>
                            <div>
                                <x-ui.forms.label :value="__('app.weekly_working_hours')" />
                                <x-ui.forms.input type="number" step="0.25" min="0" max="168" wire:model="weekly_working_hours" :disabled="!$canEditMasterData" />
                            </div>
                            <div class="md:col-span-2">
                                <x-ui.forms.label :value="__('app.additional_information')" />
                                <textarea wire:model="additional_information" @disabled(!$canEditMasterData) rows="4" class="mt-1 block w-full rounded-lg border-rt-border bg-rt-control text-sm text-rt-text shadow-rt-xs focus:border-rt-red focus:ring-rt-red dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text"></textarea>
                            </div>
                        </div>
                    </x-ui.accordion.tab-panel>
                @endif

                @if ($canViewCompensation)
                    <x-ui.accordion.tab-panel for="compensation" panel-class="space-y-4">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                            <i class="far fa-user-shield mr-2"></i>{{ __('app.compensation_confidential_hint') }}
                        </div>
                        <div class="grid gap-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 md:grid-cols-2">
                            @foreach ([
                                ['tax_identification_number', 'tax_identification_number', 'text'],
                                ['social_security_number', 'social_security_number', 'text'],
                                ['iban', 'iban', 'text'],
                                ['health_insurance', 'health_insurance', 'text'],
                                ['tax_class', 'tax_class', 'text'],
                                ['children_count', 'children_count', 'number'],
                                ['religion', 'religion', 'text'],
                                ['compensation_amount', 'compensation_amount', 'number'],
                            ] as [$field, $label, $type])
                                <div>
                                    <x-ui.forms.label :value="__('app.'.$label)" />
                                    <x-ui.forms.input :type="$type" wire:model="{{ $field }}" :disabled="!$canEditCompensation" @if($field === 'compensation_amount') step="0.01" min="0" @endif />
                                    <x-ui.forms.input-error :for="$field" />
                                </div>
                            @endforeach
                            <div class="md:col-span-2">
                                <x-ui.forms.label :value="__('app.compensation_type')" />
                                <x-ui.forms.select wire:model="compensation_type" :disabled="!$canEditCompensation">
                                    <option value="">{{ __('app.please_select') }}</option>
                                    <option value="salary">{{ __('app.salary') }}</option>
                                    <option value="fixed_salary">{{ __('app.fixed_salary') }}</option>
                                    <option value="hourly_wage">{{ __('app.hourly_wage') }}</option>
                                </x-ui.forms.select>
                            </div>
                        </div>
                    </x-ui.accordion.tab-panel>
                @endif
            </x-ui.accordion.tabs>
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic wire:click="close" class="mr-2" size="sm"><i class="far fa-times"></i>{{ __('app.close') }}</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled" size="sm">
            <i class="fal fa-save" wire:loading.remove wire:target="save"></i>
            <i class="fal fa-spinner fa-spin" wire:loading wire:target="save"></i>
            {{ __('app.save') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
