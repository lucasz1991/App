<span class="widget-primary-val">{{ $data['total'] }}</span>
<span class="widget-primary-lbl">Dateien verfügbar</span>
@if($rows === 2)
    <div class="widget-detail">
        @forelse($data['recent'] as $file)
            <div class="ops-row"><span>{{ $file->name ?? $file->title ?? 'Datei' }}</span></div>
        @empty
            <div class="ops-empty">Keine Dateien.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Download-Center öffnen →</a>
