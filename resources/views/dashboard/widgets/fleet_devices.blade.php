@if($data['stats']['available'])
    <p class="ops-kpi-val" style="font-size:28px;">{{ $data['stats']['total'] }}</p>
    <p class="ops-muted">Geräte in der Flotte</p>
    @if($rows === 2)
        <dl class="ops-meta" style="margin-top:10px;">
            <div><dt>Zugewiesen</dt><dd>{{ $data['stats']['assigned'] }}</dd></div>
            <div><dt>Im Lager</dt><dd>{{ $data['stats']['inventory'] }}</dd></div>
            <div><dt>Benötigt Aufmerksamkeit</dt><dd>{{ $data['stats']['attention'] }}</dd></div>
        </dl>
    @endif
    @if($data['href'])<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Geräte & Lager öffnen →</a>@endif
@else
    <p class="ops-empty" style="padding:16px 0;">Keine Gerätedaten verfügbar.</p>
@endif
