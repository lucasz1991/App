<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['unread'] === 0 ? 'ok' : 'warn' }}"><i data-feather="message-circle"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['unread'] }}</span>
        <span class="ops-kpi-lbl">ungelesen</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['latest'] as $message)
            <div class="ops-row"><span>{{ $message->subject }}</span><span class="ops-muted">{{ $message->sender?->name }}</span></div>
        @empty
            <div class="ops-empty">Keine Nachrichten.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate>Posteingang öffnen →</a>
