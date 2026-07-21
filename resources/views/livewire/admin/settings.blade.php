<x-ui.page
    :title="__('app.settings')"
    :eyebrow="__('app.administration')"
    :description="__('app.settings_page_description')"
>
    <x-ui.accordion.tabs
        :tabs="[
            'general' => ['label' => __('app.general'), 'icon' => 'fad fa-sliders-h'],
            'company' => ['label' => __('app.company_data'), 'icon' => 'fad fa-building'],
            'users' => ['label' => __('app.users'), 'icon' => 'fad fa-users'],
            'system' => ['label' => __('app.system'), 'icon' => 'fad fa-server'],
        ]"
        default="general"
        persist-key="admin-settings.tabs"
    >
    <x-ui.accordion.tab-panel for="system" panel-class="space-y-6">
    {{-- Wartungsmodus-Warnbanner --}}
    @if ($maintenanceMode)
        <div
            class="flex items-start gap-3 rounded-xl border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-800 shadow-rt-sm ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25"
            data-anim="fade-up"
        >
            <i class="fad fa-triangle-exclamation mt-0.5 fa-lg" aria-hidden="true"></i>
            <span>{{ __('app.maintenance_mode_active_banner') }}</span>
        </div>
    @endif

    {{-- System / Wartungsmodus --}}
    <section
        class="overflow-hidden rounded-2xl bg-rt-surface-muted p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-anim="fade-up"
    >
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-6 dark:bg-rt-dark-surface">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                    <i class="fad fa-sliders fa-lg" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                        {{ __('app.system') }}
                    </h2>
                    <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.settings_overview_system_text') }}
                    </p>

                    <div class="mt-5 flex flex-col gap-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                {{ __('app.maintenance_mode') }}
                            </p>
                            <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.maintenance_mode_hint') }}
                            </p>
                        </div>
                        <x-ui.forms.toggle-button model="maintenanceMode" />
                    </div>

                    <div class="mt-5">
                        <x-ui.buttons.button-basic mode="primary" wire:click="saveSystem" can="settings.manage">
                            <i class="fad fa-save" aria-hidden="true"></i>
                            {{ __('app.save') }}
                        </x-ui.buttons.button-basic>
                    </div>
                </div>
            </div>
        </div>
    </section>
    </x-ui.accordion.tab-panel>

    {{-- Einladungen --}}
    <x-ui.accordion.tab-panel for="users" panel-class="space-y-6">
    <section
        class="rounded-2xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
        data-anim="fade-up"
        data-anim-delay="0.05"
    >
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                <i class="fad fa-user-plus fa-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ __('app.invitations') }}
                </h2>

                <div class="mt-4 max-w-sm">
                    <label for="invitation_expiry_days" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                        {{ __('app.invitation_expiry_days') }}
                    </label>
                    <div class="mt-1.5">
                        <x-ui.forms.input
                            type="number"
                            id="invitation_expiry_days"
                            min="1"
                            max="365"
                            wire:model="invitationExpiryDays"
                        />
                    </div>
                    @error('invitationExpiryDays')
                        <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.invitation_expiry_hint') }}
                    </p>
                </div>

                <div class="mt-5">
                    <x-ui.buttons.button-basic mode="primary" wire:click="saveInvitations" can="settings.manage">
                        <i class="fad fa-save" aria-hidden="true"></i>
                        {{ __('app.save') }}
                    </x-ui.buttons.button-basic>
                </div>
            </div>
        </div>
    </section>
    </x-ui.accordion.tab-panel>

    {{-- E-Mails --}}
    <x-ui.accordion.tab-panel for="general" panel-class="space-y-6">
    <section
        class="rounded-2xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
        data-anim="fade-up"
        data-anim-delay="0.1"
    >
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                <i class="fad fa-envelope fa-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ __('app.settings_mails') }}
                </h2>
                <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.settings_overview_mails_text') }}
                </p>

                <div class="mt-4 max-w-md">
                    <label for="admin_email" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                        {{ __('app.admin_email_address') }}
                    </label>
                    <div class="mt-1.5">
                        <x-ui.forms.input
                            type="email"
                            id="admin_email"
                            placeholder="admin@rail-time.de"
                            wire:model="adminEmail"
                        />
                    </div>
                    @error('adminEmail')
                        <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.admin_email_hint') }}
                    </p>
                </div>

                <div class="mt-5">
                    <x-ui.buttons.button-basic mode="primary" wire:click="saveMails" can="settings.manage">
                        <i class="fad fa-save" aria-hidden="true"></i>
                        {{ __('app.save') }}
                    </x-ui.buttons.button-basic>
                </div>
            </div>
        </div>
    </section>
    </x-ui.accordion.tab-panel>

    <x-ui.accordion.tab-panel for="company" panel-class="space-y-6">
        <section
            class="rounded-2xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
            data-anim="fade-up"
        >
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                    <i class="fad fa-building fa-lg" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                        {{ __('app.company_data') }}
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.company_data_hint') }}
                    </p>

                    <form wire:submit="saveCompany" class="mt-6">
                        <div class="grid gap-x-5 gap-y-4 md:grid-cols-2">
                            @foreach ([
                                ['name', 'company_name', 'text', 'organization'],
                                ['email', 'company_email', 'email', 'email'],
                                ['street', 'street', 'text', 'street-address'],
                                ['postal_code', 'postal_code', 'text', 'postal-code'],
                                ['city', 'city', 'text', 'address-level2'],
                                ['country', 'country', 'text', 'country-name'],
                                ['phone', 'company_phone', 'tel', 'tel'],
                                ['emergency_phone', 'emergency_phone', 'tel', 'tel'],
                                ['website', 'website', 'url', 'url'],
                                ['managing_directors', 'managing_directors', 'text', 'off'],
                                ['register_court', 'register_court', 'text', 'off'],
                                ['commercial_register_number', 'commercial_register_number', 'text', 'off'],
                                ['vat_id', 'vat_id', 'text', 'off'],
                                ['tax_number', 'tax_number', 'text', 'off'],
                            ] as [$field, $label, $type, $autocomplete])
                                <div class="{{ in_array($field, ['name', 'street', 'managing_directors'], true) ? 'md:col-span-2' : '' }}">
                                    <label for="company_{{ $field }}" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                                        {{ __('app.'.$label) }}
                                    </label>
                                    <x-ui.forms.input
                                        id="company_{{ $field }}"
                                        type="{{ $type }}"
                                        autocomplete="{{ $autocomplete }}"
                                        wire:model="company.{{ $field }}"
                                        class="mt-1.5"
                                    />
                                    @error('company.'.$field)
                                        <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 flex items-center justify-end border-t border-rt-border/70 pt-5 dark:border-rt-dark-border/70">
                            <x-ui.buttons.button-basic type="submit" mode="primary" can="settings.manage">
                                <i class="fad fa-save" aria-hidden="true"></i>
                                <span>{{ __('app.save_company_data') }}</span>
                            </x-ui.buttons.button-basic>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </x-ui.accordion.tab-panel>
    </x-ui.accordion.tabs>
</x-ui.page>
