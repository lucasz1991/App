<div class="widget-stat">
    <div class="widget-ring" style="--ring-pct:{{ $data['completion'] }};">
        <span class="widget-ring-val">{{ $data['completion'] }}%</span>
    </div>
    <span>
        <span class="ops-kpi-lbl">Profil-Vollständigkeit</span>
        <span class="ops-muted">{{ $data['completion'] === 100 ? 'Vollständig ausgefüllt' : 'Noch nicht vollständig' }}</span>
    </span>
</div>
@if($rows === 2)
    <ul class="widget-detail" style="padding:0;list-style:none;display:grid;gap:6px;">
        @foreach($data['checks'] as $key => $done)
            @continue($done)
            <li class="ops-muted" style="display:flex;align-items:center;gap:8px;">
                <span style="width:6px;height:6px;border-radius:2px;background:var(--ops-signal);flex-shrink:0;"></span>
                {{ ['phone' => 'Telefon', 'mobile' => 'Mobilnummer', 'position' => 'Position', 'profile_photo' => 'Profilbild'][$key] ?? $key }} fehlt
            </li>
        @endforeach
    </ul>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Profil öffnen →</a>
