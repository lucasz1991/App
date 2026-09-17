<p class="ops-kpi-val" style="font-size:30px;">{{ $data['count'] }}</p>
<p class="ops-muted">{{ $data['label'] }}</p>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $order)
            <div class="ops-row"><span>{{ $order->title }}</span><span class="ops-muted">{{ $order->customer?->company_name }}</span></div>
        @empty
            <div class="ops-empty">Keine offenen Leistungen.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Leistungen öffnen →</a>
