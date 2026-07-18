<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-rt-muted dark:text-rt-dark-muted">
            {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
        </div>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
            @csrf

            <div>
                <x-ui.forms.label for="password" value="{{ __('Password') }}" />
                <x-ui.forms.input id="password" class="mt-1 block" type="password" name="password" required autocomplete="current-password" autofocus />
            </div>

            <div class="flex justify-end">
                <x-button class="ms-4">
                    {{ __('Confirm') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
