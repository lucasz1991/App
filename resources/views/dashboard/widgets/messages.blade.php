{{-- Posteingang: wie viel UND was ungelesen ist; jeder Eintrag oeffnet direkt die Nachricht. --}}
@php
    $open = fn ($message) => route('messages', ['open' => $message->id]);
    $weekTotal = array_sum($data['sparkline']);
@endphp
<div class="wv-inline">
    <span class="widget-primary-val">{{ $data['unread'] }}</span>
    <span class="widget-primary-lbl">ungelesen</span>
    @if($data['unread'] === 0)
        <span class="wv-pill ops-tone-ok"><i data-feather="check"></i>Alles gelesen</span>
    @else
        <span class="wv-pill" title="Eingang in den letzten 7 Tagen"><i data-feather="inbox"></i>{{ $weekTotal }} in 7 T</span>
    @endif
</div>
@if($rows === 1)
    @if($data['newestUnread'])
        <a class="wv-resume" href="{{ $open($data['newestUnread']) }}" wire:navigate>
            <x-dashboard.avatar :user="$data['newestUnread']->sender" :size="26" />
            <span class="wv-item-main">
                <span class="wv-truncate" style="font-weight:700;">{{ $data['newestUnread']->subject ?: 'Ohne Betreff' }}</span>
                <small class="wv-truncate">{{ $data['newestUnread']->sender?->name ?? 'System' }} · {{ $data['newestUnread']->created_at?->diffForHumans() }}</small>
            </span>
        </a>
    @else
        <div class="wv-gap"><x-dashboard.sparkline :values="$data['sparkline']" :labels="$data['sparklineLabels']" /></div>
    @endif
@else
    <div class="widget-detail" style="overflow:visible;">
        <x-dashboard.sparkline :values="$data['sparkline']" :labels="$data['sparklineLabels']" />
        <p class="wv-sub" style="margin-top:4px;">Eingang, letzte 7 Tage · heute {{ last($data['sparkline']) }}</p>
    </div>
    <div class="widget-detail wv-list">
        @forelse($data['latest'] as $message)
            <a class="wv-item" href="{{ $open($message) }}" wire:navigate @if((int) $message->status === 1) data-unread @endif>
                <x-dashboard.avatar :user="$message->sender" :size="28" />
                <span class="wv-item-main">
                    <span class="wv-truncate">{{ $message->subject ?: 'Ohne Betreff' }}</span>
                    <small class="wv-truncate">{{ $message->sender?->name ?? 'System' }} · {{ $message->created_at?->diffForHumans() }}</small>
                </span>
                @if((int) $message->status === 1)<span class="wv-unread-dot" title="Ungelesen"></span>@endif
            </a>
        @empty
            <x-dashboard.empty icon="message-circle">Keine Nachrichten.</x-dashboard.empty>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Posteingang öffnen →</a>
