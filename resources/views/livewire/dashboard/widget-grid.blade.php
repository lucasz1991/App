<div class="rt-ops ops-stack" data-dashboard-widget-grid wire:poll.60s x-data="{ pickerOpen: false }" @keydown.escape.window="pickerOpen = false">
    {{--
        "Anpassen" (editing, Server) und die Hinzufuegen-Schublade (pickerOpen,
        rein clientseitig) sind bewusst getrennte Zustaende: die Schublade
        deckt das Raster mit einem Overlay ab, das Anpassen selbst tut das
        nicht - sonst waeren Ziehen/Groesse/Entfernen am Raster blockiert,
        sobald man in den Anpassen-Modus wechselt.
    --}}
    <header class="ops-toolbar" data-anim="fade-up">
        <div><p class="ops-kicker">{{ now(config('operations.display_timezone'))->translatedFormat('D, d. M Y') }}</p><h1>Willkommen, {{ auth()->user()->name }}</h1></div>
        <div class="ops-actions">
            @if($editing)
                <button type="button" class="ops-btn" @click="pickerOpen = true">
                    <i data-feather="plus"></i>Widget hinzufügen
                </button>
            @endif
            <button type="button" class="ops-btn{{ $editing ? ' is-active' : '' }}" wire:click="toggleEditing" @click="pickerOpen = false">
                <i data-feather="{{ $editing ? 'check' : 'sliders' }}"></i>{{ $editing ? 'Fertig' : 'Dashboard anpassen' }}
            </button>
        </div>
    </header>

    <div class="widget-grid" data-widget-track x-data="dashboardWidgetGrid" data-anim-stagger>
        @forelse($visible as $item)
            <x-dashboard.widget-shell :item="$item" :editing="$editing">
                @include('dashboard.widgets.' . $item['key'], ['data' => $widgetData[$item['key']] ?? [], 'size' => $item['size']])
            </x-dashboard.widget-shell>
        @empty
            <div class="ops-empty widget-empty">Keine Widgets ausgewählt. Über „Dashboard anpassen" welche hinzufügen.</div>
        @endforelse
    </div>

    {{--
        Rechte Schublade zum Hinzufuegen - bleibt immer im DOM (fuer die
        Ein-/Ausfahr-Animation) und blendet rein ueber pickerOpen, nicht
        ueber $editing.
    --}}
    <div class="widget-sidebar-backdrop" :class="{ 'is-open': pickerOpen }" @click="pickerOpen = false"></div>
    <aside class="widget-sidebar" :class="{ 'is-open': pickerOpen }" :aria-hidden="(!pickerOpen).toString()" aria-label="Widget hinzufügen">
        <div class="widget-sidebar-head">
            <div><h2 style="font-size:16px;">Widget hinzufügen</h2><p class="ops-muted" style="margin-top:2px;">Jede Kachel gibt es in Klein und Groß.</p></div>
            <button type="button" class="widget-sidebar-close" @click="pickerOpen = false" aria-label="Schließen"><i data-feather="x"></i></button>
        </div>
        <div class="widget-sidebar-body">
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
        </div>
    </aside>
</div>
