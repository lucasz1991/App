<x-guest-layout>
    <x-auth-brand-layout
        :title="__('Verify Email Address')"
        :description="__('Bevor Sie fortfahren, bestätigen Sie bitte Ihre E-Mail-Adresse über den Link, den wir Ihnen gerade geschickt haben. Falls Sie die E-Mail nicht erhalten haben, senden wir Ihnen gerne eine neue.')"
    >
        @if (session('status') == 'verification-link-sent')
            <div role="status" class="mt-4 mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                {{ __('Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die Sie in Ihren Profileinstellungen angegeben haben.') }}
            </div>
        @endif

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf

                <div>
                    <x-ui.buttons.button-basic mode="primary" type="submit">
                        {{ __('Bestätigungs-E-Mail erneut senden') }}
                    </x-ui.buttons.button-basic>
                </div>
            </form>

            <div>
                <a
                    href="{{ route('profile.show') }}"
                    wire:navigate
                    class="underline text-sm text-rt-muted transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent rounded-md focus:outline-none focus:ring-2 focus:ring-rt-red/40"
                >
                    {{ __('Profil bearbeiten') }}</a>

                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf

                    <button type="submit" class="underline text-sm text-rt-muted transition-colors duration-300 hover:text-rt-red dark:text-rt-dark-muted dark:hover:text-rt-dark-accent rounded-md focus:outline-none focus:ring-2 focus:ring-rt-red/40 ms-2">
                        {{ __('Abmelden') }}
                    </button>
                </form>
            </div>
        </div>
    </x-auth-brand-layout>
</x-guest-layout>
