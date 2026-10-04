{{-- Ampelliste: jeder Systembaustein mit Zustandspunkt, dazu die Belegung des Datentraegers. --}}
@if(! $systemStatusLoaded)
    <x-dashboard.empty icon="activity">Datenbank, Warteschlange, Umgebung und Speicher werden erst auf Abruf geprüft.</x-dashboard.empty>
    <button type="button" class="widget-footer" wire:click="loadSystemStatus">Systemdaten anzeigen →</button>
@else
    @php
        $status = $systemStatus;
        $failed = $status['failedJobs'] ?? null;
        $debugInProduction = ($status['environment'] ?? null) === 'production' && ($status['debug'] ?? false);
        $checks = [
            ['Datenbank', $status['database'] ?? '—', ($status['databaseOk'] ?? false) ? 'ok' : 'unknown'],
            ['Warteschlange', $status['queue'] ?? '—', $failed === null ? 'unknown' : ($failed > 0 ? 'warn' : 'ok')],
            ['Umgebung', ($status['environment'] ?? '—').(($status['debug'] ?? false) ? ' · Debug an' : ''), $debugInProduction ? 'warn' : 'ok'],
            ['Anwendung', $status['appVersion'] ?? '—', 'ok'],
            ['PHP', $status['php'] ?? '—', 'ok'],
            ['Letzte Aktivität', $status['lastActivity'] ?? '—', ($status['lastActivity'] ?? '—') === '—' ? 'unknown' : 'ok'],
        ];
        $disk = $status['diskUsedPct'] ?? null;
    @endphp
    <div class="wv-health">
        @foreach(array_slice($checks, 0, $rows === 2 ? 6 : 3) as [$label, $value, $state])
            <div class="wv-health-row" data-state="{{ $state }}">
                <i role="img" aria-label="{{ ['ok' => 'in Ordnung', 'warn' => 'prüfen', 'unknown' => 'unbekannt'][$state] }}"></i>
                <span>{{ $label }}</span>
                <span title="{{ $value }}">{{ $value }}</span>
            </div>
        @endforeach
    </div>
    @if($rows === 2)
        <div class="widget-detail">
            <p class="wv-label">Speicher</p>
            @if($disk !== null)
                <div class="wv-meter" data-state="{{ $disk >= 85 ? 'warn' : 'ok' }}" role="img" aria-label="Datenträger zu {{ $disk }} Prozent belegt"><span style="width:{{ $disk }}%;"></span></div>
                <p class="wv-sub"><strong>{{ $disk }} %</strong> belegt · frei / gesamt: {{ $status['disk'] ?? '—' }}</p>
            @endif
            <p class="wv-sub">Dateiablage: {{ $status['storage'] ?? '—' }}</p>
        </div>
    @endif
@endif
