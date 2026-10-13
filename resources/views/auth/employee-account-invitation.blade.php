<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo"><x-authentication-card-logo /></x-slot>

        <h1 class="text-xl font-semibold">Mitarbeiterzugang einrichten</h1>
        <p class="mt-2 mb-4 text-sm">Legen Sie Ihr Passwort fest. Damit bestätigen Sie Ihre E-Mail-Adresse und werden anschließend direkt angemeldet.</p>
        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ $request->fullUrl() }}" class="space-y-5">
            @csrf
            <div>
                <x-ui.forms.label for="email" value="E-Mail-Adresse" />
                <x-ui.forms.input id="email" class="mt-1 block" type="email" :value="$email" readonly autocomplete="username" />
            </div>
            <div>
                <x-ui.forms.label for="password" value="Passwort" />
                <x-ui.forms.input id="password" class="mt-1 block" type="password" name="password" required autofocus autocomplete="new-password" />
            </div>
            <div>
                <x-ui.forms.label for="password_confirmation" value="Passwort bestätigen" />
                <x-ui.forms.input id="password_confirmation" class="mt-1 block" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>
            <x-button type="submit">Zugang einrichten</x-button>
        </form>
    </x-authentication-card>
</x-guest-layout>
