<section data-employee-location-status x-data x-on:worktime-synced.window.debounce.400ms="$wire.refreshStatus()" @if($locationStatus['enabled']) wire:poll.60s="refreshStatus" @endif>
    @if($locationStatus['enabled'])
        @php
            $installation = $locationStatus['installation'];
            $consent = $locationStatus['consent'];
            $labels = ['unpaired' => 'Geschäftshandy verbinden', 'consent_missing' => 'Tagesfreigabe fehlt', 'not_working' => 'Keine laufende Arbeitszeit', 'paused' => 'Erfassung pausiert', 'start_required' => 'Standortdienst starten', 'offline' => 'Erfassung unterbrochen / Gerät offline', 'inaccurate' => 'Messung ungenau', 'active' => 'Standortdienst aktiv'];
            $showExplanation = $explanationOpen || $locationStatus['needs_decision'];
        @endphp
        <div class="ops-panel ops-stack" aria-labelledby="employee-location-heading">
            <header class="ops-toolbar"><div><h3 id="employee-location-heading">Standort während der Arbeit</h3><p class="ops-muted" role="status">{{ $labels[$locationStatus['state']] ?? 'Erfassung unterbrochen' }}</p></div><span class="ops-badge">15 Minuten · 7 Tage</span></header>
            @if($showExplanation)
                <div class="ops-stack" role="group" aria-labelledby="employee-location-consent-heading">
                    <h4 id="employee-location-consent-heading">Deine Entscheidung für heute · {{ now('Europe/Berlin')->format('d.m.Y') }}</h4>
                    <p style="white-space:pre-line">{{ $locationStatus['settings']['explanation_text'] }}</p>
                    <p class="ops-muted">Die Freigabe gilt bis Mitternacht (Europe/Berlin). Eine Ablehnung hat keine Nachteile für deine Zeiterfassung. Bitte entscheide und starte den Dienst nur, wenn du sicher stehen kannst.</p>
                    <div class="ops-actions">
                        <x-ui.buttons.button-basic wire:click="accept" wire:loading.attr="disabled" :disabled="!$installation" class="min-h-11">Für heute erlauben</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic wire:click="decline" wire:loading.attr="disabled" class="min-h-11">Heute ohne Standort fortfahren</x-ui.buttons.button-basic>
                    </div>
                    @if(!$installation)<p class="ops-muted">Verbinde zuerst dein zugeordnetes Geschäftshandy, um die Standortfreigabe zu erteilen.</p>@endif
                </div>
            @else
                <div class="ops-actions"><x-ui.buttons.button-basic wire:click="openExplanation" class="min-h-11">Erklärung und Tagesfreigabe öffnen</x-ui.buttons.button-basic></div>
            @endif
            @error('location')<p role="alert" class="ops-warning">{{ $message }}</p>@enderror
            @if($installation)
                <p><strong>Geschäftshandy:</strong> {{ $installation->assignment?->device?->display_name ?: $installation->assignment?->device?->asset_tag }} · {{ $installation->platform === 'ios' ? 'iPhone' : 'Android' }}</p>
                @if($locationStatus['last_point'])
                    <p class="ops-muted">Letzter gespeicherter Punkt: {{ $locationStatus['last_point']->measured_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }} · Genauigkeit {{ number_format($locationStatus['last_point']->accuracy_m, 0, ',', '.') }} m</p>
                @else<p class="ops-muted">Noch kein Punkt gespeichert.</p>@endif
                @if($locationStatus['state'] === 'active' && $installation->next_sample_at)<p class="ops-muted">Nächste Meldung ungefähr {{ $installation->next_sample_at->setTimezone('Europe/Berlin')->format('H:i') }} Uhr. Ausfälle werden nicht nachträglich aufgefüllt.</p>@endif
                @if(in_array($locationStatus['state'], ['start_required', 'offline', 'inaccurate', 'active']) && $locationStatus['running'])
                    <div class="ops-actions">
                        <x-ui.buttons.button-basic wire:click="prepareHandoff" wire:loading.attr="disabled" class="min-h-11">Standortdienst starten / fortsetzen</x-ui.buttons.button-basic>
                        @if($handoffUrl)<a class="ops-link min-h-11 inline-flex items-center" href="{{ $handoffUrl }}" rel="noreferrer">Jetzt im Standortdienst öffnen (60 Sekunden gültig)</a>@endif
                    </div>
                @endif
                @if($consent && $consent->decision === 'accepted' && !$consent->withdrawn_at)
                    <x-ui.buttons.button-basic wire:click="withdraw" wire:loading.attr="disabled" class="min-h-11">Freigabe widerrufen und heutige Punkte löschen</x-ui.buttons.button-basic>
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
            <details>
                <summary class="min-h-11 cursor-pointer" wire:click="$set('historyOpen', true)">Mein Standortverlauf · letzte sieben Tage</summary>
                @if($historyOpen)
                    <div class="overflow-x-auto mt-3"><table class="w-full"><caption class="sr-only">Eigene gespeicherte Standortpunkte</caption><thead><tr><th scope="col">Messzeit</th><th scope="col">Koordinaten</th><th scope="col">Genauigkeit</th></tr></thead><tbody>@forelse($locationStatus['history'] as $point)<tr wire:key="own-location-{{ $point->id }}"><td class="p-2">{{ $point->measured_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i:s') }}</td><td class="p-2">{{ number_format($point->position['latitude'], 6, ',', '.') }}, {{ number_format($point->position['longitude'], 6, ',', '.') }}</td><td class="p-2">{{ number_format($point->accuracy_m, 0, ',', '.') }} m</td></tr>@empty<tr><td colspan="3" class="p-2">Keine Punkte vorhanden.</td></tr>@endforelse</tbody></table></div>
                    <x-ui.buttons.button-basic wire:click="deleteHistory" wire:confirm="Alle eigenen Standortpunkte löschen und die heutige Freigabe widerrufen?" class="min-h-11">Meinen Verlauf löschen</x-ui.buttons.button-basic>
                @endif
            </details>
        </div>
    @endif
    <details class="ops-panel mt-3" x-data="railtimePwaInstall({messages:{installed:'RailTime ist als Web-App geöffnet.',ready:'RailTime kann auf diesem Gerät installiert werden.',manual:'Öffne im Browsermenü „Zum Home-Bildschirm“ oder „App installieren“.',error:'Installation konnte nicht gestartet werden.'}})">
        <summary class="min-h-11 cursor-pointer">RailTime auf dem Geschäftshandy installieren</summary><div class="ops-stack mt-3"><p>Android: im Browsermenü „App installieren“. iPhone: in Safari „Teilen“ → „Zum Home-Bildschirm“. Die Web-App enthält deinen Arbeitstag; für Ortung bei gesperrtem Display richtest du zusätzlich den Standortdienst ein.</p><p class="ops-muted" x-text="statusText()"></p><x-ui.buttons.button-basic x-on:click="installApp()" x-bind:disabled="disabled" class="min-h-11">Web-App installieren / Anleitung</x-ui.buttons.button-basic><p role="status" x-text="notice"></p><p role="alert" x-text="error"></p></div>
    </details>
</section>
