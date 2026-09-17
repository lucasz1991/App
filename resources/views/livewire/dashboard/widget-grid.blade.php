<div class="rt-ops ops-stack" data-dashboard-widget-grid wire:poll.60s>
    <header class="ops-toolbar" data-anim="fade-up">
        <div><p class="ops-kicker">{{ now(config('operations.display_timezone'))->translatedFormat('D, d. M Y') }}</p><h1>Willkommen, {{ auth()->user()->name }}</h1></div>
        <button type="button" class="ops-btn{{ $editing ? ' is-active' : '' }}" wire:click="toggleEditing">
            <i data-feather="{{ $editing ? 'check' : 'sliders' }}"></i>{{ $editing ? 'Fertig' : 'Dashboard anpassen' }}
        </button>
    </header>

    @if($editing)
        <section class="ops-panel widget-picker" data-anim="fade-up">
            <header class="ops-toolbar"><h2>Widget hinzufügen</h2><span class="ops-muted">Jede Kachel gibt es in Klein und Groß · per Pfeil, Ziehen oder Tastatur anordnen</span></header>
            @if($hasHidden)
                @foreach($hiddenBySection as $section => $items)
                    <div class="widget-picker-section">
                        <p class="ops-kicker">{{ $section }}</p>
                        <div class="widget-picker-grid">
                            @foreach($items as $item)
                                <button type="button" class="widget-picker-item" wire:click="showWidget('{{ $item['key'] }}')" wire:key="picker-{{ $item['key'] }}">
                                    <span class="widget-card-ico"><i data-feather="{{ $item['icon'] }}"></i></span>
                                    <span class="widget-picker-text"><span class="widget-picker-title">{{ $item['title'] }}</span><span class="ops-muted">{{ $item['description'] }}</span></span>
                                    <i data-feather="plus"></i>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @else
                <p class="ops-muted">Alle für dich verfügbaren Widgets sind schon auf dem Dashboard.</p>
            @endif
        </section>
    @endif

    <div class="widget-grid" data-widget-track x-data="dashboardWidgetGrid" data-anim-stagger>
        @forelse($visible as $item)
            <x-dashboard.widget-shell :item="$item" :editing="$editing">
                @include('dashboard.widgets.' . $item['key'], ['data' => $widgetData[$item['key']] ?? [], 'size' => $item['size']])
            </x-dashboard.widget-shell>
        @empty
            <div class="ops-empty widget-empty">Keine Widgets ausgewählt. Über „Dashboard anpassen" welche hinzufügen.</div>
        @endforelse
    </div>
</div>
