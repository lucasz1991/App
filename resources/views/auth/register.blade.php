<x-guest-layout>
    <x-auth-brand-layout :title="__('app.register_title')" :description="__('app.invited_registration_description')">
        <x-validation-errors class="mt-4 mb-4" />

        <form method="POST" action="{{ route('invitation.register.store', $invitation->token) }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <x-ui.forms.label for="email" :value="__('app.email')" />
                <x-ui.forms.input id="email" class="mt-1 block" type="email" :value="$invitation->email" readonly />
            </div>

            <div>
                <x-ui.forms.label for="name" :value="__('app.name')" />
                <x-ui.forms.input id="name" class="mt-1 block" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            </div>

            <div>
                <x-ui.forms.label for="password" :value="__('app.password')" />
                <x-ui.forms.input id="password" class="mt-1 block" type="password" name="password" required autocomplete="new-password" />
            </div>

            <div>
                <x-ui.forms.label for="password_confirmation" :value="__('app.confirm_password')" />
                <x-ui.forms.input id="password_confirmation" class="mt-1 block" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>

            <x-button class="w-full justify-center">
                {{ __('app.register_button') }}
            </x-button>
        </form>

        <p class="mt-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">
            {{ __('app.already_registered') }}
            <a href="{{ route('login') }}" class="font-medium text-rt-text underline transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-text dark:hover:text-rt-dark-accent">
                {{ __('app.to_login') }}
            </a>
        </p>
    </x-auth-brand-layout>
</x-guest-layout>
