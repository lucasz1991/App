<section data-employee-location-status x-data x-on:worktime-synced.window.debounce.400ms="$wire.refreshStatus()" x-on:railtime-app-location-updated.window.debounce.400ms="$wire.refreshStatus()" @if($locationStatus['enabled']) wire:poll.60s="refreshStatus" @endif>
    @if($locationStatus['enabled'])
        @php
            $installation = $locationStatus['installation'];
            $consent = $locationStatus['consent'];
            $labels = ['unpaired' => 'Geschäftshandy verbinden', 'consent_missing' => 'Freigabe für diese Anmeldesitzung fehlt', 'not_working' => 'Keine laufende Arbeitszeit', 'paused' => 'Erfassung pausiert', 'start_required' => 'Standortdienst starten', 'offline' => 'Erfassung unterbrochen / Gerät offline', 'inaccurate' => 'Messung ungenau', 'unavailable' => 'Standortmessung derzeit nicht verfügbar', 'active' => 'Standortdienst aktiv'];
            $showExplanation = $explanationOpen || $locationStatus['needs_decision'];
        @endphp
        <div class="ops-panel ops-stack" aria-labelledby="employee-location-heading">
            <header class="ops-toolbar"><div><h3 id="employee-location-heading">App-Standort</h3><p class="ops-muted" role="status">{{ !$browserLocationStatus['consented'] ? 'Freigabe für diese Anmeldesitzung fehlt' : ($browserLocationStatus['last_point_at'] ? 'Letzter Punkt aus der App-Nutzung verfügbar' : 'Noch kein präziser App-Standort empfangen') }}</p></div><span class="ops-badge">Letzter Punkt · höchstens 15 Minuten aktuell</span></header>
            <p class="ops-muted">Mit deiner Freigabe wird bei der aktiven Nutzung der sichtbaren RailTime-App der letzte präzise Standort ersetzt, höchstens einmal pro Minute. Das funktioniert unabhängig von deiner Zeiterfassung und ohne zusätzlichen Standortdienst. Schichtplanung und Anreisehinweise dürfen nur einen höchstens 15 Minuten alten Punkt verwenden.</p>
            @if($browserLocationStatus['last_point_at'])
                <p><strong>Zuletzt gemeldet:</strong> {{ \Carbon\CarbonImmutable::parse($browserLocationStatus['last_point_at'])->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }} · Genauigkeit {{ number_format($browserLocationStatus['accuracy_m'], 0, ',', '.') }} m</p>
            @endif
            <p class="ops-muted">Bei geschlossenem oder gesperrtem Handy pausiert die Web-App. Beim nächsten Benutzen kann sie wieder einen aktuellen Punkt melden. Es gibt keinen App-Standortverlauf; abgelaufene Punkte sind sofort ausgeschlossen und werden spätestens im stündlichen Löschlauf entfernt.</p>
            @if($showExplanation)
                <div class="ops-stack" role="group" aria-labelledby="employee-location-consent-heading">
                    <h4 id="employee-location-consent-heading">Deine Entscheidung für diese Anmeldesitzung</h4>
                    <p style="white-space:pre-line">{{ $locationStatus['settings']['explanation_text'] }}</p>
                    <p class="ops-muted">Die Freigabe gilt, solange deine Anmeldesitzung aktiv bleibt. Die Nutzung der App verlängert sie; eine neue tägliche Zustimmung ist nicht nötig. Abmeldung, Sitzungsablauf oder Widerruf beenden die Freigabe. Eine Ablehnung hat keine Nachteile für deine Zeiterfassung. Bitte entscheide nur, wenn du sicher stehen kannst.</p>
                    <div class="ops-actions">
                        <x-ui.buttons.button-basic wire:click="accept" wire:loading.attr="disabled" class="min-h-11">Für diese Sitzung erlauben</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic wire:click="decline" wire:loading.attr="disabled" class="min-h-11">Ohne Standort fortfahren</x-ui.buttons.button-basic>
                    </div>
                    <p class="ops-muted">Die Standortberechtigung deines Browsers wird zusätzlich benötigt. Du kannst sie unter „Mein Profil → Einstellungen → Push und Standort“ einrichten.</p>
                </div>
            @else
                <div class="ops-actions"><x-ui.buttons.button-basic wire:click="openExplanation" class="min-h-11">Erklärung und Freigabe öffnen</x-ui.buttons.button-basic><a class="ops-link min-h-11 inline-flex items-center" href="{{ route('profile.show', ['tab' => 'app']) }}">Push und Standort im eigenen Profil</a></div>
            @endif
            @error('location')<p role="alert" class="ops-warning">{{ $message }}</p>@enderror
            @if($browserLocationStatus['consented'] || ($consent && $consent->decision === 'accepted' && !$consent->withdrawn_at))
                <x-ui.buttons.button-basic wire:click="withdraw" wire:loading.attr="disabled" class="min-h-11">Freigabe widerrufen und zugehörige Punkte löschen</x-ui.buttons.button-basic>
            @endif
            @if($nativeConfigured)
            <details>
                <summary class="min-h-11 cursor-pointer">Optionaler Arbeitsstandortdienst</summary>
                <div class="ops-stack mt-3">
                    <p class="ops-muted">Zusätzlich eingerichtete native Arbeitsstandorte: alle 15 Minuten während laufender Arbeitszeit, sieben Tage Aufbewahrung. Für den App-Standort oben ist diese Einrichtung nicht erforderlich.</p>
                    <p class="ops-muted" role="status">{{ $labels[$locationStatus['state']] ?? 'Erfassung unterbrochen' }}</p>
            @if($installation)
                <p><strong>Geschäftshandy:</strong> {{ $installation->assignment?->device?->display_name ?: $installation->assignment?->device?->asset_tag }} · {{ $installation->platform === 'ios' ? 'iPhone' : 'Android' }}</p>
                @if($locationStatus['last_point'])
                    <p class="ops-muted">Letzter gespeicherter Punkt: {{ $locationStatus['last_point']->measured_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }} · Genauigkeit {{ number_format($locationStatus['last_point']->accuracy_m, 0, ',', '.') }} m</p>
                @else<p class="ops-muted">Noch kein Punkt gespeichert.</p>@endif
                @if($locationStatus['state'] === 'active' && $installation->next_sample_at)<p class="ops-muted">Nächste Meldung ungefähr {{ $installation->next_sample_at->setTimezone('Europe/Berlin')->format('H:i') }} Uhr. Ausfälle werden nicht nachträglich aufgefüllt.</p>@endif
                @if(in_array($locationStatus['state'], ['start_required', 'offline', 'inaccurate', 'unavailable', 'active']) && $locationStatus['running'])
                    <div class="ops-actions">
                        <x-ui.buttons.button-basic wire:click="prepareHandoff" wire:loading.attr="disabled" class="min-h-11">Standortdienst starten / fortsetzen</x-ui.buttons.button-basic>
                    </div>
                    @if($handoffCode && $handoffExpiresAt && now()->lt($handoffExpiresAt))
                        @php
                            $handoffExpiry = \Carbon\CarbonImmutable::parse($handoffExpiresAt);
                            $handoffRemainingMs = max(0, (int) now()->diffInMilliseconds($handoffExpiry, false));
                        @endphp
                        <div class="ops-stack" wire:key="location-handoff-{{ hash('sha256', $handoffCode) }}" x-data="{ usable: true }" x-init="setTimeout(() => { usable = false; $wire.refreshStatus() }, {{ $handoffRemainingMs }})">
                            <div class="ops-stack" x-show="usable">
                                <a class="ops-link min-h-11 inline-flex items-center" href="{{ $handoffUrl }}" rel="noreferrer">Jetzt im Standortdienst öffnen</a>
                                <label for="employee-location-start-code">Einmaliger Startcode · gültig bis {{ $handoffExpiry->setTimezone('Europe/Berlin')->format('H:i:s') }} Uhr</label>
                                <input id="employee-location-start-code" type="text" readonly value="{{ $handoffCode }}" autocomplete="off" spellcheck="false" class="min-h-11 w-full" />
                                <p class="ops-muted">Falls die App-Verknüpfung noch nicht funktioniert: Öffne den Standortdienst selbst und kopiere diesen Code in „Startcode“. Er gilt höchstens 60 Sekunden und nur einmal für dein verbundenes Geschäftshandy.</p>
                            </div>
                            <p class="ops-muted" x-show="!usable" x-cloak>Startcode abgelaufen. Bereite den Start erneut vor.</p>
                        </div>
                    @endif
                @endif
            @endif
            <details>
                <summary class="min-h-11 cursor-pointer">Geschäftshandy einrichten oder wechseln</summary>
                <div class="ops-stack mt-3">
                    <p>Installiere den betrieblich bereitgestellten Android- oder iPhone-Standortdienst. Gib dort die RailTime-HTTPS-Adresse und den einmaligen Verbindungscode ein. Ein neues Gerät beendet die bisherige Standortinstallation.</p>
                    @foreach(['android' => 'Android-Standortdienst', 'ios' => 'iPhone-Standortdienst'] as $platform => $label)
                        @if($locationStatus['settings'][$platform.'_install_url'])<a class="ops-link min-h-11 inline-flex items-center" href="{{ $locationStatus['settings'][$platform.'_install_url'] }}" target="_blank" rel="noopener noreferrer">{{ $label }} installieren</a>@endif
                    @endforeach
                    <label for="employee-location-device">Zugeordnetes Geschäftshandy</label>
                    <select id="employee-location-device" wire:model="assignmentId" class="min-h-11 w-full"><option value="">Auswählen</option>@foreach($locationStatus['assignments'] as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->device->display_name ?: $assignment->device->asset_tag }}</option>@endforeach</select>
                    @error('assignmentId')<p role="alert">{{ $message }}</p>@enderror
                    <x-ui.buttons.button-basic wire:click="createRegistration" wire:loading.attr="disabled" class="min-h-11">Einmaligen Verbindungscode erstellen</x-ui.buttons.button-basic>
                    @if($registrationCode && $registrationExpiresAt && now()->lt($registrationExpiresAt))<label for="employee-location-code">Verbindungscode · 15 Minuten gültig</label><input id="employee-location-code" type="text" readonly value="{{ $registrationCode }}" autocomplete="off" spellcheck="false" class="min-h-11 w-full" /><p class="ops-muted">Der Code ist nur für die Standortinstallation bestimmt. Gib ihn nicht an andere weiter.</p>@endif
                </div>
            </details>
                </div>
            </details>
            @endif
            @if($locationStatus['last_point'] || $historyOpen)
            @include('livewire.operations.partials.employee-location-history')
            @endif
        </div>
    @elseif($locationStatus['last_point'])
        <div class="ops-panel ops-stack" aria-labelledby="employee-location-history-heading">
            <h3 id="employee-location-history-heading">Mein Standortverlauf</h3>
            <p class="ops-muted">Die Standorterfassung ist ausgeschaltet. Deine noch gespeicherten Punkte kannst du weiterhin ansehen und löschen; sie verfallen spätestens nach sieben Tagen.</p>
            @include('livewire.operations.partials.employee-location-history')
        </div>
    @endif
</section>
