<x-form-section submit="save">
    <x-slot name="title">
        {{ __('app.contact_information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.contact_information_description') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6 grid w-full grid-cols-1 gap-6 md:grid-cols-2">
            <!-- Telefon -->
            <div>
                <x-label for="phone" value="{{ __('app.phone') }}" />
                <x-input id="phone" type="text" class="mt-1 block w-full" wire:model="phone" autocomplete="tel" />
                <x-input-error for="phone" class="mt-2" />
            </div>

            <!-- Mobil -->
            <div>
                <x-label for="mobile" value="{{ __('app.mobile') }}" />
                <x-input id="mobile" type="text" class="mt-1 block w-full" wire:model="mobile" autocomplete="tel" />
                <x-input-error for="mobile" class="mt-2" />
            </div>

            <!-- Straße -->
            <div>
                <x-label for="street" value="{{ __('app.street') }}" />
                <x-input id="street" type="text" class="mt-1 block w-full" wire:model="street" autocomplete="street-address" />
                <x-input-error for="street" class="mt-2" />
            </div>

            <!-- Postleitzahl -->
            <div>
                <x-label for="postal_code" value="{{ __('app.postal_code') }}" />
                <x-input id="postal_code" type="text" class="mt-1 block w-full" wire:model="postal_code" autocomplete="postal-code" />
                <x-input-error for="postal_code" class="mt-2" />
            </div>

            <!-- Stadt -->
            <div>
                <x-label for="city" value="{{ __('app.city') }}" />
                <x-input id="city" type="text" class="mt-1 block w-full" wire:model="city" />
                <x-input-error for="city" class="mt-2" />
            </div>

            <!-- Land -->
            <div>
                <x-label for="country" value="{{ __('app.country') }}" />
                <x-input id="country" type="text" class="mt-1 block w-full" wire:model="country" />
                <x-input-error for="country" class="mt-2" />
            </div>

            <!-- Geburtsdatum -->
            <div>
                <x-label for="birth_date" value="{{ __('app.birth_date') }}" />
                <x-input id="birth_date" type="date" class="mt-1 block w-full" wire:model="birth_date" autocomplete="bday" />
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
