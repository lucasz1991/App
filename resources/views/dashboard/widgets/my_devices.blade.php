@if($data['stats']['available'])
    @php($total = max(1, $data['stats']['total']))
    <div class="widget-stat">
        <span class="ops-kpi-ico ops-tone-{{ $data['stats']['blocked'] > 0 ? 'warn' : 'ok' }}"><i data-feather="smartphone"></i></span>
        <span>
            <span class="ops-kpi-val">{{ $data['stats']['total'] }}</span>
            <span class="ops-kpi-lbl">eigene Geräte</span>
        </span>
    </div>
    @if($rows === 2)
        <div class="widget-segment-bar" style="margin-top:14px;">
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
    @if($data['href'])<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Meine Geräte öffnen →</a>@endif
@else
    <p class="ops-empty" style="padding:16px 0;">Keine eigenen Geräte zugewiesen.</p>
@endif
