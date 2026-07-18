<x-app-layout>
<x-authentication-card>
        

        <div class="mb-4 text-sm text-rt-muted dark:text-rt-dark-muted">
            {{ __('Bevor du fortfährst, könntest du bitte deine E-Mail-Adresse bestätigen, indem du auf den Link klickst, den wir dir gerade geschickt haben? Falls du die E-Mail nicht erhalten hast, senden wir dir gerne eine neue.') }}
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                {{ __('Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die du in deinen Profileinstellungen angegeben hast.') }}
            </div>
        @endif

        <div class="mt-4 flex items-center justify-between">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf

                <div>
                    <x-button type="submit">
                        {{ __('Bestätigungs-E-Mail erneut senden') }}
                    </x-button>
                </div>
            </form>

            <div>
                <a
                    href="{{ route('profile.show') }}"
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
    </x-authentication-card>
</x-app-layout>
