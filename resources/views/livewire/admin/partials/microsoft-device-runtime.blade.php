@php
    $schemaReady = (bool) ($runtimeStatus['schema_ready'] ?? false);
    $queueReady = (bool) ($runtimeStatus['queue_ready'] ?? false);
    $scheduler = (array) ($runtimeStatus['scheduler'] ?? []);
    $worker = (array) ($runtimeStatus['worker'] ?? []);
    $run = (array) ($runtimeStatus['run'] ?? []);
    $workerProbe = (array) ($runtimeStatus['worker_probe'] ?? []);
    $schedulerLabel = match ($scheduler['state'] ?? 'unknown') {
        'fresh' => 'Aktueller Kontakt',
        'stale' => 'Kontakt überfällig',
        default => 'Noch kein Kontakt',
    };
    $workerLabel = match ($worker['state'] ?? 'unknown') {
        'busy' => 'Verarbeitet einen Auftrag',
        'seen' => 'Ausführung nachgewiesen',
        'failed' => 'Letzte Ausführung fehlgeschlagen',
        default => 'Kein aktueller Ausführungsnachweis',
    };
    $runLabel = match ($run['status'] ?? '') {
        'queued' => 'Wartet auf Worker',
        'running' => 'Geräteabgleich läuft',
        'completed' => 'Abgeschlossen',
        'failed' => 'Abgebrochen oder fehlgeschlagen',
        default => 'Noch kein Geräteauftrag',
    };
    $probeLabel = match ($workerProbe['status'] ?? 'unknown') {
        'queued' => 'Test wartet auf Worker',
        'running' => 'Worker verarbeitet den Test',
        'completed' => filled($workerProbe['acknowledged_at'] ?? null) ? 'Worker hat den Test verarbeitet' : 'Ausführungsbestätigung fehlt',
        'failed' => 'Hintergrundtest fehlgeschlagen',
        default => 'Noch kein Hintergrundtest',
    };
    $formatRuntimeDate = static function ($value): string {
        if (! is_string($value) || $value === '') {
            return 'Noch kein Nachweis';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('d.m.Y. H:i:s');
        } catch (\Throwable) {
            return 'Zeitpunkt nicht verfügbar';
        }
    };
@endphp

