@if($selectedDevice->relationLoaded('microsoftLink') && ($microsoft = $selectedDevice->microsoftLink))
    @php
        $assignmentLabels = [
            'matched' => 'Microsoft-Konto und Mitarbeiter stimmen überein',
            'suggested' => 'Mitarbeiter erkannt – manuelle Zuweisung auswählen',
            'identity_unlinked' => 'Microsoft-Konto noch keinem Mitarbeiter zugeordnet',
            'no_owner' => 'Microsoft meldet keinen eindeutigen Benutzer',
            'ambiguous_owner' => 'Mehrere Microsoft-Benutzer – Zuweisung prüfen',
            'ambiguous_intune' => 'Mehrere Intune-Einträge – Zuweisung prüfen',
            'assignment_conflict' => 'Abweichender Microsoft-Benutzer – bestehende Zuteilung bleibt erhalten',
            'manual_review' => 'Vorhandene Gerätehistorie – manuelle Zuweisung erforderlich',
            'intune_unavailable' => 'Intune nicht erreichbar – Benutzerzuordnung bleibt unverändert',
            'directory_disabled' => 'Gerät in Entra deaktiviert',
            'directory_unknown' => 'Entra-Aktivierungsstatus ist nicht belegt – Zuweisung prüfen',
            'directory_missing' => 'Gerät beim letzten vollständigen Entra-Abgleich nicht gefunden',
            'device_id_conflict' => 'Entra-Geräte-ID hat sich geändert – Verknüpfung prüfen',
            'serial_conflict' => 'Seriennummer widersprüchlich oder bereits vergeben – Bestand prüfen',
        ];
        $sourceLabel = $microsoft->assignment_source === 'intune_primary_user' ? 'Intune-Hauptbenutzer' : 'Entra-Registrierungsbesitzer';
    @endphp
    <section class="rounded-xl border border-sky-200 p-4 dark:border-sky-900" aria-label="Microsoft-Geräteverknüpfung" data-microsoft-device-detail>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="flex items-center gap-2 font-semibold text-rt-text dark:text-white"><i class="fab fa-microsoft text-sky-600" aria-hidden="true"></i> Microsoft Entra &amp; Windows</h3>
                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Letzter Inventarabgleich: {{ $microsoft->last_synced_at?->format('d.m.Y H:i') ?? 'Noch nicht erfolgt' }}</p>
            </div>
            <a href="https://entra.microsoft.com/#view/Microsoft_AAD_Devices/DevicesMenuBlade/~/Devices" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-2 text-xs font-semibold text-sky-700 hover:underline dark:text-sky-300">In Entra prüfen <i class="far fa-external-link" aria-hidden="true"></i></a>
        </div>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Registrierung</dt><dd class="mt-1 font-medium">{{ match($microsoft->join_type) { 'AzureAd' => 'Microsoft Entra joined', 'ServerAd' => 'Hybrid joined', 'Workplace' => 'Microsoft Entra registered', default => 'Registrierungsart unbekannt' } }}</dd></div>
            <div><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Intune-Verwaltung</dt><dd class="mt-1 font-medium">{{ $microsoft->intune_device_id ? 'Intune-Eintrag vorhanden' : 'Nicht durch Intune belegt' }}</dd></div>
            <div><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $sourceLabel }}</dt><dd class="mt-1 font-medium">{{ $microsoft->suggestedUser?->name ?? 'Noch nicht mit einem Mitarbeiter verknüpft' }}</dd></div>
            <div><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Letzter Intune-Gerätekontakt</dt><dd class="mt-1 font-medium">{{ $microsoft->intune_synced_at?->format('d.m.Y H:i') ?? 'Nicht belegt' }}{{ $microsoft->intune_compliance ? ' · '.$microsoft->intune_compliance : '' }}</dd></div>
        </dl>
        <p class="mt-3 rounded-lg px-3 py-2 text-xs leading-5 {{ $microsoft->assignment_status === 'matched' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' }}">{{ $assignmentLabels[$microsoft->assignment_status] ?? 'Microsoft-Zuordnung prüfen' }}</p>
        <details class="mt-3 text-xs text-rt-muted dark:text-rt-dark-muted">
            <summary class="min-h-8 cursor-pointer">Geräte-IDs und Datenquelle</summary>
            <p class="mt-1 break-all">Entra-Geräte-ID: {{ $microsoft->entra_device_id }}</p>
            <p class="mt-1 break-all">Entra-Objekt-ID: {{ $microsoft->directory_object_id }}</p>
            @if($microsoft->intune_device_id)<p class="mt-1 break-all">Intune-ID: {{ $microsoft->intune_device_id }}</p>@endif
            <p class="mt-2 leading-5">Eine Entra-Registrierung belegt die Geräteidentität. Apps, Richtlinien und Fernsupport werden über die jeweiligen Verwaltungsdienste bereitgestellt. Der Entra-Registrierungsbesitzer kann die Person sein, die das Gerät ursprünglich eingerichtet hat.</p>
        </details>
    </section>
@endif
