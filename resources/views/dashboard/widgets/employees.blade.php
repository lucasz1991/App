<p class="ops-kpi-val" style="font-size:28px;">{{ $data['total'] }}</p>
<p class="ops-muted">Mitarbeiter, {{ $data['active'] }} aktiv</p>
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
