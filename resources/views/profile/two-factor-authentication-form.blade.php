<x-action-section>
    <x-slot name="title">
        {{ __('Zwei-Faktor-Authentifizierung') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Schützen Sie Ihr Konto zusätzlich mit der Zwei-Faktor-Authentifizierung.') }}
    </x-slot>

    <x-slot name="content">
        <h3 class="text-lg font-medium tracking-tight text-rt-text dark:text-rt-dark-text">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Beende das Aktivieren der Zwei-Faktor-Authentifizierung.') }}
                @else
                    {{ __('Sie haben die Zwei-Faktor-Authentifizierung aktiviert.') }}
                @endif
            @else
                {{ __('Sie haben die Zwei-Faktor-Authentifizierung nicht aktiviert.') }}
            @endif
        </h3>

        <div class="mt-3 max-w-xl text-sm text-rt-muted dark:text-rt-dark-muted">
            <p>
                {{ __('Wenn die Zwei-Faktor-Authentifizierung aktiviert ist, werden Sie bei der Anmeldung nach einem sicheren Token gefragt. Sie können dieses Token mit der Google Authenticator-App auf Ihrem Telefon abrufen.') }}
            </p>
        </div>

        @if ($this->enabled)
            @if ($showingQrCode)
                <div class="mt-4 max-w-xl text-sm text-rt-muted dark:text-rt-dark-muted">
                    <p class="font-semibold">
                        @if ($showingConfirmation)
                            {{ __('Um die Zwei-Faktor-Authentifizierung abzuschließen, scannen Sie den folgenden QR-Code mit der Authenticator-App auf Ihrem Telefon oder geben Sie den Setup-Schlüssel und anschließend den generierten OTP-Code ein.') }}
                        @else
                            {{ __('Die Zwei-Faktor-Authentifizierung ist jetzt aktiviert. Scannen Sie den folgenden QR-Code mit der Authenticator-App auf Ihrem Telefon oder geben Sie den Setup-Schlüssel ein.') }}
                        @endif
                    </p>
                </div>

                <div class="mt-4 inline-block rounded-lg bg-white p-2 shadow-rt-xs ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <div class="mt-4 max-w-xl text-sm text-rt-muted dark:text-rt-dark-muted">
                    <p class="font-semibold">
                        {{ __('Setup-Schlüssel') }}: {{ decrypt($this->user->two_factor_secret) }}
                    </p>
                </div>

                @if ($showingConfirmation)
                    <div class="mt-4">
                        <x-ui.forms.label for="code" value="{{ __('Code') }}" />

                        <x-ui.forms.input id="code" type="text" name="code" class="mt-1 block w-1/2" inputmode="numeric" autofocus autocomplete="one-time-code"
                            wire:model="code"
                            wire:keydown.enter="confirmTwoFactorAuthentication" />

                        <x-input-error for="code" class="mt-2" />
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <div class="mt-4 max-w-xl text-sm text-rt-muted dark:text-rt-dark-muted">
                    <p class="font-semibold">
                        {{ __('Speichern Sie diese Wiederherstellungscodes in einem sicheren Passwort-Manager. Damit können Sie den Zugriff auf Ihr Konto wiederherstellen, falls Ihr Zwei-Faktor-Authentifizierungsgerät verloren geht.') }}
                    </p>
                </div>

                <div class="mt-4 grid max-w-xl gap-1 rounded-lg bg-rt-surface-muted px-4 py-4 font-mono text-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="mt-5">
            @if (! $this->enabled)
                <x-confirms-password wire:then="enableTwoFactorAuthentication">
                    <x-button type="button" wire:loading.attr="disabled">
                        {{ __('Aktivieren') }}
                    </x-button>
                </x-confirms-password>
            @else
                @if ($showingRecoveryCodes)
                    <x-confirms-password wire:then="regenerateRecoveryCodes">
                        <x-secondary-button class="me-3">
                            {{ __('Wiederherstellungscodes neu generieren') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @elseif ($showingConfirmation)
                    <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                        <x-button type="button" class="me-3" wire:loading.attr="disabled">
                            {{ __('Bestätigen') }}
                        </x-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="showRecoveryCodes">
                        <x-secondary-button class="me-3">
                            {{ __('Wiederherstellungscodes anzeigen') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @endif

                @if ($showingConfirmation)
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-secondary-button wire:loading.attr="disabled">
                            {{ __('Abbrechen') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-danger-button wire:loading.attr="disabled">
                            {{ __('Deaktivieren') }}
                        </x-danger-button>
                    </x-confirms-password>
                @endif
            @endif
        </div>
    </x-slot>
</x-action-section>
