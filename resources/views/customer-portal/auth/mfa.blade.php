<x-customer-portal-auth-layout title="Zusätzliche Bestätigung" kicker="Zugangssicherheit">
    @if($enabled)
        <form method="POST" action="/kundenportal/bestaetigung" class="rt-customer-portal__auth-form">
            @csrf
            <x-ui.forms.input id="portal-mfa-code" name="code" label="Authenticator-Code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus />
            <details class="rt-customer-portal__auth-details">
                <summary>Wiederherstellungscode verwenden</summary>
                <x-ui.forms.input id="portal-mfa-recovery" name="recovery_code" label="Wiederherstellungscode" autocomplete="off" maxlength="40" />
            </details>
            <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
                <span class="rt-customer-portal__button-label">Bestätigen</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
            </x-ui.buttons.button-basic>
        </form>
    @else
        <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" href="/kundenportal/sicherheit" data-no-navigate mode="primary">
            <span class="rt-customer-portal__button-label">Authenticator einrichten</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
        </x-ui.buttons.button-basic>
    @endif
    <x-slot:footer><a class="rt-customer-portal__auth-back" href="/kundenportal"><x-customer-portal.icon name="chevron-left" />Zurück zum Kundenportal</a></x-slot:footer>
</x-customer-portal-auth-layout>
