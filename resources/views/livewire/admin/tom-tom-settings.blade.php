<section class="space-y-5" aria-label="TomTom Routenplanung">
    <header><h2 class="text-lg font-semibold">TomTom / Routenplanung</h2><p class="text-sm text-rt-muted">Pkw-Fahrzeiten zwischen bestätigten Einsatzorten. Ungeprüfte Fahrten werden als Hinweis angezeigt.</p></header>
    <form wire:submit="save" class="ops-form">
        @if($errors->any())<ul class="ops-full text-sm text-red-700 dark:text-red-300" role="alert">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
        <div class="ops-full"><x-ui.forms.checkbox wire:model="config.enabled" label="Routenplanung aktivieren" /></div>
        <div class="ops-full space-y-2"><label for="tomtom-api-key" class="text-sm font-semibold">API-Schlüssel</label><x-ui.forms.input id="tomtom-api-key" type="password" wire:model="apiKey" autocomplete="new-password" placeholder="{{ $saved['has_api_key'] ? $saved['key_mask'] : 'TomTom-Schlüssel eingeben' }}" /><p class="text-sm text-rt-muted">Leere Eingabe behält den gespeicherten Schlüssel. Der Schlüssel bleibt auf dem Server.</p>@error('key')<p role="alert">{{ $message }}</p>@enderror</div>
        <x-operations.field label="Fahrtreserve (Minuten, mindestens 5)" model="config.reserve_minutes" type="number" min="5" max="120" required />
        <x-operations.field label="Routen- und Adressanfragen pro Monat (gemeinsam)" model="config.routing_monthly_limit" type="number" min="0" required />
        <x-operations.field label="Kartenkacheln pro Monat" model="config.tile_monthly_limit" type="number" min="0" required />
        <div class="ops-full space-y-2 border-t border-rt-border pt-4 dark:border-rt-dark-border">
            <h3 class="font-semibold">Globaler Server-Cache</h3>
            <x-ui.forms.checkbox wire:model="config.server_cache_enabled" label="Gemeinsamen Server-Cache aktivieren" />
            <p class="text-sm text-rt-muted">Alle berechtigten Nutzer verwenden dieselben passenden Ergebnisse. Cache-Treffer erzeugen keine neue TomTom-Anfrage und zählen nicht zum Anbieter-Verbrauch. Der Browser erhält Kartenkacheln ohne Cache-Speicherung.</p>
            <x-ui.forms.checkbox wire:model="config.server_tile_cache_enabled" label="Kartenkacheln serverseitig zwischenspeichern" />
            <x-ui.forms.checkbox wire:model="config.server_route_cache_enabled" label="Geplante Fahrzeiten und Routengeometrien serverseitig zwischenspeichern" />
        </div>
        <x-operations.field label="Kartenkacheln: maximale Dauer (Minuten)" model="config.server_tile_cache_minutes" type="number" min="1" max="1440" required />
        <x-operations.field label="Fahrzeiten: maximale Dauer (Minuten)" model="config.server_route_cache_minutes" type="number" min="1" max="15" required />
        <x-operations.field label="Routengeometrien: maximale Dauer (Minuten)" model="config.server_geometry_cache_minutes" type="number" min="1" max="60" required />
        <x-operations.field label="Gesamter Server-Cache: Speichergrenze (MB)" model="config.server_cache_max_mb" type="number" min="16" max="1024" required />
        <div class="ops-full space-y-2 text-sm text-rt-muted">
            <p>Standard: Karten 60 Minuten, Fahrzeiten 5 Minuten, Routengeometrien 15 Minuten und insgesamt 128 MB. Eine kürzere Kartengültigkeit des Anbieters gilt immer. Abgelaufene Einträge werden sofort ausgeschlossen und automatisch durch den minütlichen Bereinigungslauf gelöscht. Bei voller Speichergrenze werden die ältesten Einträge entfernt.</p>
            <p>Verbindliche Zuteilungen, Verbindungstests und Anreisen vom aktuellen Mitarbeiterstandort werden frisch geprüft. Mitarbeiterstandorte, Freigaben und Zuteilungsentscheidungen werden nicht im gemeinsamen Cache gespeichert. Adressvorschläge werden derzeit frisch gesucht und nicht dauerhaft zwischengespeichert.</p>
            <p role="status">{{ number_format($cacheStats['entries'] ?? 0, 0, ',', '.') }} gespeicherte Einträge · {{ number_format(($cacheStats['bytes'] ?? 0) / 1048576, 2, ',', '.') }} MB belegt</p>
            <x-ui.buttons.button-basic type="button" wire:click="clearServerCache" wire:loading.attr="disabled" wire:target="clearServerCache">Server-Cache für alle Nutzer leeren</x-ui.buttons.button-basic>
        </div>
        <div class="ops-full"><x-ui.forms.checkbox wire:model="config.use_employee_locations" label="Freigegebene Mitarbeiterstandorte für kurzfristige Anreisehinweise nutzen" /><p class="mt-2 text-sm text-rt-muted">Benötigt den eingerichteten und abgenommenen Mitarbeiter-Standortdienst. Eine fehlende Standortfreigabe verschlechtert die Einsatzberechtigung nicht.</p></div>
        <div class="ops-full flex flex-wrap gap-3"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="test" wire:loading.attr="disabled" wire:target="test">Gespeicherte Verbindung testen</x-ui.buttons.button-basic>@if($saved['has_api_key'])<x-ui.buttons.button-basic type="button" wire:click="deleteKey" wire:confirm="API-Schlüssel löschen und die Routenplanung deaktivieren?" wire:loading.attr="disabled">Schlüssel löschen</x-ui.buttons.button-basic>@endif</div>
    </form>
    <div class="space-y-2 border-t border-rt-border pt-4 text-sm dark:border-rt-dark-border"><h3 class="font-semibold">Durch RailTime erfasster Verbrauch · {{ $usage['month'] }}</h3><p>{{ number_format($usage['routing_transactions'], 0, ',', '.') }} / {{ number_format($saved['routing_monthly_limit'], 0, ',', '.') }} Routen- und Adressanfragen · {{ number_format($usage['map_tiles'], 0, ',', '.') }} / {{ number_format($saved['tile_monthly_limit'], 0, ',', '.') }} Kartenkacheln</p><p class="text-rt-muted">Tests, Wiederholungen und Adresssuchen zählen zur gemeinsamen Grenze. TomTom rechnet Suche und Routing nach dem jeweiligen Kontotarif ab; diese RailTime-Grenze ist keine Kostenfreigrenze. Andere Anwendungen desselben TomTom-Kontos sind nicht enthalten. Beim Monatswechsel beginnt der Zähler neu.</p></div>
    @if($connection)<p role="status" class="text-sm">{{ $connection['ok'] ? 'Verbindung erfolgreich geprüft.' : 'Verbindung nicht verfügbar; bitte Schlüssel, Verbrauchsgrenze und Anbieterstatus prüfen.' }}</p>@endif
    <p wire:loading wire:target="test" role="status">Verbindung wird geprüft …</p>
</section>
