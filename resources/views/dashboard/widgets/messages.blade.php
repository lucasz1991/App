<span class="widget-primary-val">{{ $data['unread'] }}</span>
<span class="widget-primary-lbl">ungelesen</span>
@if($rows === 2)
    <div class="widget-detail">
        <x-dashboard.sparkline :values="$data['sparkline']" :labels="$data['sparklineLabels']" />
        <p class="ops-muted" style="margin-top:4px;">Eingang, letzte 7 Tage</p>
    </div>
    <div class="widget-detail">
        @forelse($data['latest'] as $message)
            <div class="ops-row"><span>{{ $message->subject }}</span><span class="ops-muted">{{ $message->sender?->name }}</span></div>
        @empty
            <div class="ops-empty">Keine Nachrichten.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Posteingang öffnen →</a>
