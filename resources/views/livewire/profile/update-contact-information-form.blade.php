<x-form-section submit="save">
    <x-slot name="title">
        {{ __('app.contact_information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.contact_information_description') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6 grid w-full grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Funktion / Position -->
            <div class="md:col-span-2">
                <x-ui.forms.label for="position" value="{{ __('app.position') }}" />
                <x-ui.forms.input id="position" type="text" class="mt-1 block" wire:model="position" placeholder="{{ __('app.position_placeholder') }}" autocomplete="organization-title" />
                <x-input-error for="position" class="mt-2" />
            </div>

            <!-- Telefon -->
            <div>
                <x-ui.forms.label for="phone" value="{{ __('app.phone') }}" />
                <x-ui.forms.input id="phone" type="text" class="mt-1 block" wire:model="phone" autocomplete="tel" />
                <x-input-error for="phone" class="mt-2" />
            </div>

            <!-- Mobil -->
            <div>
                <x-ui.forms.label for="mobile" value="{{ __('app.mobile') }}" />
                <x-ui.forms.input id="mobile" type="text" class="mt-1 block" wire:model="mobile" autocomplete="tel" />
                <x-input-error for="mobile" class="mt-2" />
            </div>

            <!-- Straße -->
            <div>
                <x-ui.forms.label for="street" value="{{ __('app.street') }}" />
                <x-ui.forms.input id="street" type="text" class="mt-1 block" wire:model="street" autocomplete="street-address" />
                <x-input-error for="street" class="mt-2" />
            </div>

            <!-- Postleitzahl -->
            <div>
                <x-ui.forms.label for="postal_code" value="{{ __('app.postal_code') }}" />
                <x-ui.forms.input id="postal_code" type="text" class="mt-1 block" wire:model="postal_code" autocomplete="postal-code" />
                <x-input-error for="postal_code" class="mt-2" />
            </div>

            <!-- Stadt -->
            <div>
                <x-ui.forms.label for="city" value="{{ __('app.city') }}" />
                <x-ui.forms.input id="city" type="text" class="mt-1 block" wire:model="city" />
                <x-input-error for="city" class="mt-2" />
            </div>

            <!-- Land -->
            <div>
                <x-ui.forms.label for="country" value="{{ __('app.country') }}" />
                <x-ui.forms.input id="country" type="text" class="mt-1 block" wire:model="country" />
                <x-input-error for="country" class="mt-2" />
            </div>

            <!-- Geburtsdatum -->
            <div>
                <x-ui.forms.label for="birth_date" value="{{ __('app.birth_date') }}" />
                <x-ui.forms.input id="birth_date" type="date" class="mt-1 block" wire:model="birth_date" autocomplete="bday" />
                <x-input-error for="birth_date" class="mt-2" />
            </div>
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('app.saved') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled">
            {{ __('app.save') }}
        </x-button>
    </x-slot>
</x-form-section>
