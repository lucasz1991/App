{{-- Ablauf-Countdown: Pruef-Warteschlange plus genehmigte Nachweise, die in 60 Tagen ablaufen. --}}
@php
    $urgency = fn (int $days) => $days <= 14 ? 'ops-tone-brand' : ($days <= 30 ? 'ops-tone-warn' : 'ops-tone-neutral');
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['count'] }}</span>
    <span class="widget-primary-lbl">zur Prüfung</span>
    @if($data['expiredCount'] > 0)
        <span class="wv-pill ops-tone-warn"><i data-feather="alert-triangle"></i>{{ $data['expiredCount'] }} abgelaufen</span>
    @endif
</div>
@if($rows === 1)
    <div class="wv-countdown">
        @forelse($data['expiring'] as $qualification)
            <span class="wv-days {{ $urgency($qualification['daysLeft']) }}" title="{{ $qualification['name'] }} · {{ $qualification['type'] }}"><b>{{ $qualification['daysLeft'] }}</b><small>Tage</small></span>
        @empty
            <span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Keine Abläufe in 60 Tagen</span>
        @endforelse
        @if($data['expiringCount'] > 0)
            <span class="wv-sub" style="margin:0;">{{ $data['expiringCount'] }} {{ $data['expiringCount'] === 1 ? 'läuft' : 'laufen' }} in 60 T ab</span>
        @endif
    </div>
@else
    <div class="widget-detail">
        <p class="wv-label">Läuft bald ab</p>
        <div class="wv-list">
            @forelse($data['expiring'] as $qualification)
                <div class="wv-item">
                    <span class="wv-days {{ $urgency($qualification['daysLeft']) }}"><b>{{ $qualification['daysLeft'] }}</b><small>Tage</small></span>
                    <span class="wv-item-main"><span class="wv-truncate">{{ $qualification['name'] }}</span><small class="wv-truncate">{{ $qualification['type'] }}</small></span>
                </div>
            @empty
                <div class="wv-item"><span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Keine Abläufe in den nächsten 60 Tagen</span></div>
            @endforelse
        </div>
        <p class="wv-label wv-gap">Zur Prüfung eingereicht</p>
        <div class="wv-list">
            @forelse($data['items']->take(3) as $item)
                <div class="wv-item">
                    <span class="wv-item-ico ops-tone-warn"><i data-feather="file-text"></i></span>
                    <span class="wv-item-main"><span class="wv-truncate">{{ $item['title'] }}</span><small class="wv-truncate">{{ $item['meta'] }} · {{ $item['when']?->diffForHumans() }}</small></span>
                </div>
            @empty
                <div class="wv-item"><span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Nichts zu prüfen</span></div>
            @endforelse
        </div>
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Nachweise öffnen →</a>
