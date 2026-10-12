<section class="employee-location-history" aria-labelledby="employee-location-history-title-{{ $userId }}" wire:loading.attr="aria-busy">
    <header class="employee-location-history__header">
        <div>
            <p class="employee-location-history__kicker">Nur für Administratoren</p>
            <h2 id="employee-location-history-title-{{ $userId }}">Standortverlauf</h2>
            <p class="employee-location-history__subtitle">Aufbewahrungszeitraum: 7 Tage · Europe/Berlin</p>
        </div>
        <span class="employee-location-history__privacy"><i class="far fa-shield-check" aria-hidden="true"></i> Geschützter Verlauf</span>
    </header>

    @if($locationHistory['state'] === 'restricted')
        <div class="employee-location-history__state" role="status">
            <span class="employee-location-history__state-icon"><i class="far fa-lock" aria-hidden="true"></i></span>
            <h3>Standortzugriff nicht freigegeben</h3>
            <p>Zusätzlich zur Administratorrolle sind eine ausdrückliche Standortberechtigung und die passende Personalzuständigkeit erforderlich. Es wurden keine Standortdaten geladen.</p>
        </div>
    @elseif($locationHistory['state'] === 'unconfigured')
        <div class="employee-location-history__state" role="status">
            <span class="employee-location-history__state-icon"><i class="far fa-map-marker-alt" aria-hidden="true"></i></span>
            <h3>Standortverlauf noch nicht eingerichtet</h3>
            <p>Die Standortfunktion ist technisch noch nicht vollständig eingerichtet. Dieser Tab aktiviert keine Erfassung.</p>
        </div>
    @else
        <div class="employee-location-history__toolbar">
            <div class="employee-location-history__day-navigation" aria-label="Tag im Standortverlauf">
                <x-ui.buttons.button-basic type="button" mode="basic" class="employee-location-history__day-button" wire:click="previousDay" wire:loading.attr="disabled"
                    :disabled="$locationHistory['previous_available'] ? null : true" aria-label="Vorheriger Tag" title="Vorheriger Tag">
                    <i class="far fa-chevron-left" aria-hidden="true"></i>
                </x-ui.buttons.button-basic>
                <div class="employee-location-history__day-select">
                    <x-ui.forms.select wire:model.live="selectedDay" aria-label="Tag auswählen" id="employee-location-history-day-{{ $userId }}">
                        @foreach($locationHistory['days'] as $day)
                            <option value="{{ $day['value'] }}">{{ $day['label'] }}</option>
                        @endforeach
                    </x-ui.forms.select>
                </div>
                <x-ui.buttons.button-basic type="button" mode="basic" class="employee-location-history__day-button" wire:click="nextDay" wire:loading.attr="disabled"
                    :disabled="$locationHistory['next_available'] ? null : true" aria-label="Nächster Tag" title="Nächster Tag">
                    <i class="far fa-chevron-right" aria-hidden="true"></i>
                </x-ui.buttons.button-basic>
            </div>
            <x-ui.buttons.button-basic type="button" mode="basic" class="employee-location-history__refresh" wire:click="refreshHistory" wire:loading.attr="disabled" aria-label="Standortverlauf aktualisieren" title="Aktualisieren">
                <i class="far fa-sync-alt" aria-hidden="true" wire:loading.remove></i>
                <svg wire:loading class="h-4 w-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" opacity=".2"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </x-ui.buttons.button-basic>
        </div>
        <x-ui.forms.input-error for="selectedDay" />
        <x-ui.forms.input-error for="locationHistoryPage" />
        <p class="employee-location-history__notice" wire:offline role="status">Verbindung unterbrochen. Der angezeigte Verlauf wurde nicht erneut geladen; dies ist keine Live-Ortung.</p>

        <div class="employee-location-history__summary">
            <div><span>Ausgewählter Tag</span><strong>{{ $locationHistory['day_label'] }}</strong></div>
            <div><span>Messpunkte</span><strong>{{ $locationHistory['total'] }}</strong></div>
            <div><span>Letzte Messung an diesem Tag</span><strong>{{ $locationHistory['latest']['time'] ?? 'Keine Messung' }}</strong></div>
        </div>

        @if(!$locationHistory['enabled'])
            <p class="employee-location-history__notice"><i class="far fa-pause-circle" aria-hidden="true"></i> Die Erfassung ist deaktiviert. Dies betrifft den Arbeitsstandortdienst; ausdrücklich freigegebene Chat-Standorte werden unabhängig davon angezeigt. Vorhandene Messpunkte bleiben nur innerhalb der Aufbewahrungsfrist sichtbar.</p>
        @endif
        <p class="employee-location-history__notice">Dies ist ein Verlauf, kein Live-Standort. Arbeitsstandorte und ausdrücklich für das Personalprofil freigegebene Chat-Standorte sind nach Quelle gekennzeichnet. Lücken können durch Pausen, fehlende Freigaben, Netz- oder Geräteunterbrechungen entstehen. Aus Messpunkten werden keine Arbeitszeiten abgeleitet.</p>
        @if($locationHistory['partial_day'])
            <p class="employee-location-history__notice">Teil des Tages: Für das älteste Datum liegen nur Messungen nach {{ $locationHistory['cutoff_time'] }} innerhalb der siebentägigen Aufbewahrungsfrist.</p>
        @endif

        @if($locationHistory['state'] === 'empty')
            <div class="employee-location-history__state" role="status">
                <span class="employee-location-history__state-icon"><i class="far fa-map-marker-alt" aria-hidden="true"></i></span>
                <h3>Keine freigegebenen Messpunkte</h3>
                <p>Für diesen Tag liegen keine gültigen Messpunkte vor. Es werden keine Standorte geschätzt und keine neuen Messungen ausgelöst.</p>
            </div>
        @else
            <div class="employee-location-history__layout" wire:loading.class="opacity-60">
                <div class="employee-location-history__timeline">
                    <div class="employee-location-history__timeline-heading"><h3>Messungen</h3><span>Neueste zuerst</span></div>
                    <ol aria-label="Gespeicherte Standortmessungen">
                        @foreach($locationHistory['records'] as $record)
                            <li class="employee-location-history__entry" wire:key="employee-location-{{ $userId }}-point-{{ $record['key'] }}">
                                <time class="employee-location-history__time" datetime="{{ $record['measured_at'] }}">{{ $record['time'] }}<span>{{ $record['display_date'] }}</span></time>
                                <div class="employee-location-history__point"><strong>GPS-Messpunkt · {{ $record['source_label'] }}</strong><span>{{ $record['coordinates'] ?? 'Koordinaten nicht verfügbar' }}</span></div>
                                <span class="employee-location-history__accuracy" title="Gemeldete Messgenauigkeit"><i class="far fa-crosshairs" aria-hidden="true"></i> ± {{ $record['accuracy'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
                <figure class="employee-location-history__map">
                    <figcaption><h3>Letzter Messpunkt des Tages</h3><span>{{ $locationHistory['latest']['time'] }} · ± {{ $locationHistory['latest']['accuracy'] }}</span></figcaption>
                    @if($locationHistory['map'])
                        <svg viewBox="{{ $locationHistory['map']['view_box'] }}" role="img" aria-label="Deutschland-Orientierung mit dem letzten gespeicherten Messpunkt; kein Live-Standort">
                            <path class="employee-location-history__outline" d="{{ $locationHistory['map']['outline'] }}" />
                            <circle class="employee-location-history__marker-halo" cx="{{ $locationHistory['map']['x'] }}" cy="{{ $locationHistory['map']['y'] }}" r="7" />
                            <circle class="employee-location-history__marker" cx="{{ $locationHistory['map']['x'] }}" cy="{{ $locationHistory['map']['y'] }}" r="3" />
                        </svg>
                        <p>Deutschland-Orientierung · keine Live-Ortung</p>
                    @else
                        <div class="employee-location-history__map-unavailable"><i class="far fa-map-marker-alt" aria-hidden="true"></i><p>{{ $locationHistory['latest']['coordinates'] ? 'Messpunkt außerhalb des Kartenausschnitts.' : 'Kein darstellbarer Messpunkt.' }}</p></div>
                    @endif
                    <small>Die Darstellung bleibt lokal. Es werden keine Koordinaten an Kartenanbieter übertragen.</small>
                </figure>
            </div>
        @endif

        <footer class="employee-location-history__pagination">
            <x-tables.pagination :paginator="$locationHistory['records']" label="Seiten des Standortverlaufs" :table-key="'employee-location-history-'.$userId" />
        </footer>
    @endif
</section>
