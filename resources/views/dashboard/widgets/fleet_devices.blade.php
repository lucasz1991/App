@if($data['stats']['available'])
    @php($total = max(1, $data['stats']['total']))
    <div class="widget-stat">
        <span class="ops-kpi-ico ops-tone-{{ $data['stats']['attention'] > 0 ? 'warn' : 'ok' }}"><i data-feather="monitor"></i></span>
        <span>
            <span class="ops-kpi-val">{{ $data['stats']['total'] }}</span>
            <span class="ops-kpi-lbl">Geräte in der Flotte</span>
        </span>
    </div>
    @if($rows === 2)
        <div class="widget-segment-bar" style="margin-top:14px;">
            <span class="widget-segment-ok" style="width:{{ $data['stats']['assigned'] / $total * 100 }}%"></span>
            <span class="widget-segment-neutral" style="width:{{ $data['stats']['inventory'] / $total * 100 }}%"></span>
            <span class="widget-segment-warn" style="width:{{ $data['stats']['attention'] / $total * 100 }}%"></span>
        </div>
        <div class="widget-segment-legend">
            <span><i class="widget-segment-ok"></i>Zugewiesen {{ $data['stats']['assigned'] }}</span>
            <span><i class="widget-segment-neutral"></i>Im Lager {{ $data['stats']['inventory'] }}</span>
            <span><i class="widget-segment-warn"></i>Aufmerksamkeit {{ $data['stats']['attention'] }}</span>
        </div>
    @endif
    @if($data['href'])<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Geräte & Lager öffnen →</a>@endif
@else
    <p class="ops-empty" style="padding:16px 0;">Keine Gerätedaten verfügbar.</p>
@endif
