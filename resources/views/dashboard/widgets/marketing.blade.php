{{-- Typ-Kacheln: Entwuerfe je Motivart nebeneinander, dazu die Freigaben der letzten 30 Tage. --}}
<div class="wv-duo">
    <div class="wv-duo-tile">
        <span class="wv-item-ico ops-tone-brand"><i data-feather="briefcase"></i></span>
        <div class="wv-duo-text"><b>{{ (int) ($data['byType']['job'] ?? 0) }}</b><span>Stellenanzeigen</span></div>
    </div>
    <div class="wv-duo-tile">
        <span class="wv-item-ico ops-tone-neutral"><i data-feather="info"></i></span>
        <div class="wv-duo-text"><b>{{ (int) ($data['byType']['info'] ?? 0) }}</b><span>Info-Motive</span></div>
    </div>
</div>
<p class="wv-sub wv-truncate" title="{{ $data['pending'] }} warten auf Freigabe, {{ $data['approvedRecently'] }} in den letzten 30 Tagen freigegeben"><strong>{{ $data['pending'] }}</strong> zur Freigabe · {{ $data['approvedRecently'] }} freigegeben (30 T)</p>
@if($rows === 2)
    <div class="widget-detail wv-list">
        @forelse($data['recent'] as $creative)
            <div class="wv-item">
                <span class="wv-item-ico {{ $creative['typeLabel'] === 'Stellenanzeige' ? 'ops-tone-brand' : 'ops-tone-neutral' }}"><i data-feather="{{ $creative['typeLabel'] === 'Stellenanzeige' ? 'briefcase' : 'info' }}"></i></span>
                <span class="wv-item-main"><span class="wv-truncate">{{ $creative['title'] }}</span><small>{{ $creative['typeLabel'] }} · {{ $creative['when']?->diffForHumans() }}</small></span>
            </div>
        @empty
            <x-dashboard.empty icon="check-circle">Nichts wartet auf Freigabe.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Marketing-Motive öffnen →</a>
