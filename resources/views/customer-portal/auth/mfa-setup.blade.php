<x-customer-portal-auth-layout title="Zugangssicherheit" kicker="Kundenportal">
    @if($state['pending'])
        <p class="rt-customer-portal__auth-hint">QR-Code in der Authenticator-App scannen.</p>
        <div class="rt-customer-portal__auth-qr" role="img" aria-label="QR-Code für die Authenticator-App">{!! $state['qr_svg'] !!}</div>
        <details class="rt-customer-portal__auth-details">
            <summary>Schlüssel manuell eingeben</summary><code class="rt-customer-portal__auth-secret">{{ $state['secret'] }}</code>
        </details>
        <form method="POST" action="/kundenportal/sicherheit/bestaetigen" class="rt-customer-portal__auth-form">
            @csrf
            <x-ui.forms.input id="portal-setup-code" name="code" label="Authenticator-Code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required />
            <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
                <span class="rt-customer-portal__button-label">Einrichtung bestätigen</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="check" /></span>
            </x-ui.buttons.button-basic>
        </form>
    @else
        <div class="rt-customer-portal__auth-state" data-state="{{ $state['enabled'] ? 'success' : 'neutral' }}">
            <x-customer-portal.icon name="shield" />
            <div><strong>{{ $state['enabled'] ? 'Authenticator aktiv' : 'Authenticator nicht eingerichtet' }}</strong>
                @if($state['enabled'])<span>{{ $state['remaining_codes'] }} Wiederherstellungscodes verfügbar</span>@endif
            </div>
        </div>
        <form method="POST" action="/kundenportal/sicherheit/einrichten" class="rt-customer-portal__auth-form">
            @csrf
            <x-ui.forms.input id="portal-setup-password" name="password" type="password" label="Passwort bestätigen" autocomplete="current-password" required />
            @if($state['enabled'])
                <x-ui.forms.input id="portal-replace-code" name="code" label="Aktueller Authenticator-Code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" />
                <details class="rt-customer-portal__auth-details">
                    <summary>Wiederherstellungscode verwenden</summary><x-ui.forms.input id="portal-replace-recovery" name="recovery_code" label="Wiederherstellungscode" autocomplete="off" maxlength="40" />
                </details>
            @endif
            <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
                <span class="rt-customer-portal__button-label">{{ $state['enabled'] ? 'Authenticator wechseln' : 'Authenticator einrichten' }}</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="arrow-right" /></span>
            </x-ui.buttons.button-basic>
        </form>
        @if($state['enabled'])
            <div class="rt-customer-portal__auth-options">
                <details class="rt-customer-portal__auth-details">
                    <summary>Wiederherstellungscodes erneuern</summary>
                    <form method="POST" action="/kundenportal/sicherheit/wiederherstellung" class="rt-customer-portal__auth-form">
                        @csrf
                        <x-ui.forms.input id="portal-codes-password" name="password" type="password" label="Passwort bestätigen" autocomplete="current-password" required />
                        <x-ui.forms.input id="portal-codes-code" name="code" label="Authenticator-Code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" />
                        <x-ui.forms.input id="portal-codes-recovery" name="recovery_code" label="Oder Wiederherstellungscode" autocomplete="off" maxlength="40" />
                        <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="primary">
                            <span class="rt-customer-portal__button-label">Neue Codes erstellen</span><span class="rt-customer-portal__button-icon"><x-customer-portal.icon name="key" /></span>
                        </x-ui.buttons.button-basic>
                    </form>
                </details>
                @unless($state['required'])
                    <details class="rt-customer-portal__auth-details">
                        <summary>Authenticator deaktivieren</summary>
                        <form method="POST" action="/kundenportal/sicherheit/deaktivieren" class="rt-customer-portal__auth-form">
                            @csrf
                            <x-ui.forms.input id="portal-disable-password" name="password" type="password" label="Passwort bestätigen" autocomplete="current-password" required />
                            <x-ui.forms.input id="portal-disable-code" name="code" label="Authenticator-Code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" />
                            <x-ui.forms.input id="portal-disable-recovery" name="recovery_code" label="Oder Wiederherstellungscode" autocomplete="off" maxlength="40" />
                            <x-ui.buttons.button-basic class="rt-customer-portal__auth-submit" type="submit" mode="danger">Deaktivieren</x-ui.buttons.button-basic>
                        </form>
                    </details>
                @endunless
            </div>
        @endif
    @endif
    <x-slot:footer><a class="rt-customer-portal__auth-back" href="/kundenportal"><x-customer-portal.icon name="chevron-left" />Zurück zum Kundenportal</a></x-slot:footer>
</x-customer-portal-auth-layout>
