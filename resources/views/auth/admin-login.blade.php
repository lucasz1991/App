<x-guest-layout>
    <x-auth-brand-layout :title="__('app.admin_login_title')" :description="__('app.admin_login_description')">
        <x-validation-errors class="mt-4 mb-4" />

        @if (session('status'))
            <div class="mt-4 mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
            @csrf
            <input type="hidden" name="admin_login" value="1">

            <div>
                <x-ui.forms.label for="email" :value="__('app.email')" />
                <x-ui.forms.input id="email" class="mt-1 block" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div>
                <x-ui.forms.label for="password" :value="__('app.password')" />
                <x-ui.forms.input id="password" class="mt-1 block" type="password" name="password" required autocomplete="current-password" />
            </div>

            <div class="flex items-center justify-between">
                <x-ui.forms.checkbox id="remember" name="remember" :label="__('app.remember_me')" />
                <a href="{{ route('password.request') }}" class="text-sm text-rt-muted underline transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent">
                    {{ __('app.forgot_password') }}
                </a>
            </div>

            <x-button class="w-full justify-center">
                {{ __('app.admin_login_button') }}
            </x-button>
        </form>
    </x-auth-brand-layout>
</x-guest-layout>
