@php
    $distributionTabsId = 'shift-distribution-'.$this->getId();
@endphp
<div class="rt-shift-distribution" data-pending-distribution x-data="{ distributionTab: @js($pendingShifts->total() > 0 || $unplannedOrders->total() === 0 ? 'shifts' : 'orders') }">
    <header class="rt-shift-distribution__header">
        <span class="rt-shift-distribution__eyebrow">Offene Planung</span>
        <h2>Noch zu verteilen</h2>
        <p><i class="far fa-calendar-alt" aria-hidden="true"></i>{{ \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') }}</p>
    </header>
    <x-operations.panel.tabs class="rt-shift-distribution__tabs" label="Offene Planung nach Art" :id-prefix="$distributionTabsId" model="distributionTab"
        :tabs="['shifts' => ['label' => 'Schichten', 'count' => $pendingShifts->total()], 'orders' => ['label' => 'Leistungen', 'count' => $unplannedOrders->total()]]" data-rt-dropdown-keep-open />
    @foreach(['shifts' => $pendingShifts, 'orders' => $unplannedOrders] as $kind => $items)
        <section class="rt-shift-distribution__group" role="tabpanel" id="{{ $distributionTabsId }}-panel-{{ $kind }}" aria-labelledby="{{ $distributionTabsId }}-tab-{{ $kind }}"
            x-show="distributionTab === @js($kind)" :inert="distributionTab !== @js($kind)" x-cloak>
            @if($items->total() === 0)
                <p class="rt-shift-distribution__empty" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>{{ $kind === 'shifts' ? 'Alle Schichten in diesem Zeitraum sind besetzt.' : 'Alle Leistungen in diesem Zeitraum sind geplant.' }}</p>
            @else
                    <h3>{{ $kind === 'shifts' ? 'Besetzung vervollständigen' : 'Schichtplanung beginnen' }}</h3>
                    <div wire:loading.flex wire:target="previousPage,nextPage" class="rt-shift-distribution__loading" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i>Weitere Einträge werden geladen …</div>
                    <div wire:loading.class="opacity-50" wire:target="previousPage,nextPage">
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
                                <span class="rt-shift-distribution__time"><i class="far fa-clock" aria-hidden="true"></i>{{ $item->starts_at->copy()->setTimezone($displayTimezone)->format('d.m. H:i') }} – {{ $item->ends_at->copy()->setTimezone($displayTimezone)->format('d.m. H:i') }}</span>
                            </span>
                            <span class="rt-shift-distribution__action"><span>{{ $kind === 'shifts' ? max(0, $item->required_staff - $item->reserved_count).' frei' : 'Planen' }}</span><i class="far fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    @endforeach
                    </div>
                    @if($items->hasPages())
                        <nav class="rt-shift-distribution__pagination" data-rt-dropdown-keep-open aria-label="{{ $kind === 'shifts' ? 'Offene Schichten blättern' : 'Ungeplante Leistungen blättern' }}">
                            <button type="button" wire:click="previousPage('{{ $items->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($items->onFirstPage()) aria-label="Vorherige Seite"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                            <span>Seite {{ $items->currentPage() }} / {{ $items->lastPage() }}</span>
                            <button type="button" wire:click="nextPage('{{ $items->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled(! $items->hasMorePages()) aria-label="Nächste Seite"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
                        </nav>
                    @endif
            @endif
        </section>
    @endforeach
    <footer class="rt-shift-distribution__footer">
        <p class="rt-shift-distribution__note">Gewählter Zeitraum · unabhängig von Listenfiltern. Angefragte und bestätigte Mitarbeiter zählen als eingeplant.</p>
        @can('operations.inquiries.manage')
            <a class="rt-shift-distribution__inquiries" href="{{ \App\Support\Operations\OperationsPages::moduleUrl('inquiries') }}" wire:navigate><i class="far fa-inbox" aria-hidden="true"></i>Anfragen öffnen<i class="far fa-arrow-right" aria-hidden="true"></i></a>
        @endcan
    </footer>
</div>
