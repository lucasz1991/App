<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['pending'] === 0 ? 'ok' : 'warn' }}"><i data-feather="image"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['pending'] }}</span>
        <span class="ops-kpi-lbl">Motive warten auf Freigabe</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $creative)
            <div class="ops-row"><span>{{ $creative['title'] }}</span><span class="ops-muted">{{ $creative['typeLabel'] }}</span></div>
        @empty
            <div class="ops-empty">Nichts wartet auf Freigabe.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Marketing-Motive öffnen →</a>
