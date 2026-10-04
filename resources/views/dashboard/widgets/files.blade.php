{{-- Dateityp-Chips: was in der Ablage liegt (PDF, Dokumente, Tabellen, Bilder) und wie viel Platz es belegt. --}}
@php
    $tags = ['pdf' => 'PDF', 'doc' => 'DOC', 'sheet' => 'XLS', 'image' => 'IMG', 'other' => 'DAT'];
    $names = ['pdf' => 'PDF-Dokumente', 'doc' => 'Textdokumente', 'sheet' => 'Tabellen', 'image' => 'Bilder', 'other' => 'Sonstige'];
    $types = collect($data['byType'])->sortDesc();
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['total'] }}</span>
    <span class="widget-primary-lbl">{{ $data['total'] === 1 ? 'Datei verfügbar' : 'Dateien verfügbar' }}</span>
    @if($data['totalBytes'] > 0)
        <span class="wv-pill"><i data-feather="hard-drive"></i>{{ \App\Support\Dashboard\SystemDashboardData::formatBytes($data['totalBytes']) }}</span>
    @endif
</div>
@if($types->isNotEmpty())
    <div class="wv-chips">
        @foreach($types->take($rows === 2 ? 5 : 3) as $type => $count)
            <span class="wv-chip" title="{{ $count }} {{ $names[$type] }}"><span class="wv-filetag" data-type="{{ $type }}">{{ $tags[$type] }}</span>{{ $count }}</span>
        @endforeach
        @if($rows === 1 && $types->count() > 3)
            <span class="wv-chip" style="padding-left:9px;">+{{ $types->slice(3)->sum() }}</span>
        @endif
    </div>
@elseif($rows === 1)
    <x-dashboard.empty icon="file">Noch keine Dateien für dich bereitgestellt.</x-dashboard.empty>
@endif
@if($rows === 2)
    <div class="widget-detail">
        <p class="wv-label">Zuletzt bereitgestellt</p>
        <div class="wv-list">
            @forelse($data['recent'] as $file)
                @php($type = \App\Support\Dashboard\WidgetDataProvider::fileCategory($file->mime_type, $file->name))
                <div class="wv-item">
                    <span class="wv-filetag" data-type="{{ $type }}">{{ $tags[$type] }}</span>
                    <span class="wv-item-main"><span class="wv-truncate">{{ $file->name ?? 'Datei' }}</span><small>{{ $file->created_at?->translatedFormat('d.m.Y') }}@if($file->size) · {{ \App\Support\Dashboard\SystemDashboardData::formatBytes((int) $file->size) }}@endif</small></span>
                </div>
            @empty
                <x-dashboard.empty icon="file">Keine Dateien.</x-dashboard.empty>
            @endforelse
        </div>
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Download-Center öffnen →</a>
