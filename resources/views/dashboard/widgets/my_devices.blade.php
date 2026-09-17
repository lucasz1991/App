@if($data['stats']['available'])
    @php($total = max(1, $data['stats']['total']))
    <span class="widget-primary-val">{{ $data['stats']['total'] }}</span>
    <span class="widget-primary-lbl">eigene Geräte</span>
    @if($rows === 2)
        <div class="widget-segment-bar widget-detail">
            <span class="widget-segment-ok" style="width:{{ $data['stats']['ready'] / $total * 100 }}%"></span>
            <span class="widget-segment-neutral" style="width:{{ $data['stats']['pending'] / $total * 100 }}%"></span>
            <span class="widget-segment-warn" style="width:{{ $data['stats']['blocked'] / $total * 100 }}%"></span>
        </div>
        <div class="widget-segment-legend">
            <span><i class="widget-segment-ok"></i>Bereit {{ $data['stats']['ready'] }}</span>
            <span><i class="widget-segment-neutral"></i>Ausstehend {{ $data['stats']['pending'] }}</span>
            <span><i class="widget-segment-warn"></i>Blockiert {{ $data['stats']['blocked'] }}</span>
        </div>
    @endif
    @if($data['href'])<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Meine Geräte öffnen →</a>@endif
@else
    <p class="ops-empty" style="padding:16px 0;">Keine eigenen Geräte zugewiesen.</p>
@endif
