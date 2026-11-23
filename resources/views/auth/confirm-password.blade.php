<x-guest-layout>
    <x-auth-brand-layout
        :title="__('app.confirm_password')"
        :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
    >
        <x-validation-errors class="mt-4 mb-4" />

        <form method="POST" action="{{ route('password.confirm') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <x-ui.forms.label for="password" value="{{ __('Password') }}" />
                <x-ui.forms.input id="password" class="mt-1 block" type="password" name="password" required autocomplete="current-password" autofocus />
            </div>

            <div class="flex justify-end">
                <x-ui.buttons.button-basic mode="primary" type="submit" class="ms-4">
                    {{ __('Confirm') }}
                </x-ui.buttons.button-basic>
            </div>
        </form>
    </x-auth-brand-layout>
</x-guest-layout>