<section
    class="min-w-0 space-y-4 rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
    aria-labelledby="microsoft-runtime-title"
    data-microsoft-device-runtime
    @if ($runtimePolling) wire:poll.visible.10s="$refresh" @endif
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 id="microsoft-runtime-title" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Betrieb und Hintergrundverarbeitung</h3>
            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $active ? 'Automatischer Geräteabgleich ist eingeschaltet.' : 'Automatischer Geräteabgleich ist ausgeschaltet.' }} Die folgenden Nachweise zeigen, ob der Server Aufträge tatsächlich verarbeiten kann.</p>
        </div>
        <x-ui.buttons.button-basic type="button" size="sm" mode="secondary" class="min-h-11" wire:click="refreshRuntime" wire:loading.attr="disabled" wire:target="refreshRuntime"><i class="far fa-sync" aria-hidden="true"></i>Status aktualisieren</x-ui.buttons.button-basic>
    </div>

    <dl class="grid min-w-0 gap-3 sm:grid-cols-2" aria-live="polite">
        @foreach ([
            ['Datenbankschema', $schemaReady ? 'Vollständig eingerichtet' : 'Migrationen fehlen oder Datenbank nicht erreichbar', null],
            ['Warteschlange', $queueReady ? 'Erreichbar und korrekt konfiguriert' : 'Noch nicht bereit', null],
            ['Schedulerkontakt', $schedulerLabel, $formatRuntimeDate($scheduler['checked_at'] ?? null)],
            ['Worker-Ausführung', $workerLabel, $formatRuntimeDate($worker['checked_at'] ?? null)],
        ] as [$label, $value, $timestamp])
            <div class="min-w-0 rounded-lg bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted">
                <dt class="text-xs font-medium text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt>
                <dd class="mt-1 break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $value }}</dd>
                @if ($timestamp)<dd class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $timestamp }}</dd>@endif
            </div>
        @endforeach
    </dl>

    @if (($runtimeStatus['issues'] ?? []) !== [])
        <ul class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-950 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100" aria-label="Offene Betriebsvoraussetzungen" role="status">
            @foreach ($runtimeStatus['issues'] as $issue)
                <li class="break-words" wire:key="microsoft-runtime-issue-{{ $issue['code'] }}">{{ $issue['message'] }}</li>
            @endforeach
        </ul>
    @endif
    <x-input-error for="runtime" />
    @if ($runtimeStatus['overdue'] ?? false)
        <p role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-950 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">Ein Hintergrundauftrag ist überfällig. Prüfen Sie Schedulerkontakt, Worker-Ausführung, Geräteauftrag und Hintergrundtest.</p>
    @endif

    <div class="space-y-3 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70" aria-live="polite">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h4 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Hintergrundtest: {{ $probeLabel }}</h4>
                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Der Test läuft ohne Microsoft-Zugriff und ohne Geräteänderung. Erst die Rückmeldung des Workers bestätigt die Verarbeitung.</p>
            </div>
            <x-ui.buttons.button-basic type="button" mode="secondary" class="min-h-11" wire:click="testBackgroundProcessing" wire:loading.attr="disabled" wire:target="testBackgroundProcessing,syncNow,save,testConnection" :disabled="! $queueReady || in_array($workerProbe['status'] ?? '', ['queued', 'running'], true) ? true : null"><i class="far fa-cogs" aria-hidden="true"></i>Hintergrundverarbeitung testen</x-ui.buttons.button-basic>
        </div>
        @if (filled($workerProbe['queued_at'] ?? null))<p class="text-xs text-rt-muted dark:text-rt-dark-muted">Eingeplant: {{ $formatRuntimeDate($workerProbe['queued_at']) }}</p>@endif
        @if (filled($workerProbe['acknowledged_at'] ?? null))<p class="text-xs text-rt-muted dark:text-rt-dark-muted">Vom Worker bestätigt: {{ $formatRuntimeDate($workerProbe['acknowledged_at']) }}</p>@endif
    </div>

    <div class="space-y-3 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70" aria-live="polite">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h4 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Geräteauftrag: {{ $runLabel }}</h4>
                @if (filled($run['message'] ?? null))<p class="mt-1 break-words text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $run['message'] }}</p>@endif
            </div>
            <x-ui.buttons.button-basic type="button" mode="secondary" class="min-h-11" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow,save,testConnection,testBackgroundProcessing" :disabled="! $schemaReady || ! $queueReady || ! $active || in_array($run['status'] ?? '', ['queued', 'running'], true) ? true : null"><i class="far fa-sync" aria-hidden="true"></i>Jetzt synchronisieren</x-ui.buttons.button-basic>
        </div>
        @if ($run !== [])
            <dl class="flex flex-wrap gap-x-5 gap-y-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                @foreach (['queued_at' => 'Eingeplant', 'started_at' => 'Gestartet', 'finished_at' => 'Beendet'] as $key => $label)
                    @if (filled($run[$key] ?? null))<div><dt>{{ $label }}</dt><dd class="mt-0.5">{{ $formatRuntimeDate($run[$key]) }}</dd></div>@endif
                @endforeach
            </dl>
        @endif
        @if (! $active)<p class="text-xs text-rt-muted dark:text-rt-dark-muted">Zum Abrufen von Geräten die Microsoft-Verbindung oben aktivieren und speichern. Der Hintergrundtest ist auch ohne Microsoft-Konfiguration möglich.</p>@endif
    </div>

    @if ($runtimePolling)
        <p class="text-xs text-rt-muted dark:text-rt-dark-muted" data-runtime-polling>Der Auftragsstatus wird alle 10 Sekunden aktualisiert, höchstens zwei Minuten lang.</p>
    @elseif ($runtimePending)
        <p class="text-xs text-rt-muted dark:text-rt-dark-muted" data-runtime-polling-paused>Die automatische Statusabfrage ist pausiert; der Auftrag kann im Hintergrund weiterlaufen. Mit „Status aktualisieren“ erneut prüfen.</p>
    @endif

    @if ($lastRun !== [])
        <div class="space-y-3 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70">
            <h4 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Letztes gespeichertes Importergebnis</h4>
            <p class="break-words text-sm text-rt-text dark:text-rt-dark-text">{{ $lastRun['message'] }} <span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $formatRuntimeDate($connectionStatus['last_sync_at'] ?? null) }}</span></p>
            @if (isset($lastRun['intune_message']) && $lastRun['intune_status'] !== 'success')<p class="break-words text-sm text-rt-muted dark:text-rt-dark-muted"><span class="font-medium">Intune:</span> {{ $lastRun['intune_message'] }}</p>@endif
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach (['discovered' => 'Gefunden', 'created' => 'Neu erfasst', 'updated' => 'Aktualisiert', 'assigned' => 'Zugeordnet', 'conflicts' => 'Zu prüfen', 'skipped' => 'Übersprungen'] as $key => $label)
                    @if (array_key_exists($key, $lastRun))<div class="rounded-lg bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted"><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-rt-text dark:text-rt-dark-text">{{ $lastRun[$key] }}</dd></div>@endif
                @endforeach
            </dl>
        </div>
    @endif
</section>
