<x-app-layout>
<x-authentication-card>
        

        <div class="mb-4 text-sm text-gray-600 dark:text-slate-400">
            {{ __('Bevor du fortfährst, könntest du bitte deine E-Mail-Adresse bestätigen, indem du auf den Link klickst, den wir dir gerade geschickt haben? Falls du die E-Mail nicht erhalten hast, senden wir dir gerne eine neue.') }}
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
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
                    class="underline text-sm text-gray-600 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    {{ __('Profil bearbeiten') }}</a>

                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf

                    <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 ms-2">
                        {{ __('Abmelden') }}
                    </button>
                </form>
            </div>
        </div>
    </x-authentication-card>
</x-app-layout>
