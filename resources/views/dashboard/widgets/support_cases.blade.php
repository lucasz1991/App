<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['open'] === 0 ? 'ok' : 'warn' }}"><i data-feather="life-buoy"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['open'] }}</span>
        <span class="ops-kpi-lbl">{{ $data['scope'] === 'team' ? 'offene Fälle im Team' : 'eigene offene Fälle' }}</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['cases'] as $case)
            <div class="ops-row"><span>{{ \Illuminate\Support\Str::limit($case->subject, 40) }}</span><span class="ops-badge">{{ str($case->status)->replace('_', ' ')->title() }}</span></div>
        @empty
            <div class="ops-empty">Keine offenen Fälle.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">IT-Support öffnen →</a>
