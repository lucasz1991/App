<p class="ops-kpi-val" style="font-size:28px;">{{ $data['open'] }}</p>
<p class="ops-muted">{{ $data['scope'] === 'team' ? 'offene Fälle im Team' : 'eigene offene Fälle' }}</p>
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
