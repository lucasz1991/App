<div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;">
    <span class="ops-muted">Vollständigkeit</span>
    <span class="ops-kpi-val" style="font-size:20px;">{{ $data['completion'] }} %</span>
</div>
<div style="height:6px;border-radius:999px;background:var(--ops-platform);overflow:hidden;margin-top:8px;">
    <div style="height:100%;border-radius:999px;background:var(--ops-signal);width:{{ max($data['completion'], 4) }}%;"></div>
</div>
@if($rows === 2)
    <ul style="margin:10px 0 0;padding:0;list-style:none;display:grid;gap:6px;">
        @foreach($data['checks'] as $key => $done)
            @continue($done)
            <li class="ops-muted" style="display:flex;align-items:center;gap:8px;">
                <span style="width:6px;height:6px;border-radius:2px;background:var(--ops-signal);flex-shrink:0;"></span>
                {{ ['phone' => 'Telefon', 'mobile' => 'Mobilnummer', 'position' => 'Position', 'profile_photo' => 'Profilbild'][$key] ?? $key }} fehlt
            </li>
        @endforeach
    </ul>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Profil öffnen →</a>
