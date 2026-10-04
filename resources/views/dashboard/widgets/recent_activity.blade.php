{{-- Praesenzpunkte: wer gerade online ist (gruen), heute da war (gelb) oder laenger weg ist (grau). --}}
@php
    $onlineSince = now()->subMinutes(5);
    $presence = fn ($seen) => $seen->gte($onlineSince) ? 'online' : ($seen->isToday() ? 'today' : 'away');
@endphp
<div class="wv-inline">
    <span class="wv-live" @if($data['online'] === 0) data-idle @endif><i></i>{{ $data['online'] }} gerade online</span>
    <span class="wv-pill">{{ $data['today'] }} heute aktiv</span>
</div>
@if($data['entries']->isEmpty())
    <x-dashboard.empty icon="activity">Noch keine Aktivität.</x-dashboard.empty>
@elseif($rows === 1)
    <div class="wv-presence-row">
        @foreach($data['entries'] as $entry)
            <span class="wv-presence-person" title="{{ $entry['user']?->name }} · {{ $entry['lastSeen']->diffForHumans() }}">
                <span class="wv-presence" data-presence="{{ $presence($entry['lastSeen']) }}"><x-dashboard.avatar :user="$entry['user']" :size="32" /></span>
                <span>{{ \Illuminate\Support\Str::before((string) $entry['user']?->name, ' ') ?: $entry['user']?->name }}</span>
            </span>
        @endforeach
    </div>
@else
    <div class="widget-detail wv-list">
        @foreach($data['entries'] as $entry)
            @php($state = $presence($entry['lastSeen']))
            <div class="wv-item">
                <span class="wv-presence" data-presence="{{ $state }}"><x-dashboard.avatar :user="$entry['user']" :size="30" /></span>
                <span class="wv-item-main"><span class="wv-truncate">{{ $entry['user']?->name }}</span><small>{{ $state === 'online' ? 'Gerade aktiv' : $entry['lastSeen']->diffForHumans() }}</small></span>
            </div>
        @endforeach
    </div>
@endif
