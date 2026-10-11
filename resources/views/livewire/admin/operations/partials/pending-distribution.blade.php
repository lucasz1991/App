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
        <h2 class="sr-only" id="{{ $distributionTabsId }}-title">Noch zu verteilen</h2>
        <div class="rt-shift-distribution__progress" role="img" aria-label="{{ $progressReserved }} von {{ $progressRequired }} Plätzen eingeplant" title="Gewählter Zeitraum · unabhängig von Listenfiltern. Angefragte und bestätigte Mitarbeiter zählen als eingeplant.">
            <div class="rt-shift-distribution__progress-text"><strong>{{ $progressReserved }}/{{ $progressRequired }}</strong><span>Plätze eingeplant</span><b>{{ max(0, $progressRequired - $progressReserved) }} offen</b></div>
            <span class="rt-shift-distribution__meter" aria-hidden="true"><span style="width: {{ $progressPercent }}%"></span></span>
        </div>
        <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-distribution__close" wire:click="toggleDistribution" wire:loading.attr="disabled" wire:target="toggleDistribution" aria-label="Offene Planung schließen" title="Seitenleiste schließen"><i class="far fa-times" aria-hidden="true"></i></x-ui.buttons.button-basic>
        <p class="sr-only">{{ \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') }}. Gewählter Zeitraum · unabhängig von Listenfiltern. Angefragte und bestätigte Mitarbeiter zählen als eingeplant. Bei offenem Panel zeigt die Zeitleiste unverbindliche Besetzungsvorschläge.</p>
    </header>
    <x-operations.panel.tabs class="rt-shift-distribution__tabs" label="Offene Planung nach Art" :id-prefix="$distributionTabsId" model="distributionTab"
        :tabs="['shifts' => ['label' => 'Schichten', 'count' => $pendingShifts->total()], 'orders' => ['label' => 'Leistungen', 'count' => $unplannedOrders->total()]]" />

    <section class="rt-shift-distribution__group" role="tabpanel" id="{{ $distributionTabsId }}-panel-shifts" aria-labelledby="{{ $distributionTabsId }}-tab-shifts"
        x-show="distributionTab === 'shifts'" :inert="distributionTab !== 'shifts'" @unless($pendingShifts->total() > 0 || $unplannedOrders->total() === 0) style="display: none" @endunless>
        @if($pendingShifts->total() === 0)
            <p class="rt-shift-distribution__empty" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>Alle Schichten in diesem Zeitraum sind besetzt.</p>
        @else
            <div wire:loading.class="opacity-50" wire:target="previousPage,nextPage,selectDistributionShift,assignFromDistribution">
            @foreach($shiftDays as $date => $dayShifts)
                @php
                    $day = \Carbon\CarbonImmutable::parse($date, $displayTimezone)->locale('de');
                @endphp
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
                                    x-on:click="$dispatch('operations-timeline-distribution-focus', { owner: @js($this->getId()), shiftId: {{ $item->id }}, date: @js($start->toDateString()), selected: @js($selected) })"
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
                                        @if(\App\Support\Routing\TomTomSettings::all()['enabled'] && count($distributionCandidates) > 0)
                                            @php($travelEmployeeIds = collect($distributionCandidates)->pluck('user.id')->all())
                                            <livewire:operations.shift-travel-panel :shift-id="$item->id" :employee-ids="$travelEmployeeIds" :key="'distribution-travel-'.$item->id.'-'.$item->revision.'-'.md5(implode(',', $travelEmployeeIds))" />
                                        @endif
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
        @endif
    </section>

    <section class="rt-shift-distribution__group" role="tabpanel" id="{{ $distributionTabsId }}-panel-orders" aria-labelledby="{{ $distributionTabsId }}-tab-orders"
        x-show="distributionTab === 'orders'" :inert="distributionTab !== 'orders'" @if($pendingShifts->total() > 0 || $unplannedOrders->total() === 0) style="display: none" @endif>
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
        @endif
    </section>

    <footer class="rt-shift-distribution__footer" aria-label="Offene Planung – Aktionen"
        wire:loading.attr="inert" wire:loading.class="opacity-50"
        wire:target="selectDistributionShift,assignFromDistribution,previousPage,nextPage,applyPeriod,movePeriod,currentWeek,rangeFrom,rangeTo,setView">
        <div class="rt-shift-distribution__footer-actions" role="group" aria-label="Ausgewählte Schicht">
            <button type="button" class="rt-shift-distribution__footer-button" data-distribution-focus
                @if($distributionShift && $viewMode === 'timeline' && $nativeOperations)
                    x-on:click="$dispatch('operations-timeline-distribution-focus', { owner: @js($this->getId()), shiftId: {{ $distributionShift->id }}, date: @js($distributionShift->starts_at->copy()->setTimezone($displayTimezone)->toDateString()), selected: false })"
                @endif
                x-bind:disabled="distributionTab !== 'shifts' || @js(! $distributionShift || $viewMode !== 'timeline' || ! $nativeOperations)"
                @disabled(! $distributionShift || $viewMode !== 'timeline' || ! $nativeOperations) aria-label="Ausgewählte Schicht in der Zeitleiste zeigen" title="Ausgewählte Schicht in der Zeitleiste zeigen">
                <i class="far fa-location-crosshairs" aria-hidden="true"></i>
            </button>
            <button type="button" class="rt-shift-distribution__footer-button" data-distribution-clear
                @if($distributionShift)
                    wire:click="selectDistributionShift({{ $distributionShift->id }})"
                    x-on:click="$dispatch('operations-timeline-distribution-focus', { owner: @js($this->getId()), shiftId: {{ $distributionShift->id }}, date: @js($distributionShift->starts_at->copy()->setTimezone($displayTimezone)->toDateString()), selected: true })"
                @endif
                x-bind:disabled="distributionTab !== 'shifts' || @js(! $distributionShift)"
                @disabled(! $distributionShift) aria-label="Schichtauswahl aufheben" title="Schichtauswahl aufheben">
                <i class="far fa-times-circle" aria-hidden="true"></i>
            </button>
        </div>
        @foreach(['shifts' => $pendingShifts, 'orders' => $unplannedOrders] as $tab => $pages)
            <nav class="rt-shift-distribution__pagination" aria-label="{{ $tab === 'shifts' ? 'Offene Schichten blättern' : 'Ungeplante Leistungen blättern' }}"
                x-show="distributionTab === @js($tab)" :inert="distributionTab !== @js($tab)"
                @if(($pendingShifts->total() > 0 || $unplannedOrders->total() === 0 ? 'shifts' : 'orders') !== $tab) style="display: none" @endif>
                <button type="button" class="rt-shift-distribution__footer-button" wire:click="previousPage('{{ $pages->getPageName() }}')"
                    @disabled($pages->onFirstPage()) aria-label="Vorherige Seite" title="Vorherige Seite"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                <span class="rt-shift-distribution__page" aria-live="polite" aria-atomic="true"><span class="sr-only">Seite </span>{{ $pages->currentPage() }} / {{ $pages->lastPage() }}</span>
                <button type="button" class="rt-shift-distribution__footer-button" wire:click="nextPage('{{ $pages->getPageName() }}')"
                    @disabled(! $pages->hasMorePages()) aria-label="Nächste Seite" title="Nächste Seite"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
            </nav>
        @endforeach
    </footer>
</div>
