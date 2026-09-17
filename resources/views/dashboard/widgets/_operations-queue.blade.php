{{-- Gemeinsames Markup fuer die vier Cockpit-Warteschlangen (dieselbe Datenform aus WidgetDataProvider::operationsQueue()). --}}
<p class="ops-kpi-val" style="font-size:30px;">{{ $data['count'] }}</p>
<p class="ops-muted">{{ $data['count'] === 1 ? 'wartet auf Bearbeitung' : 'warten auf Bearbeitung' }}</p>
<a class="ops-link" href="{{ $data['href'] }}" wire:navigate>{{ $data['label'] }} öffnen →</a>
