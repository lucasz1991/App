<x-customer-portal-auth-layout title="Passwort zurücksetzen" kicker="Zugang wiederherstellen">
    <form method="POST" action="/kundenportal/passwort" class="rt-customer-portal__auth-form">
        @csrf
        <x-ui.forms.input id="portal-reset-email" type="email" name="email" label="E-Mail" autocomplete="email" required autofocus />
        <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
            <span class="rt-customer-portal__button-label">Link anfordern</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
        </x-ui.buttons.button-basic>
    </form>
    <x-slot:footer><a class="rt-customer-portal__auth-back" href="/kundenportal/anmelden"><x-customer-portal.icon name="chevron-left" />Zur Anmeldung</a></x-slot:footer>
</x-customer-portal-auth-layout>
