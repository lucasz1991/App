<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-brand"><i data-feather="clipboard"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['count'] }}</span>
        <span class="ops-kpi-lbl">{{ $data['label'] }}</span>
    </span>
</div>
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
