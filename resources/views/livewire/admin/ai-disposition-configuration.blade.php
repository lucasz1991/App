<section class="ops-stack min-w-0" data-ai-disposition-configuration wire:poll.visible.15s aria-labelledby="ai-disposition-heading">
    <header class="ops-section-heading">
        <div><h2 id="ai-disposition-heading" class="text-lg font-semibold">AI-Disposition</h2><p class="ops-muted">Anfragen aus dem Dispositionspostfach erfassen, Rückfragen klären und die Planung vorbereiten.</p></div>
    </header>
    <div class="grid gap-3 sm:grid-cols-3" aria-label="Betriebsstatus">
        @foreach(['configured'=>'Gemeinsame AI','mailbox_configured'=>'Postfach','supervisor_ready'=>'Verantwortliche Disposition'] as $key=>$label)
            <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border"><p class="text-sm font-semibold">{{ $label }}</p><p class="ops-muted">{{ $status[$key] ? 'Eingerichtet' : 'Einrichtung offen' }}</p></div>
        @endforeach
    </div>
    <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border">
        <div class="ops-toolbar"><div><h3 class="font-semibold">Gemeinsame AI-Verbindung</h3><p class="ops-muted">Zugang und Modelle werden aus der bestehenden OpenRouter-Konfiguration übernommen.</p></div><x-ui.buttons.button-basic mode="link" :href="route('admin.settings',['tab'=>'superadmin','section'=>'assistant-runtime'])">AI-Verbindung öffnen</x-ui.buttons.button-basic></div>
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">@foreach(['text_model'=>'Text','data_model'=>'Daten','image_understanding_model'=>'Bilder','speech_to_text_model'=>'Sprache'] as $key=>$label)<div><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt><dd class="break-all text-sm">{{ $models[$key] ?: 'Noch nicht hinterlegt' }}</dd></div>@endforeach</dl>
    </div>
    <form wire:submit="save" class="ops-stack">
        <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border">
            <h3 class="mb-3 font-semibold">Betrieb und Zuständigkeit</h3>
            <div class="ops-form">
                <label class="flex min-h-11 items-center gap-3"><input type="checkbox" wire:model="form.enabled" class="rounded border-rt-border text-rt-accent focus:ring-rt-accent" />AI-Annahme und Kundenmails aktivieren</label>
                <x-operations.field label="Verarbeitung" model="form.automation_mode" type="select"><option value="automatic">Automatisch erfassen und rückfragen</option><option value="assisted">Nur vorbereiten, manuell übernehmen</option></x-operations.field>
                <x-operations.field label="Verantwortlicher Disponent" model="form.supervisor_id" type="select" :wide="true"><option value="">Bitte auswählen</option>@foreach($supervisors as $supervisor)<option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>@endforeach</x-operations.field>
            </div>
            <p class="mt-3 ops-muted">Angebote, verbindliche Zusagen, Personaleinsatz und Veröffentlichung werden von der Disposition freigegeben. Personalanfragen werden separat aktiviert.</p>
        </div>
        <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border" data-ai-customer-communication>
            <h3 class="mb-3 font-semibold">Kundenkommunikation</h3>
            <div class="ops-form">
                @foreach(['customer_receipt_mode'=>'Eingangsbestätigung','customer_clarification_mode'=>'Rückfragen zu fehlenden Angaben','customer_confirmation_mode'=>'Auftragsbestätigung nach Freigabe'] as $key=>$label)
                    <x-operations.field :label="$label" :model="'form.'.$key" type="select">
                        <option value="off">Aus</option><option value="draft">Entwurf zur Freigabe</option><option value="automatic">Automatisch</option>
                    </x-operations.field>
                @endforeach
            </div>
            <p class="mt-3 ops-muted">Mails gehen an den eindeutig zugeordneten Kundenkontakt. Eingangsbestätigungen und Rückfragen enthalten keine Zusage. Auftragsbestätigungen verwenden den bereits freigegebenen Auftrag. Im vorbereitenden Betrieb entstehen Entwürfe zur Freigabe.</p>
        </div>
        <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border">
            <h3 class="mb-3 font-semibold">Posteingang · IMAP</h3>
            <div class="ops-form">
                <x-operations.field label="IMAP-Server" model="form.imap_host" autocomplete="off" />
                <x-operations.field label="Port" model="form.imap_port" type="number" min="1" max="65535" />
                <x-operations.field label="Verschlüsselung" model="form.imap_encryption" type="select"><option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option></x-operations.field>
                <x-operations.field label="Eingangsordner" model="form.imap_folder" />
                <x-operations.field label="Benutzername" model="form.imap_username" autocomplete="off" />
                <x-operations.field label="Passwort / App-Passwort" model="form.imap_password" type="password" autocomplete="new-password" />
            </div><p class="mt-3 ops-muted">Leere oder unveränderte Passwortfelder behalten das gespeicherte Geheimnis.</p>
        </div>
        <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border" data-ai-staffing-automation>
            <h3 class="mb-3 font-semibold">Personalanfragen</h3>
            <div class="ops-form">
                <x-operations.field label="Automatische Personalanfragen" model="form.staffing_request_mode" type="select">
                    <option value="off">Aus</option><option value="assisted">Vorschläge zur Prüfung</option><option value="automatic">Geeignete Mitarbeiter automatisch anfragen</option>
                </x-operations.field>
                @foreach(['staffing_request_wave_size'=>['Personen je Anfragerunde',1,20],'staffing_request_max_waves'=>['Höchstens Anfragerunden',1,5],'staffing_request_timeout_hours'=>['Antwortfrist in Stunden',1,168],'staffing_request_horizon_days'=>['Zukünftige Dienste bis … Tage',1,90],'staffing_request_min_score'=>['Mindestbewertung für Vorschläge',75,100]] as $key=>[$label,$min,$max])
                    <x-operations.field :label="$label" :model="'form.'.$key" type="number" :min="$min" :max="$max" />
                @endforeach
            </div>
            <p class="mt-3 ops-muted">Für offene Plätze in bereits veröffentlichten Diensten. Die Anfrage erscheint im Mitarbeiterportal und reserviert keinen Platz. Interesse wird gesammelt; Übernahme und Dienstbestätigung folgen dem bestehenden Freigabeablauf. Unklare Eignung und ausgeschöpfte Runden erscheinen im Assistenten und in der Arbeitsliste.</p>
            <p class="mt-2 ops-muted" role="status">{{ $status['staffing_schema_ready'] ? ($status['staffing_mode'] === 'off' ? 'Personalanfragen sind ausgeschaltet.' : ($status['staffing_supervisor_ready'] ? ($status['staffing_mode'] === 'automatic' ? 'Automatische Personalanfragen sind konfiguriert.' : 'Personalanfragen werden zur Prüfung vorbereitet.') : 'Die Berechtigung der verantwortlichen Disposition fehlt.')) : 'Die Datenbankerweiterung für Personalanfragen ist noch nicht eingerichtet.' }}</p>
        </div>
        <div class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border">
            <h3 class="mb-3 font-semibold">Kundenmails · SMTP</h3>
            <div class="ops-form">
                <x-operations.field label="SMTP-Server" model="form.smtp_host" autocomplete="off" />
                <x-operations.field label="Port" model="form.smtp_port" type="number" min="1" max="65535" />
                <x-operations.field label="Verschlüsselung" model="form.smtp_encryption" type="select"><option value="tls">STARTTLS</option><option value="ssl">SSL/TLS</option></x-operations.field>
                <label class="flex min-h-11 items-center gap-3"><input type="checkbox" wire:model.live="form.smtp_same_credentials" class="rounded border-rt-border text-rt-accent focus:ring-rt-accent" />IMAP-Zugang auch für SMTP verwenden</label>
                @if(!$form['smtp_same_credentials'])<x-operations.field label="SMTP-Benutzername" model="form.smtp_username" autocomplete="off" /><x-operations.field label="SMTP-Passwort" model="form.smtp_password" type="password" autocomplete="new-password" />@endif
                <x-operations.field label="Absenderadresse" model="form.from_address" type="email" />
                <x-operations.field label="Absendername" model="form.from_name" />
            </div>
        </div>
        <details class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border">
            <summary class="cursor-pointer font-semibold">Ablauf und Verarbeitungsgrenzen</summary>
            <div class="ops-form mt-4">
                @foreach(['max_rounds'=>['Automatische Rückfragerunden',1,2],'reply_timeout_hours'=>['Prüfaufgabe nach … Stunden ohne Antwort',1,720],'poll_interval_minutes'=>['Postfachabruf alle … Minuten',1,60],'max_messages_per_poll'=>['Nachrichten je Abruf',1,100],'max_attachment_count'=>['Anlagen je Eingang',1,3],'max_total_kilobytes'=>['Anlagen insgesamt (KB)',1,15360],'max_audio_kilobytes'=>['Audio höchstens (KB)',1,8192],'max_ai_calls_per_hour'=>['AI-Aufrufe je Stunde',1,1000]] as $key=>[$label,$min,$max])
                    <x-operations.field :label="$label" :model="'form.'.$key" type="number" :min="$min" :max="$max" />
                @endforeach
                <x-operations.field label="Zusätzliche Hinweise für die Anfrage-Auswertung" model="form.instructions" type="textarea" :wide="true" maxlength="10000" />
            </div>
        </details>
        <x-ui.forms.input-error for="form" />
        <div class="ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled" wire:target="save"><i class="far fa-save" aria-hidden="true"></i>Konfiguration speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="reloadConfiguration" wire:loading.attr="disabled">Neu laden</x-ui.buttons.button-basic><span wire:loading wire:target="save" role="status" class="ops-muted">Wird gespeichert …</span></div>
    </form>
    <section class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border" aria-labelledby="ai-mail-runtime-heading" aria-live="polite">
        <h3 id="ai-mail-runtime-heading" class="font-semibold">Verbindung und Hintergrundverarbeitung</h3>
        <p class="mt-2 ops-muted">{{ $status['enabled'] ? (($runtime['activation_pending'] ?? true) ? 'Aktivierung ausstehend: Der erste Abruf legt den Startpunkt fest.' : 'Verarbeitung aktiviert.') : 'Automatische Verarbeitung ist deaktiviert.' }}</p>
        @if(!$schemaReady)<p class="mt-2 ops-muted">Die Datenbankerweiterung für die AI-Annahme ist noch nicht eingerichtet.</p>@endif
        @if($runtime['worker']['checked_at'] ?? null)<p class="mt-2 ops-muted">Hintergrundtest verarbeitet.</p>@else<p class="mt-2 ops-muted">Hintergrundverarbeitung noch nicht bestätigt.</p>@endif
        @if($runtime['last_poll_at'] ?? null)<p class="mt-2 ops-muted">Letzter Abruf: {{ \Carbon\CarbonImmutable::parse($runtime['last_poll_at'])->timezone('Europe/Berlin')->format('d.m.Y. H:i') }}</p>@endif
        @if($runtime['failure_code'] ?? null)<p class="mt-2 ops-muted">Der letzte Abruf benötigt eine Prüfung der Verbindung.</p>@endif
        <div class="ops-actions mt-3"><x-ui.buttons.button-basic type="button" wire:click="probe" wire:loading.attr="disabled">Gespeicherte Verbindung testen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="probeWorker" wire:loading.attr="disabled">Hintergrundtest</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="pollNow" wire:loading.attr="disabled" :disabled="!$status['enabled']">Jetzt abrufen</x-ui.buttons.button-basic></div>
        <p wire:loading wire:target="probe,pollNow,probeWorker" class="mt-2 ops-muted" role="status">Die Anfrage wird verarbeitet …</p>
        <x-ui.forms.input-error for="connection" /><x-ui.forms.input-error for="runtime" />
        @if($diagnostic)<p class="mt-2 text-sm">IMAP: {{ ($diagnostic['imap']['state'] ?? '') === 'authenticated' ? 'Verbunden' : 'Verbindung prüfen' }} · SMTP: {{ ($diagnostic['smtp']['state'] ?? '') === 'authenticated' ? 'Verbunden' : 'Verbindung prüfen' }}</p>@endif
    </section>
    <details class="rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border">
        <summary class="cursor-pointer font-semibold">Ältere Nachrichten gezielt importieren</summary>
        <p class="mt-3 ops-muted">Der automatische Abruf beginnt mit neuen Nachrichten. Laden Sie eine Vorschau, um ältere Nachrichten ausdrücklich auszuwählen.</p>
        <x-ui.buttons.button-basic type="button" class="mt-3" wire:click="previewHistory" wire:loading.attr="disabled">Letzte 20 Nachrichten ansehen</x-ui.buttons.button-basic>
        @if($historyPreview)<div class="mt-3 space-y-2">@foreach($historyPreview as $message)<label class="flex items-start gap-3 text-sm" wire:key="history-{{ $message['uid'] }}"><input type="checkbox" wire:model="historySelection" value="{{ $message['uid'] }}" class="mt-1 rounded border-rt-border text-rt-accent" /><span class="break-words">{{ $message['subject'] ?: 'Ohne Betreff' }}<small class="block ops-muted">{{ $message['date'] ?? '' }}</small></span></label>@endforeach</div><x-ui.buttons.button-basic type="button" class="mt-3" mode="primary" wire:click="importHistory" wire:confirm="Ausgewählte ältere Nachrichten in die AI-Annahme importieren?" wire:loading.attr="disabled">Auswahl importieren</x-ui.buttons.button-basic>@endif
        <x-ui.forms.input-error for="history" /><x-ui.forms.input-error for="historySelection" />
    </details>
</section>
