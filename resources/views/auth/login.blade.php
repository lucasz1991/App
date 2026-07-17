<x-guest-layout>
    <x-auth-brand-layout :title="__('app.login_title')" :description="__('app.login_description')">
        <x-validation-errors class="mt-4 mb-4" />

        @if (session('status'))
            <div class="mt-4 mb-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <x-label for="email" :value="__('app.email')" />
                <x-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div>
                <x-label for="password" :value="__('app.password')" />
                <x-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
            </div>

            <div class="flex items-center justify-between">
                <label for="remember" class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <x-checkbox id="remember" name="remember" />
                    {{ __('app.remember_me') }}
                </label>
                <a href="{{ route('password.request') }}" class="text-sm text-slate-600 underline hover:text-slate-900">
                    {{ __('app.forgot_password') }}
                </a>
            </div>

            <x-button class="w-full justify-center">
                {{ __('app.login_button') }}
            </x-button>
        </form>

    </x-auth-brand-layout>
</x-guest-layout>
