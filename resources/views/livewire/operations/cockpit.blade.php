<section class="rt-ops ops-stack" data-operations-cockpit wire:poll.60s>
    {{--
        Der Modul-Umschalter (x-operations.navigation) stand hier zusaetzlich
        zur Sidebar-Untergruppe "Disposition" - dieselben Ziele doppelt. Die
        KPI-Kacheln unten sind selbst die Sprungmarken je Modul; die Sidebar
        bleibt der eine Ort fuer den Wechsel zwischen Arbeitsbereichen.
    --}}
    <header class="ops-toolbar" data-anim="fade-up"><div><p class="ops-kicker">Betriebsübersicht · {{ now(config('operations.display_timezone'))->translatedFormat('D, d. M Y') }}</p><h1>Heute im Blick</h1></div><button type="button" class="ops-link" wire:click="$refresh" wire:loading.attr="disabled">Aktualisieren ↻</button></header>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-anim-stagger>
        @foreach($queue as $item)
            <a class="ops-kpi" href="{{ route('operations.workspace',$item['slug']) }}" wire:navigate><span class="ops-kpi-ico ops-tone-{{ $item['count'] > 0 ? 'warn' : 'ok' }}"><i data-feather="{{ $item['icon'] }}"></i></span><span><span class="ops-kpi-val">{{ $item['count'] }}</span><span class="ops-kpi-lbl">{{ $item['label'] }}</span></span></a>
        @endforeach
        @if(isset($modules['shift-management']))
            <a class="ops-kpi" href="{{ route('operations.workspace','shift-management') }}" wire:navigate><span class="ops-kpi-ico ops-tone-{{ $openSlots > 0 ? 'brand' : 'ok' }}"><i data-feather="alert-triangle"></i></span><span><span class="ops-kpi-val">{{ $openSlots }}</span><span class="ops-kpi-lbl">Offene Besetzungen · 14 Tage</span></span></a>
        @endif
    </div>
    @if(isset($modules['shift-management']))
        <section class="ops-panel" data-anim="fade-up" x-data="operationsCoverageChart(@js($weekCoverage))">
            <header class="ops-toolbar"><h2>Besetzung diese Woche</h2><div class="ops-chart-legend"><span><i style="background:var(--ops-line)"></i>Benötigt</span><span><i style="background:var(--ops-signal)"></i>Zugesagt</span></div></header>
            <div class="ops-chart-canvas" x-ref="coverageChart" wire:ignore role="img" aria-label="Besetzung der naechsten sieben Tage: zugesagt gegenueber benoetigt"></div>
        </section>
        <section class="ops-panel" data-anim="fade-up"><header class="ops-toolbar"><h2>Nächste Dienste</h2><a class="ops-link" href="{{ route('operations.workspace','shift-management') }}" wire:navigate>Schichtplan →</a></header>@forelse($shifts->take(6) as $shift)<a class="ops-row ops-service" href="{{ route('operations.workspace','shift-management') }}?shift={{ $shift->id }}" wire:navigate><div><p class="ops-service-date">{{ $shift->starts_at->format('d.m. · H:i') }} – {{ $shift->ends_at->format($shift->starts_at->isSameDay($shift->ends_at) ? 'H:i' : 'd.m. H:i') }}</p><h3>{{ $shift->title }}</h3><p class="ops-muted">{{ $shift->order?->customer?->company_name }} · {{ $shift->location_name }}</p></div><div class="ops-actions"><span class="ops-badge" data-state="{{ $shift->reserved >= $shift->required_staff ? 'confirmed' : 'pending' }}">{{ $shift->reserved }}/{{ $shift->required_staff }} besetzt</span>@if($shift->published_revision !== $shift->revision)<span class="ops-badge">Entwurf</span>@endif</div></a>@empty<div class="ops-empty">Keine Dienste in den nächsten 14 Tagen.</div>@endforelse</section>
    @endif
</section>
