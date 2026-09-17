@if(! $systemStatusLoaded)
    <p class="ops-muted">Version, Umgebung, Datenbank, Speicher – erst beim Öffnen geladen.</p>
    <button type="button" class="ops-link" wire:click="loadSystemStatus" style="margin-top:8px;">Systemdaten anzeigen →</button>
@else
    <dl class="ops-meta">
        @foreach(['appVersion' => 'Anwendung', 'environment' => 'Umgebung', 'php' => 'PHP', 'database' => 'Datenbank', 'queue' => 'Queue', 'lastActivity' => 'Letzte Aktivität', 'storage' => 'Dateispeicher', 'disk' => 'Datenträger'] as $key => $label)
            @if($size === 'lg' || in_array($key, ['environment', 'database'], true))
                <div><dt>{{ $label }}</dt><dd>{{ $systemStatus[$key] ?? '—' }}</dd></div>
            @endif
        @endforeach
    </dl>
@endif
