<x-form-section submit="save">
    <x-slot name="title">{{ __('app.contact_information') }}</x-slot>
    <x-slot name="description">{{ __('app.contact_information_description') }}</x-slot>

    <x-slot name="form">
        <div class="col-span-6 grid w-full grid-cols-1 gap-6 md:grid-cols-2">
            <div class="md:col-span-2 rounded-xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-700">
                <span class="font-semibold">{{ __('app.position') }}:</span>
                {{ auth()->user()->profile?->position ?: __('app.not_set') }}
                <span class="mt-1 block text-xs opacity-70">{{ __('app.position_managed_by_administration') }}</span>
            </div>

            @foreach ([
                ['first_name', 'first_name', 'text', 'given-name'],
                ['last_name', 'last_name', 'text', 'family-name'],
                ['phone', 'phone', 'text', 'tel'],
                ['mobile', 'mobile', 'text', 'tel'],
                ['street', 'street', 'text', 'street-address'],
                ['postal_code', 'postal_code', 'text', 'postal-code'],
                ['city', 'city', 'text', 'address-level2'],
                ['country', 'country', 'text', 'country-name'],
                ['birth_date', 'birth_date', 'date', 'bday'],
                ['birth_place', 'birth_place', 'text', 'off'],
                ['birth_name', 'birth_name', 'text', 'off'],
                ['nationality', 'nationality', 'text', 'country-name'],
                ['education', 'school_education', 'text', 'off'],
            ] as [$field, $label, $type, $autocomplete])
                <div @class(['md:col-span-2' => in_array($field, ['street', 'education'], true)])>
                    <x-ui.forms.label :for="$field" :value="__('app.'.$label)" />
                    <x-ui.forms.input :id="$field" :type="$type" class="mt-1 block" wire:model="{{ $field }}" :autocomplete="$autocomplete" />
                    <x-input-error :for="$field" class="mt-2" />
                </div>
            @endforeach
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">{{ __('app.saved') }}</x-action-message>
        <x-button wire:loading.attr="disabled">{{ __('app.save') }}</x-button>
    </x-slot>
</x-form-section>
