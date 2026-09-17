<span class="widget-primary-val">{{ $data['total'] }}</span>
<span class="widget-primary-lbl">Mitarbeiter, {{ $data['active'] }} aktiv</span>
@if($rows === 2)
    <div class="widget-detail widget-donut-row">
        <x-dashboard.donut :segments="[
            ['label' => 'Aktiv', 'count' => $data['active'], 'color' => 'var(--ops-ok)'],
            ['label' => 'Inaktiv', 'count' => $data['inactive'], 'color' => 'var(--ops-muted)'],
        ]" :value="$data['total']" />
        <div class="widget-segment-legend">
            <span><i class="widget-segment-ok"></i>Aktiv {{ $data['active'] }}</span>
            <span><i class="widget-segment-neutral"></i>Inaktiv {{ $data['inactive'] }}</span>
        </div>
    </div>
    <div class="widget-detail">
        @forelse($data['recent'] as $employee)
            <div class="ops-row"><span>{{ $employee->name }}</span><span class="ops-muted">seit {{ $employee->created_at->translatedFormat('d.m.Y') }}</span></div>
        @empty
            <div class="ops-empty">Keine Mitarbeiter.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Mitarbeiter öffnen →</a>
