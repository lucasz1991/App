@if($data['stats']['available'])
    @php($total = max(1, $data['stats']['total']))
    <span class="widget-primary-val">{{ $data['stats']['total'] }}</span>
    <span class="widget-primary-lbl">Geräte in der Flotte</span>
    @if($rows === 2)
        <div class="widget-segment-bar widget-detail">
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
    @if($data['href'])<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Geräte & Lager öffnen →</a>@endif
@else
    <p class="ops-empty" style="padding:16px 0;">Keine Gerätedaten verfügbar.</p>
@endif
