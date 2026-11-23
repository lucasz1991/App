<x-guest-layout>
    <x-auth-brand-layout :title="__('app.two_factor_authentication')">
        <div class="mt-4" x-data="{ recovery: false }">
            <div class="mb-4 text-sm text-rt-muted dark:text-rt-dark-muted" x-show="! recovery">
                {{ __('Please confirm access to your account by entering the authentication code provided by your authenticator application.') }}
            </div>

            <div class="mb-4 text-sm text-rt-muted dark:text-rt-dark-muted" x-cloak x-show="recovery">
                {{ __('Please confirm access to your account by entering one of your emergency recovery codes.') }}
            </div>

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('two-factor.login') }}">
                @csrf

                <div class="mt-4" x-show="! recovery">
                    <x-ui.forms.label for="code" value="{{ __('Code') }}" />
                    <x-ui.forms.input id="code" class="mt-1 block" type="text" inputmode="numeric" name="code" autofocus x-ref="code" autocomplete="one-time-code" />
                </div>

                <div class="mt-4" x-cloak x-show="recovery">
                    <x-ui.forms.label for="recovery_code" value="{{ __('Recovery Code') }}" />
                    <x-ui.forms.input id="recovery_code" class="mt-1 block" type="text" name="recovery_code" x-ref="recovery_code" autocomplete="one-time-code" />
                </div>

                <div class="flex items-center justify-end mt-4">
                    <button type="button" class="text-sm text-rt-muted underline transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent cursor-pointer"
                                    x-show="! recovery"
                                    x-on:click="
                                        recovery = true;
                                        $nextTick(() => { $refs.recovery_code.focus() })
                                    ">
                        {{ __('Use a recovery code') }}
                    </button>

                    <button type="button" class="text-sm text-rt-muted underline transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent cursor-pointer"
                                    x-cloak
                                    x-show="recovery"
                                    x-on:click="
                                        recovery = false;
                                        $nextTick(() => { $refs.code.focus() })
                                    ">
                        {{ __('Use an authentication code') }}
                    </button>

                    <x-ui.buttons.button-basic mode="primary" type="submit" class="ms-4">
                        {{ __('Log in') }}
                    </x-ui.buttons.button-basic>
                </div>
            </form>
        </div>
    </x-auth-brand-layout>
</x-guest-layout>
