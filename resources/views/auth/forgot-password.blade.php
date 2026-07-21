<x-guest-layout>
    <x-auth-brand-layout :title="__('app.forgot_password_title')" :description="__('app.forgot_password_description')">
        <x-validation-errors class="mt-4 mb-4" />

        @if (session('status'))
            <div class="mt-4 mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <x-ui.forms.label for="email" :value="__('app.email')" />
                <x-ui.forms.input id="email" class="mt-1 block" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <x-button class="w-full justify-center">
                {{ __('app.send_reset_link') }}
            </x-button>
        </form>

        <div class="mt-6 text-center">
            <a href="{{ route('login') }}" class="text-sm text-rt-muted underline transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent">
                {{ __('app.back_to_login') }}
            </a>
        </div>
    </x-auth-brand-layout>
</x-guest-layout>
