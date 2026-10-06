<x-customer-portal-auth-layout title="Anmelden" kicker="Kundenportal">
    <form method="POST" action="/kundenportal/anmelden" class="rt-customer-portal__auth-form">
        @csrf
        <x-ui.forms.input id="portal-email" name="email" type="email" label="E-Mail" value="{{ old('email') }}" required autofocus autocomplete="username" />
        <x-ui.forms.input id="portal-password" name="password" type="password" label="Passwort" required autocomplete="current-password" />
        <div class="rt-customer-portal__auth-actions"><a class="rt-customer-portal__auth-link" href="/kundenportal/passwort">Passwort vergessen?</a></div>
        <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
            <span class="rt-customer-portal__button-label">Anmelden</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
        </x-ui.buttons.button-basic>
    </form>
</x-customer-portal-auth-layout>
