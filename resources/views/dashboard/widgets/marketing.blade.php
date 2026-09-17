<p class="ops-kpi-val" style="font-size:30px;">{{ $data['pending'] }}</p>
<p class="ops-muted">Motive warten auf Freigabe</p>
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
