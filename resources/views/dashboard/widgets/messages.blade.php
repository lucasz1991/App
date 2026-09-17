<p class="ops-kpi-val" style="font-size:28px;">{{ $data['unread'] }}</p>
<p class="ops-muted">ungelesen</p>
@if($size === 'lg')
    <div style="margin-top:10px;">
        @forelse($data['latest'] as $message)
            <div class="ops-row"><span>{{ $message->subject }}</span><span class="ops-muted">{{ $message->sender?->name }}</span></div>
        @empty
            <div class="ops-empty">Keine Nachrichten.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate>Posteingang öffnen →</a>
