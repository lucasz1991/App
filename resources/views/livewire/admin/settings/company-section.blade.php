{{-- Einstellungen > Firmendaten. Wurzel ist die bisherige Abschnittskarte. --}}
<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
    data-anim="fade-up"
    data-autosave-scope
>
    <x-ui.autosave-status
        event="company-settings-saved"
        target="saveCompany"
        dirty-target="company.name,company.email,company.street,company.postal_code,company.city,company.country,company.phone,company.emergency_phone,company.whatsapp_phone,company.website,company.managing_directors,company.register_court,company.commercial_register_number,company.vat_id,company.tax_number"
    />
    <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
    <div class="flex min-w-0 items-start gap-3 sm:gap-4">
        <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent sm:flex">
            <i class="fad fa-building fa-lg" aria-hidden="true"></i>
        </span>
        <div class="min-w-0 flex-1">
            <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                {{ __('app.company_data') }}
            </h2>
            <p class="mt-1 max-w-3xl break-words text-sm text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.company_data_hint') }}
            </p>
        </div>
    </div>

    <form wire:submit="saveCompany" class="mt-6">
        <div class="grid min-w-0 gap-x-5 gap-y-4 sm:grid-cols-2">
                    @foreach ([
                        ['name', 'company_name', 'text', 'organization'],
                        ['email', 'company_email', 'email', 'email'],
                        ['street', 'street', 'text', 'street-address'],
                        ['postal_code', 'postal_code', 'text', 'postal-code'],
                        ['city', 'city', 'text', 'address-level2'],
                        ['country', 'country', 'text', 'country-name'],
                        ['phone', 'company_phone', 'tel', 'tel'],
                        ['emergency_phone', 'emergency_phone', 'tel', 'tel'],
                        ['whatsapp_phone', 'company_whatsapp_phone', 'tel', 'tel'],
                        ['website', 'website', 'url', 'url'],
                        ['managing_directors', 'managing_directors', 'text', 'off'],
                        ['register_court', 'register_court', 'text', 'off'],
                        ['commercial_register_number', 'commercial_register_number', 'text', 'off'],
                        ['vat_id', 'vat_id', 'text', 'off'],
                        ['tax_number', 'tax_number', 'text', 'off'],
                    ] as [$field, $label, $type, $autocomplete])
                        <div class="min-w-0 {{ in_array($field, ['name', 'street', 'managing_directors'], true) ? 'sm:col-span-2' : '' }}">
                            <label for="company_{{ $field }}" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                                {{ __('app.'.$label) }}
                            </label>
                            <x-ui.forms.input
                                id="company_{{ $field }}"
                                type="{{ $type }}"
                                autocomplete="{{ $autocomplete }}"
                                :aria-describedby="$field === 'whatsapp_phone' ? 'company_whatsapp_phone_hint' : null"
                                wire:model="company.{{ $field }}"
                                class="mt-1.5"
                            />
                            @if ($field === 'whatsapp_phone')
                                <p id="company_whatsapp_phone_hint" class="mt-1.5 text-sm text-rt-muted dark:text-rt-dark-muted">
                                    {{ __('app.company_whatsapp_hint') }}
                                </p>
                            @endif
                            @error('company.'.$field)
                                <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
        </div>
    </form>
    </div>
</section>
