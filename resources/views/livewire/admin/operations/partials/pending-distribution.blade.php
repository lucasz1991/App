<div class="rt-shift-distribution" data-pending-distribution>
    @if($viewMode === 'timeline')<x-ui.buttons.button-basic type="button" mode="link" size="sm" x-on:click="$dispatch('operations-timeline-suggestions-toggle'); close()"><i class="far fa-lightbulb" aria-hidden="true"></i>Besetzungsvorschläge ein-/ausblenden</x-ui.buttons.button-basic>@endif
    <header class="rt-shift-distribution__header">
        <h2>Noch zu verteilen</h2>
        <p>{{ \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') }} · unabhängig von Listenfiltern</p>
    </header>
    @if($pendingShifts->total() + $unplannedOrders->total() === 0)
        <p class="rt-shift-distribution__empty" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>Keine offenen Verteilungen in diesem Zeitraum.</p>
    @else
        @foreach(['shifts' => $pendingShifts, 'orders' => $unplannedOrders] as $kind => $items)
            @if($items->total() > 0)
                <section class="rt-shift-distribution__group" aria-label="{{ $kind === 'shifts' ? 'Schichten mit offenen Plätzen' : 'Leistungen ohne Schichtplanung' }}">
                    <h3><i class="far {{ $kind === 'shifts' ? 'fa-user-clock' : 'fa-briefcase' }}" aria-hidden="true"></i>{{ $kind === 'shifts' ? 'Offene Schichtplätze' : 'Noch ohne Schichtplanung' }}<span>{{ $items->total() }}</span></h3>
                    @foreach($items as $item)
                        <button type="button" class="rt-shift-distribution__item" wire:key="distribution-{{ $kind }}-{{ $item->id }}"
                            @if($kind === 'shifts')
                                x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $item->id }} }); close()"
                            @else
                                wire:click="prepareOrderShift({{ $item->id }})" x-on:click="close()"
                            @endif
                            aria-label="{{ $kind === 'shifts' ? 'Besetzung öffnen: ' : 'Schicht planen für: ' }}{{ $item->title }}">
                            <span class="rt-shift-distribution__text">
                                <strong>{{ $item->title }}</strong>
                                <span>{{ $kind === 'shifts' ? $item->order?->customer?->company_name : $item->customer?->company_name }}</span>
                                <span>{{ $item->starts_at->copy()->setTimezone($displayTimezone)->format('d.m. H:i') }} – {{ $item->ends_at->copy()->setTimezone($displayTimezone)->format('d.m. H:i') }}</span>
                            </span>
                            <span class="rt-shift-distribution__action">{{ $kind === 'shifts' ? max(0, $item->required_staff - $item->reserved_count).' offen' : 'Planen' }}<i class="far fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    @endforeach
                    @if($items->hasPages())
                        <nav class="rt-shift-distribution__pagination" data-rt-dropdown-keep-open aria-label="{{ $kind === 'shifts' ? 'Offene Schichten blättern' : 'Ungeplante Leistungen blättern' }}">
                            <button type="button" wire:click="previousPage('{{ $items->getPageName() }}')" @disabled($items->onFirstPage()) aria-label="Vorherige Seite"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                            <span>Seite {{ $items->currentPage() }} / {{ $items->lastPage() }}</span>
                            <button type="button" wire:click="nextPage('{{ $items->getPageName() }}')" @disabled(! $items->hasMorePages()) aria-label="Nächste Seite"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
                        </nav>
                    @endif
                </section>
            @endif
        @endforeach
        <p class="rt-shift-distribution__note">Angefragte und bestätigte Mitarbeiter zählen als eingeplant.</p>
    @endif
</div>
