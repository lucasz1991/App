<x-guest-layout>
    <x-auth-brand-layout variant="premium-login">
        <x-validation-errors class="mt-4 mb-4" />

        @if (session('status'))
            <div role="status" class="rt-login-feedback mt-4 mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                {{ session('status') }}
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('login') }}"
            class="rt-login-form"
            data-premium-login-form
        >
            @csrf

            <x-ui.forms.input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                :label="__('app.email')"
                required
                autofocus
                autocomplete="username"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                aria-describedby="email-error"
            />
            @error('email')
                <span id="email-error" class="sr-only">{{ $message }}</span>
            @enderror

            <x-ui.forms.input
                id="password"
                type="password"
                name="password"
                :label="__('app.password')"
                required
                autocomplete="current-password"
                aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                aria-describedby="password-error"
            />
            @error('password')
                <span id="password-error" class="sr-only">{{ $message }}</span>
            @enderror

            <div>
                <div class="rt-login-options">
                    <x-ui.forms.toggle-button
                        id="remember"
                        name="remember"
                        :label="__('app.remember_me')"
                    />
                    <a href="{{ route('password.request') }}" wire:navigate class="rt-login-forgot">
                        {{ __('app.forgot_password') }}
                    </a>
                </div>
            </div>

            <button type="submit" class="rt-login-submit">
                <span>{{ __('app.login_button') }}</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                </svg>
            </button>
        </form>

    </x-auth-brand-layout>
</x-guest-layout>
