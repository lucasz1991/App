<div class="space-y-3">
    @foreach (['push' => 'Push-Benachrichtigungen', 'location' => 'Standort für die Einsatzplanung'] as $purpose => $label)
        @php
            $available = $purpose === 'push' ? $permissions['push_available'] : $permissions['location_enabled'];
            $decision = $permissions[$purpose.'_decision'];
        @endphp
        <details wire:key="{{ $permissionIdPrefix }}-{{ $purpose }}" class="rounded-xl border border-rt-border/70 dark:border-rt-dark-border/70" data-session-permission-purpose="{{ $purpose }}" data-permission-explanation="{{ $purpose }}">
            <summary class="min-h-11 cursor-pointer rounded-xl p-4 text-sm text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent dark:text-rt-dark-text">
                <span class="font-semibold">{{ $label }}</span>
            </summary>
            <div class="space-y-3 px-4 pb-4">
                @if ($automatic)
                <p class="text-xs font-semibold text-rt-muted dark:text-rt-dark-muted" role="status">{{ !$available ? 'Derzeit nicht verfügbar' : ($decision === 'accepted' ? 'Für diese Sitzung erlaubt' : ($decision === 'declined' ? 'Für diese Sitzung abgelehnt' : 'Noch keine Entscheidung')) }}</p>
                @if ($purpose === 'push')
                    <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">RailTime darf dir Benachrichtigungen über neue Nachrichten und betriebliche Hinweise senden. Die Browserberechtigung und Einrichtung gelten separat für dieses Gerät. Auf dem iPhone wird die installierte Web-App benötigt.</p>
                @elseif ($available)
                    <p class="whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $permissions['location_text'] }}</p>
                    <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Mit Freigabe und Geräteberechtigung wird während aktiver App-Nutzung dein letzter präziser Punkt gespeichert. Im Standby werden keine neuen Positionen erfasst. Eine fehlende oder veraltete Position verschlechtert deine Einsatzberechtigung nicht.</p>
                @endif
                @endif
                @if ($available)
                    <div class="grid gap-2 sm:grid-cols-2">
                        <x-ui.buttons.button-basic mode="secondary" :x-on:click="'allow('.\Illuminate\Support\Js::from($purpose).', '.($decision === 'accepted' ? 'true' : 'false').')'" x-bind:disabled="busy !== null" wire:loading.attr="disabled" class="min-h-11 rounded-xl" data-permission-allow="{{ $purpose }}">{{ $decision === 'accepted' ? 'Geräteberechtigung einrichten' : 'Erlauben' }}</x-ui.buttons.button-basic>
                        @if ($decision === 'accepted')
                            <x-ui.buttons.button-basic mode="secondary" wire:click="revoke('{{ $purpose }}')" x-bind:disabled="busy !== null" wire:loading.attr="disabled" class="min-h-11 rounded-xl" data-permission-withdraw="{{ $purpose }}">Freigabe widerrufen</x-ui.buttons.button-basic>
                        @else
                            <x-ui.buttons.button-basic mode="secondary" x-on:click="decline('{{ $purpose }}')" x-bind:disabled="busy !== null" wire:loading.attr="disabled" class="min-h-11 rounded-xl" data-permission-decline="{{ $purpose }}">Ohne {{ $purpose === 'push' ? 'Push' : 'Standort' }} fortfahren</x-ui.buttons.button-basic>
                        @endif
                    </div>
                    @if ($automatic && $purpose === 'location' && $decision === 'accepted')
                        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Ein Widerruf beendet die Erfassung und die Routing-Nutzung; gespeicherte Punkte dieser Freigabe werden gelöscht.</p>
                    @endif
                @else
                    @if ($automatic)
                    <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $purpose === 'push' ? 'Push ist serverseitig noch nicht eingerichtet oder ausgeschaltet.' : 'Arbeitsstandorte sind für dein Konto derzeit nicht aktiviert.' }}</p>
                    @endif
                    @if ($decision === 'accepted')
                        @if ($automatic)
                        <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Deine gespeicherte Freigabe kannst du auch bei ausgeschalteter Funktion widerrufen.</p>
                        @endif
                        <x-ui.buttons.button-basic mode="secondary" wire:click="revoke('{{ $purpose }}')" x-bind:disabled="busy !== null" wire:loading.attr="disabled" class="min-h-11 rounded-xl" data-permission-withdraw="{{ $purpose }}">Freigabe widerrufen</x-ui.buttons.button-basic>
                    @elseif (! $automatic)
                        <x-ui.buttons.button-basic mode="secondary" disabled class="min-h-11 rounded-xl">Derzeit nicht verfügbar</x-ui.buttons.button-basic>
                    @endif
                @endif
                @if (! $automatic && $purpose === 'location')
                    <livewire:settings.chat-location-privacy :compact="true" />
                @endif
            </div>
        </details>
    @endforeach
    @if ($automatic)
    <details class="rounded-xl border border-rt-border/70 dark:border-rt-dark-border/70" data-session-permission-validity>
        <summary class="min-h-11 cursor-pointer rounded-xl p-3 text-sm font-semibold text-rt-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent dark:text-rt-dark-muted">Gültigkeit und Einstellungen</summary>
        <div class="space-y-3 px-4 pb-4 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            <p>Beide Freigaben sind freiwillig und unabhängig voneinander. Deine Entscheidung gilt für diese aktive Anmeldesitzung. Die normale Nutzung der App verlängert die Sitzung und damit diese Entscheidung. Es gibt keine erneute tägliche Nachfrage. Nach Abmeldung oder Sitzungsablauf wirst du erneut gefragt.</p>
            <p>Du kannst deine Entscheidung jederzeit unter <a href="{{ route('profile.show', ['tab' => 'app']) }}" class="font-semibold text-rt-accent underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent">Mein Profil → Einstellungen → Push und Standort</a> ändern.</p>
            <p data-session-idle-duration>
                @php
                    $idleMinutes = \App\Support\Privacy\SessionLifetimePolicy::minutes(auth()->user());
                @endphp
                Diese Sitzung läuft nach {{ $idleMinutes % 60 === 0 ? ($idleMinutes / 60).' Stunden' : $idleMinutes.' Minuten' }} ohne App-Nutzung ab. Automatische Standortmeldungen verlängern die Anmeldung nicht. Bei geändertem Erklärtext ist einmalig eine neue Entscheidung erforderlich.
            </p>
            <p>Browser- und Geräteberechtigungen können trotz RailTime-Freigabe fehlen oder entzogen werden. Erlaube sie nur, wenn du sicher stehen kannst.</p>
            @if ($permissions['expires_at'])
                <p data-session-permission-expiry>Ohne weitere Nutzung läuft diese Sitzung voraussichtlich am {{ \Carbon\CarbonImmutable::parse($permissions['expires_at'])->setTimezone('Europe/Berlin')->format('d.m.Y \u\m H:i') }} Uhr ab.</p>
            @endif
        </div>
    </details>
    @endif
    @error('permissions')<p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">{{ $message }}</p>@enderror
    @error('location')<p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">{{ $message }}</p>@enderror
    @error('push')<p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">{{ $message }}</p>@enderror
    <p x-cloak x-show="browserMessage" x-text="browserMessage" role="status" aria-live="polite" class="rounded-xl bg-rt-surface-muted p-3 text-sm leading-6 text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-text"></p>
</div>
