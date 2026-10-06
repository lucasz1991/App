<x-customer-portal-auth-layout title="Neues Passwort" kicker="Zugang wiederherstellen">
    <form method="POST" action="/kundenportal/passwort/{{ $token }}" class="rt-customer-portal__auth-form">
        @csrf
        <x-ui.forms.input id="portal-reset-password" name="password" type="password" label="Passwort" minlength="12" maxlength="128" autocomplete="new-password" required />
        <x-ui.forms.input id="portal-reset-confirmation" name="password_confirmation" type="password" label="Passwort bestätigen" autocomplete="new-password" required />
        <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
            <span class="rt-customer-portal__button-label">Speichern</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
        </x-ui.buttons.button-basic>
    </form>
    <x-slot:footer><a class="rt-customer-portal__auth-back" href="/kundenportal/anmelden"><x-customer-portal.icon name="chevron-left" />Zur Anmeldung</a></x-slot:footer>
</x-customer-portal-auth-layout>
