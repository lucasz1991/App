{{-- Gemeinsames Markup fuer die vier Cockpit-Warteschlangen (dieselbe Datenform aus WidgetDataProvider::operationsQueue()). --}}
<span class="widget-primary-val">{{ $data['count'] }}</span>
<span class="widget-primary-lbl">{{ $data['count'] === 1 ? 'wartet auf Bearbeitung' : 'warten auf Bearbeitung' }}</span>
@if($rows === 2)
    <div class="widget-detail">
        @forelse($data['items'] as $item)
            <div class="ops-row"><span>{{ $item['title'] }}{{ $item['meta'] ? ' · '.$item['meta'] : '' }}</span><span class="ops-muted">{{ $item['when']?->diffForHumans() }}</span></div>
        @empty
            <div class="ops-empty">Nichts Offenes.</div>
        @endforelse
    </div>
@endif
<a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>{{ $data['label'] }} öffnen →</a>
