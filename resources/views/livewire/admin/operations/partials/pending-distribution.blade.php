@php
    $distributionTabsId = 'shift-distribution-'.$this->getId();
    $progressRequired = $distributionProgress['required'];
    $progressReserved = $distributionProgress['reserved'];
    $progressPercent = $progressRequired > 0 ? round($progressReserved / $progressRequired * 100, 1) : 0;
    $today = now($displayTimezone)->toDateString();
    $shiftDays = $pendingShifts->getCollection()->groupBy(fn ($shift) => $shift->starts_at->copy()->setTimezone($displayTimezone)->toDateString());
@endphp
{{-- Seitenpanel „Noch zu verteilen“: offene Schichten des Zeitraums auswählen und passende Mitarbeitende direkt einteilen. --}}
<div class="rt-shift-distribution rt-shift-distribution--side" data-pending-distribution x-data="{ distributionTab: @js($pendingShifts->total() > 0 || $unplannedOrders->total() === 0 ? 'shifts' : 'orders') }">
    <header class="rt-shift-distribution__header">
        <div class="rt-shift-distribution__heading">
            <div>
                <h2 id="{{ $distributionTabsId }}-title">Noch zu verteilen</h2>
                <p><i class="far fa-calendar-alt" aria-hidden="true"></i>{{ \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') }}</p>
            </div>
            <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-distribution__close" wire:click="toggleDistribution" wire:loading.attr="disabled" wire:target="toggleDistribution" aria-label="Offene Planung schließen" title="Seitenleiste schließen"><i class="far fa-xmark" aria-hidden="true"></i></x-ui.buttons.button-basic>
        </div>
        <div class="rt-shift-distribution__progress" role="img" aria-label="{{ $progressReserved }} von {{ $progressRequired }} Plätzen eingeplant">
            <div class="rt-shift-distribution__progress-text"><strong>{{ $progressReserved }}/{{ $progressRequired }}</strong><span>Plätze eingeplant</span><b>{{ max(0, $progressRequired - $progressReserved) }} offen</b></div>
            <span class="rt-shift-distribution__meter" aria-hidden="true"><span style="width: {{ $progressPercent }}%"></span></span>
        </div>
    </header>
    <x-operations.panel.tabs class="rt-shift-distribution__tabs" label="Offene Planung nach Art" :id-prefix="$distributionTabsId" model="distributionTab"
        :tabs="['shifts' => ['label' => 'Schichten', 'count' => $pendingShifts->total()], 'orders' => ['label' => 'Leistungen', 'count' => $unplannedOrders->total()]]" />

    <section class="rt-shift-distribution__group" role="tabpanel" id="{{ $distributionTabsId }}-panel-shifts" aria-labelledby="{{ $distributionTabsId }}-tab-shifts"
        x-show="distributionTab === 'shifts'" :inert="distributionTab !== 'shifts'">
        @if($pendingShifts->total() === 0)
            <p class="rt-shift-distribution__empty" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>Alle Schichten in diesem Zeitraum sind besetzt.</p>
        @else
            <div wire:loading.class="opacity-50" wire:target="previousPage,nextPage,selectDistributionShift,assignFromDistribution">
            @foreach($shiftDays as $date => $dayShifts)
                @php($day = \Carbon\CarbonImmutable::parse($date, $displayTimezone)->locale('de'))
                <section class="rt-shift-distribution__day" wire:key="distribution-day-{{ $date }}">
                    <h3 class="rt-shift-distribution__day-head">
                        <span>{{ $day->isoFormat('dd, DD.MM.') }}@if($date === $today)<em>Heute</em>@endif</span>
                        <span>{{ $dayShifts->sum(fn ($shift) => max(0, $shift->required_staff - $shift->reserved_count)) }} frei</span>
                    </h3>
                    <ol class="rt-shift-distribution__entries">
                        @foreach($dayShifts as $item)
                            @php
                                $selected = $distributionShift && $distributionShift->id === $item->id;
                                $start = $item->starts_at->copy()->setTimezone($displayTimezone);
                                $end = $item->ends_at->copy()->setTimezone($displayTimezone);
                            @endphp
                            <li class="rt-shift-distribution__entry" data-selected="{{ $selected ? 'true' : 'false' }}" wire:key="distribution-shift-{{ $item->id }}">
                                <button type="button" class="rt-shift-distribution__item" wire:click="selectDistributionShift({{ $item->id }})" aria-expanded="{{ $selected ? 'true' : 'false' }}"
                                    aria-label="Besetzung vervollständigen: {{ $item->title }}, {{ $start->locale('de')->isoFormat('dd, DD.MM. HH:mm') }}">
                                    <span class="rt-shift-distribution__when" aria-hidden="true"><b>{{ $start->format('H:i') }}</b><small>{{ $end->format('H:i') }}</small></span>
                                    <span class="rt-shift-distribution__text">
                                        <strong>{{ $item->title }}</strong>
                                        <span>{{ collect([$item->order?->customer?->company_name, $item->location_name])->filter()->implode(' · ') }}</span>
                                    </span>
                                    <span class="rt-shift-distribution__free" data-draft="{{ (int) $item->published_revision === 0 ? 'true' : 'false' }}">{{ max(0, $item->required_staff - $item->reserved_count) }} frei</span>
                                    <i class="far {{ $selected ? 'fa-chevron-down' : 'fa-chevron-right' }} rt-shift-distribution__chevron" aria-hidden="true"></i>
                                </button>
                                @if($selected)
                                    <div class="rt-shift-distribution__assign" role="group" aria-label="Mitarbeitende für {{ $item->title }}">
                                        <div class="rt-shift-distribution__assign-head">
                                            <span>Zuweisung</span>
                                            <div class="rt-shift-distribution__status" role="radiogroup" aria-label="Zuweisungsstatus">
                                                @foreach(['requested' => 'Angefragt', 'confirmed' => 'Bestätigt'] as $value => $label)
                                                    <label><input type="radio" wire:model="distributionStatus" value="{{ $value }}"><span>{{ $label }}</span></label>
                                                @endforeach
                                            </div>
                                        </div>
                                        @error('distribution')<p class="rt-shift-distribution__error" role="alert">{{ $message }}</p>@enderror
                                        @forelse($distributionCandidates as $candidate)
                                            <div class="rt-shift-distribution__candidate" wire:key="distribution-candidate-{{ $item->id }}-{{ $candidate['user']->id }}">
                                                <x-user.public-info :user="$candidate['user']" :size="7" :show-email="false" :show-presence="false" />
                                                <span class="rt-shift-distribution__candidate-reason">{{ collect($candidate['reasons'])->slice(1)->first() ?? 'Keine Planungskonflikte' }}</span>
                                                <x-ui.buttons.button-basic type="button" size="sm" :mode="$loop->first ? 'primary' : 'secondary'" wire:click="assignFromDistribution({{ $item->id }},{{ $candidate['user']->id }},{{ (int) $item->revision }})" wire:loading.attr="disabled" wire:target="assignFromDistribution" aria-label="{{ $candidate['user']->name }} für {{ $item->title }} einteilen">Einteilen</x-ui.buttons.button-basic>
                                            </div>
                                        @empty
                                            <p class="rt-shift-distribution__note">{{ $item->starts_at->isPast() ? 'Die Schicht hat bereits begonnen. Besetzung über die Schichtdetails bearbeiten.' : 'Niemand ist ohne Planungskonflikt frei. Über die Schichtdetails lassen sich weitere Mitarbeitende mit Begründung prüfen.' }}</p>
                                        @endforelse
                                        <div class="rt-shift-distribution__assign-foot">
                                            <span>@if($viewMode === 'timeline')In der Zeitleiste markiert @else Passende Mitarbeitende @endif</span>
                                            <button type="button" x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $item->id }} })">Schicht öffnen<i class="far fa-arrow-right" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
            </div>
            @if($pendingShifts->hasPages())
                <nav class="rt-shift-distribution__pagination" aria-label="Offene Schichten blättern">
                    <button type="button" wire:click="previousPage('{{ $pendingShifts->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($pendingShifts->onFirstPage()) aria-label="Vorherige Seite"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                    <span>Seite {{ $pendingShifts->currentPage() }} / {{ $pendingShifts->lastPage() }}</span>
                    <button type="button" wire:click="nextPage('{{ $pendingShifts->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled(! $pendingShifts->hasMorePages()) aria-label="Nächste Seite"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
                </nav>
            @endif
        @endif
    </section>

    <section class="rt-shift-distribution__group" role="tabpanel" id="{{ $distributionTabsId }}-panel-orders" aria-labelledby="{{ $distributionTabsId }}-tab-orders"
        x-show="distributionTab === 'orders'" :inert="distributionTab !== 'orders'" x-cloak>
        @if($unplannedOrders->total() === 0)
            <p class="rt-shift-distribution__empty" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>Alle Leistungen in diesem Zeitraum sind geplant.</p>
        @else
            <ol class="rt-shift-distribution__entries">
                @foreach($unplannedOrders as $item)
                    <li class="rt-shift-distribution__entry" wire:key="distribution-orders-{{ $item->id }}">
                        <button type="button" class="rt-shift-distribution__item" wire:click="prepareOrderShift({{ $item->id }})" aria-label="Schicht planen für: {{ $item->title }}">
                            <span class="rt-shift-distribution__item-icon" aria-hidden="true"><i class="far fa-briefcase"></i></span>
                            <span class="rt-shift-distribution__text">
                                <strong>{{ $item->title }}</strong>
                                <span>{{ collect([$item->customer?->company_name, $item->location_name])->filter()->implode(' · ') }}</span>
                                <span class="rt-shift-distribution__time"><i class="far fa-clock" aria-hidden="true"></i>{{ $item->starts_at->copy()->setTimezone($displayTimezone)->format('d.m. H:i') }} – {{ $item->ends_at->copy()->setTimezone($displayTimezone)->format('d.m. H:i') }}</span>
                            </span>
                            <span class="rt-shift-distribution__action"><span>Planen</span><i class="far fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                @endforeach
            </ol>
            @if($unplannedOrders->hasPages())
                <nav class="rt-shift-distribution__pagination" aria-label="Ungeplante Leistungen blättern">
                    <button type="button" wire:click="previousPage('{{ $unplannedOrders->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled($unplannedOrders->onFirstPage()) aria-label="Vorherige Seite"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                    <span>Seite {{ $unplannedOrders->currentPage() }} / {{ $unplannedOrders->lastPage() }}</span>
                    <button type="button" wire:click="nextPage('{{ $unplannedOrders->getPageName() }}')" wire:loading.attr="disabled" wire:target="previousPage,nextPage" @disabled(! $unplannedOrders->hasMorePages()) aria-label="Nächste Seite"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
                </nav>
            @endif
        @endif
    </section>

    <footer class="rt-shift-distribution__footer">
        <details class="rt-shift-distribution__basis"><summary>Planungsgrundlage<i class="far fa-chevron-down" aria-hidden="true"></i></summary><p class="rt-shift-distribution__note">Gewählter Zeitraum · unabhängig von Listenfiltern. Angefragte und bestätigte Mitarbeiter zählen als eingeplant. Bei offenem Panel zeigt die Zeitleiste unverbindliche Besetzungsvorschläge.</p></details>
        @can('operations.inquiries.manage')
            <a class="rt-shift-distribution__inquiries" href="{{ \App\Support\Operations\OperationsPages::moduleUrl('inquiries') }}" wire:navigate><i class="far fa-inbox" aria-hidden="true"></i>Anfragen öffnen<i class="far fa-arrow-right" aria-hidden="true"></i></a>
        @endcan
    </footer>
</div>
