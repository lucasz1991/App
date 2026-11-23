<x-guest-layout>
    <x-auth-brand-layout :title="__('Reset Password')">
        <x-validation-errors class="mt-4 mb-4" />

        <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-5">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="block">
                <x-ui.forms.label for="email" value="{{ __('Email') }}" />
                <x-ui.forms.input id="email" class="mt-1 block" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            </div>

            <div>
                <x-ui.forms.label for="password" value="{{ __('Password') }}" />
                <x-ui.forms.input id="password" class="mt-1 block" type="password" name="password" required autocomplete="new-password" />
            </div>

            <div>
                <x-ui.forms.label for="password_confirmation" value="{{ __('Confirm Password') }}" />
                <x-ui.forms.input id="password_confirmation" class="mt-1 block" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>

            <div class="flex items-center justify-end">
                <x-ui.buttons.button-basic mode="primary" type="submit">
                    {{ __('Reset Password') }}
                </x-ui.buttons.button-basic>
            </div>
        </form>
    </x-auth-brand-layout>
</x-guest-layout>
