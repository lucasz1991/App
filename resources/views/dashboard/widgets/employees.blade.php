<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-brand"><i data-feather="users"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['total'] }}</span>
        <span class="ops-kpi-lbl">Mitarbeiter, {{ $data['active'] }} aktiv</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['recent'] as $employee)
            <div class="ops-row"><span>{{ $employee->name }}</span><span class="ops-muted">seit {{ $employee->created_at->translatedFormat('d.m.Y') }}</span></div>
        @empty
            <div class="ops-empty">Keine Mitarbeiter.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">Mitarbeiter öffnen →</a>
