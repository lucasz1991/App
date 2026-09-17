{{-- Gemeinsames Markup fuer die vier Cockpit-Warteschlangen (dieselbe Datenform aus WidgetDataProvider::operationsQueue()). --}}
<div class="widget-stat">
    <span class="ops-kpi-ico ops-tone-{{ $data['count'] === 0 ? 'ok' : 'warn' }}"><i data-feather="{{ $data['icon'] }}"></i></span>
    <span>
        <span class="ops-kpi-val">{{ $data['count'] }}</span>
        <span class="ops-kpi-lbl">{{ $data['count'] === 1 ? 'wartet auf Bearbeitung' : 'warten auf Bearbeitung' }}</span>
    </span>
</div>
@if($rows === 2)
    <div style="margin-top:10px;">
        @forelse($data['items'] as $item)
            <div class="ops-row"><span>{{ $item['title'] }}{{ $item['meta'] ? ' · '.$item['meta'] : '' }}</span><span class="ops-muted">{{ $item['when']?->diffForHumans() }}</span></div>
        @empty
            <div class="ops-empty">Nichts Offenes.</div>
        @endforelse
    </div>
@endif
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate style="margin-top:8px;display:inline-block;">{{ $data['label'] }} öffnen →</a>
