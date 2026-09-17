<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-brand"><i data-feather="download-cloud"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['total'] }}</span>
        <span class="ops-kpi-lbl">Dateien verfügbar</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $file)
            <div class="ops-row"><span>{{ $file->name ?? $file->title ?? 'Datei' }}</span></div>
        @empty
            <div class="ops-empty">Keine Dateien.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate>Download-Center öffnen →</a>
