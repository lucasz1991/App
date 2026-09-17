<p class="ops-kpi-val" style="font-size:30px;">{{ $data['active'] }}</p>
<p class="ops-muted">aktive Kunden von {{ $data['total'] }} gesamt</p>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $customer)
            <div class="ops-row"><span>{{ $customer->company_name }}</span><span class="ops-muted">{{ $customer->city }}</span></div>
        @empty
            <div class="ops-empty">Keine Kunden.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Kundendatenbank öffnen →</a>
