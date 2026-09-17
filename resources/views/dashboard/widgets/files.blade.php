<p class="ops-kpi-val" style="font-size:28px;">{{ $data['total'] }}</p>
<p class="ops-muted">Dateien verfügbar</p>
@if($size === 'lg')
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $file)
            <div class="ops-row"><span>{{ $file->name ?? $file->title ?? 'Datei' }}</span></div>
        @empty
            <div class="ops-empty">Keine Dateien.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate>Download-Center öffnen →</a>
