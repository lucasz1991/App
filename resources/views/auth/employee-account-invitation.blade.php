<x-guest-layout>
    <x-auth-brand-layout
        title="Mitarbeiterzugang einrichten"
        description="Legen Sie Ihr Passwort fest. Damit bestätigen Sie Ihre E-Mail-Adresse und werden anschließend direkt angemeldet."
    >
        <x-validation-errors class="mt-4 mb-4" />

        <form method="POST" action="{{ $request->fullUrl() }}" class="mt-6 space-y-5">
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
            <x-ui.buttons.button-basic mode="primary" type="submit" class="w-full justify-center">Zugang einrichten</x-ui.buttons.button-basic>
        </form>
    </x-auth-brand-layout>
</x-guest-layout>
