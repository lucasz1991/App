<x-customer-portal-auth-layout title="Einladung annehmen" kicker="Kundenportal">
    <form method="POST" action="/kundenportal/einladung/{{ $token }}" class="rt-customer-portal__auth-form">
        @csrf
        @if($existing)
            <x-ui.forms.input id="portal-current-password" type="password" name="current_password" label="Bestehendes Passwort" autocomplete="current-password" required />
        @else
            <x-ui.forms.input id="portal-name" name="name" label="Name" value="{{ old('name') }}" autocomplete="name" required />
            <x-ui.forms.input id="portal-new-password" type="password" name="password" label="Passwort" minlength="12" maxlength="128" autocomplete="new-password" required />
            <x-ui.forms.input id="portal-confirm-password" type="password" name="password_confirmation" label="Passwort bestätigen" autocomplete="new-password" required />
        @endif
        <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
            <span class="rt-customer-portal__button-label">{{ $existing ? 'Einladung annehmen' : 'Zugang einrichten' }}</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
        </x-ui.buttons.button-basic>
    </form>
    <x-slot:footer><a class="rt-customer-portal__auth-back" href="/kundenportal/anmelden"><x-customer-portal.icon name="chevron-left" />Zur Anmeldung</a></x-slot:footer>
</x-customer-portal-auth-layout>
