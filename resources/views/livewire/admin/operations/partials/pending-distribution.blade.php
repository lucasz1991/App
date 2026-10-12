@php
    $distributionTabsId = 'shift-distribution-'.$this->getId();
    $progressRequired = $distributionProgress['required'];
    $progressReserved = $distributionProgress['reserved'];
    $progressPercent = $progressRequired > 0 ? round($progressReserved / $progressRequired * 100, 1) : 0;
    $today = now($displayTimezone)->toDateString();
    $shiftDays = $pendingShifts->getCollection()->groupBy(fn ($shift) => $shift->starts_at->copy()->setTimezone($displayTimezone)->toDateString());
    $selectedDay = $distributionShift?->starts_at->copy()->setTimezone($displayTimezone)->toDateString();
    $initialDay = $shiftDays->has($selectedDay) ? $selectedDay : ($shiftDays->has($rangeFrom) ? $rangeFrom : $shiftDays->keys()->first());
@endphp
{{-- Seitenpanel „Noch zu verteilen“: offene Schichten des Zeitraums auswählen und passende Mitarbeitende direkt einteilen. --}}
<div class="rt-shift-distribution rt-shift-distribution--side" data-pending-distribution
    x-data="distributionPanel({ distributionTab: @js($pendingShifts->total() > 0 || $unplannedOrders->total() === 0 ? 'shifts' : 'orders') })"
    data-distribution-owner="{{ $this->getId() }}"
    data-distribution-days="{{ $shiftDays->keys()->values()->toJson() }}"
    data-distribution-context="{{ json_encode([$rangeFrom, $rangeTo, $customerFilter, $pendingShifts->currentPage()]) }}"
    data-distribution-selected="{{ json_encode($distributionShift ? ['id' => (int) $distributionShift->id, 'date' => $selectedDay] : null) }}"
    data-distribution-preferred-day="{{ $rangeFrom }}"
    x-bind:data-distribution-mode="distributionMode">
    <header class="rt-shift-distribution__header">
        <h2 class="sr-only" id="{{ $distributionTabsId }}-title">Noch zu verteilen</h2>
        <x-operations.panel.tabs class="rt-shift-distribution__tabs" label="Offene Planung nach Art" :id-prefix="$distributionTabsId" model="distributionTab"
            x-show="distributionMode === 'list'" ::inert="distributionMode !== 'list'"
            :tabs="['shifts' => ['label' => 'Schichten', 'icon' => 'far fa-clock', 'count' => $pendingShifts->total()], 'orders' => ['label' => 'Leistungen', 'icon' => 'far fa-briefcase', 'count' => $unplannedOrders->total()]]" />
        <span class="rt-shift-distribution__auto-label" x-cloak x-show="distributionMode === 'auto'"><i class="far fa-magic" aria-hidden="true"></i>Besetzung</span>
        <div class="rt-shift-distribution__progress" role="img" aria-label="{{ $progressReserved }} von {{ $progressRequired }} Plätzen eingeplant" title="Gewählter Zeitraum · unabhängig von Listenfiltern. Angefragte und bestätigte Mitarbeiter zählen als eingeplant.">
            <div class="rt-shift-distribution__progress-text"><strong>{{ $progressReserved }}/{{ $progressRequired }}</strong><span class="sr-only">Plätze eingeplant</span><b>{{ max(0, $progressRequired - $progressReserved) }} offen</b></div>
            <span class="rt-shift-distribution__meter" aria-hidden="true"><span style="width: {{ $progressPercent }}%"></span></span>
        </div>
        <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-distribution__close" wire:click="toggleDistribution" wire:loading.attr="disabled" wire:target="toggleDistribution" aria-label="Offene Planung schließen" title="Seitenleiste schließen"><i class="far fa-times" aria-hidden="true"></i></x-ui.buttons.button-basic>
        <p class="sr-only">{{ \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') }}. Gewählter Zeitraum · unabhängig von Listenfiltern. Angefragte und bestätigte Mitarbeiter zählen als eingeplant. Bei offenem Panel zeigt die Zeitleiste unverbindliche Besetzungsvorschläge.</p>
    </header>

    <section class="rt-shift-distribution__group" role="tabpanel" id="{{ $distributionTabsId }}-panel-shifts" aria-labelledby="{{ $distributionTabsId }}-tab-shifts"
        x-show="distributionMode === 'list' && distributionTab === 'shifts'" :inert="distributionMode !== 'list' || distributionTab !== 'shifts'" @unless($pendingShifts->total() > 0 || $unplannedOrders->total() === 0) style="display: none" @endunless>
        @if($pendingShifts->total() === 0)
            <p class="rt-shift-distribution__empty" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>Alle Schichten in diesem Zeitraum sind besetzt.</p>
        @else
            <div wire:loading.class="opacity-50" wire:target="previousPage,nextPage,selectDistributionShift,assignFromDistribution">
            @foreach($shiftDays as $date => $dayShifts)
                @php
                    $day = \Carbon\CarbonImmutable::parse($date, $displayTimezone)->locale('de');
                    $dayCounts = $pendingShiftDayCounts[$date];
                    $dayId = $distributionTabsId.'-day-'.$date;
                    $daySummary = $dayCounts['shifts'].($dayCounts['shifts'] === 1 ? ' Schicht' : ' Schichten').' · '.$dayCounts['open_places'].($dayCounts['open_places'] === 1 ? ' freier Platz' : ' freie Plätze').' · '.$dayCounts['requested_places'].($dayCounts['requested_places'] === 1 ? ' ausstehende Zusage' : ' ausstehende Zusagen').' · auf dieser Seite';
                @endphp
                <section class="rt-shift-distribution__day" wire:key="distribution-day-{{ $date }}" x-bind:data-open="openDay === @js($date)">
                    <h3 class="rt-shift-distribution__day-heading">
                        <button type="button" class="rt-shift-distribution__day-head" data-distribution-day-toggle data-today="{{ $date === $today ? 'true' : 'false' }}"
                            id="{{ $dayId }}-toggle" aria-controls="{{ $dayId }}-entries" aria-expanded="{{ $initialDay === $date ? 'true' : 'false' }}"
                            x-bind:aria-expanded="openDay === @js($date)" x-on:click="toggleDay(@js($date))" x-on:keydown="navigateDays($event)"
                            title="{{ $day->isoFormat('dddd, DD.MM.YYYY') }} · {{ $daySummary }}" aria-label="{{ $day->isoFormat('dddd, DD.MM.YYYY') }} · {{ $daySummary }}">
                            <span class="rt-shift-distribution__day-date"><i class="far fa-calendar-day" aria-hidden="true"></i>{{ $day->isoFormat('dd, DD.MM.') }}@if($date === $today)<em>Heute</em>@endif</span>
                            <span class="rt-shift-distribution__day-indicators" aria-hidden="true">
                                <span data-status="shifts" title="{{ $dayCounts['shifts'] }} Schichten auf dieser Seite"><i class="far fa-clock"></i>{{ $dayCounts['shifts'] }}</span>
                                <span data-status="open" title="{{ $dayCounts['open_places'] }} freie Plätze"><i class="far fa-user-plus"></i>{{ $dayCounts['open_places'] }}</span>
                                @if($dayCounts['requested_places'] > 0)<span data-status="requested" title="{{ $dayCounts['requested_places'] }} ausstehende Zusagen"><i class="far fa-hourglass-half"></i>{{ $dayCounts['requested_places'] }}</span>@endif
                            </span>
                            <i class="far fa-chevron-down rt-shift-distribution__day-caret" aria-hidden="true"></i>
                        </button>
                    </h3>
                    <ol class="rt-shift-distribution__entries" id="{{ $dayId }}-entries" aria-labelledby="{{ $dayId }}-toggle"
                        x-cloak x-show="openDay === @js($date)" :inert="openDay !== @js($date)" @if($initialDay !== $date) style="display: none" @endif>
                        @foreach($dayShifts as $item)
                            @php
                                $selected = $distributionShift && $distributionShift->id === $item->id;
                                $start = $item->starts_at->copy()->setTimezone($displayTimezone);
                                $end = $item->ends_at->copy()->setTimezone($displayTimezone);
                            @endphp
                            <li class="rt-shift-distribution__entry" data-selected="{{ $selected ? 'true' : 'false' }}" wire:key="distribution-shift-{{ $item->id }}">
                                <button type="button" class="rt-shift-distribution__item" wire:click="selectDistributionShift({{ $item->id }})" aria-expanded="{{ $selected ? 'true' : 'false' }}"
                                    x-on:click="$dispatch('operations-timeline-distribution-focus', { owner: @js($this->getId()), shiftId: {{ $item->id }}, date: @js($start->toDateString()), selected: @js($selected) })"
                                    @if($selected) aria-controls="{{ $distributionTabsId }}-assignment-{{ $item->id }}" @endif
                                    title="{{ $item->title }} · {{ $start->format('d.m. H:i') }} – {{ $end->format('d.m. H:i') }}"
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
                                    <div class="rt-shift-distribution__assign" id="{{ $distributionTabsId }}-assignment-{{ $item->id }}" role="group" aria-label="Mitarbeitende für {{ $item->title }}"
                                        x-init="$nextTick(() => {
                                            const group = $el.closest('.rt-shift-distribution__group');
                                            if (!group?.clientHeight) return;
                                            const entry = $el.closest('.rt-shift-distribution__entry').getBoundingClientRect();
                                            if (!entry.height) return;
                                            const viewport = group.getBoundingClientRect();
                                            const top = viewport.top + 44, bottom = viewport.bottom - 8;
                                            const delta = entry.height > bottom - top ? entry.top - top
                                                : entry.bottom > bottom ? Math.min(entry.top - top, entry.bottom - bottom)
                                                : entry.top < top ? entry.top - top : 0;
                                            if (delta !== 0) group.scrollBy({ top: delta, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                                        })">
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
                                            <button type="button" class="rt-shift-distribution__selection-clear" data-distribution-clear
                                                wire:click="selectDistributionShift({{ $item->id }})"
                                                wire:loading.attr="disabled" wire:target="selectDistributionShift,assignFromDistribution,previousPage,nextPage,applyPeriod,movePeriod,currentWeek,rangeFrom,rangeTo"
                                                x-on:click="$dispatch('operations-timeline-distribution-focus', { owner: @js($this->getId()), shiftId: {{ $item->id }}, date: @js($start->toDateString()), selected: true })"
                                                aria-label="Schichtauswahl aufheben" title="Schichtauswahl aufheben"><i class="far fa-times" aria-hidden="true"></i></button>
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
        x-show="distributionMode === 'list' && distributionTab === 'orders'" :inert="distributionMode !== 'list' || distributionTab !== 'orders'" @if($pendingShifts->total() > 0 || $unplannedOrders->total() === 0) style="display: none" @endif>
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

    @if($nativeOperations && auth()->user()?->can('operations.manage'))
        <section class="rt-shift-distribution__auto" data-distribution-auto-panel id="{{ $distributionTabsId }}-auto-panel"
            x-cloak x-show="distributionMode === 'auto'" :inert="distributionMode !== 'auto'" style="display: none"
            aria-labelledby="{{ $distributionTabsId }}-auto-title">
            <header class="rt-shift-distribution__auto-head">
                <i class="far fa-magic" aria-hidden="true"></i>
                <h3 id="{{ $distributionTabsId }}-auto-title" x-ref="distributionAutoHeading" tabindex="-1">Automatisch zuordnen</h3>
            </header>
            <div class="rt-shift-distribution__auto-owner-guard" wire:loading.attr="inert" wire:target="applyPeriod,movePeriod,currentWeek,rangeFrom,rangeTo,customerFilter,setView">
                <livewire:operations.distribution-auto-assignment :from="$rangeFrom" :until="$rangeTo" :customer-filter="$customerFilter"
                    :key="'distribution-auto-'.$this->getId().'-'.$rangeFrom.'-'.$rangeTo.'-'.$customerFilter" />
            </div>
        </section>
    @endif

    <footer class="rt-shift-distribution__footer" aria-label="Offene Planung – Aktionen"
        wire:loading.attr="inert" wire:loading.class="opacity-50"
        wire:target="selectDistributionShift,assignFromDistribution,previousPage,nextPage,applyPeriod,movePeriod,currentWeek,rangeFrom,rangeTo,setView">
        <div class="rt-shift-distribution__footer-actions" role="group" aria-label="Schichtplanung"
            x-show="distributionMode === 'list'" :inert="distributionMode !== 'list'">
            <button type="button" class="rt-shift-distribution__footer-button" data-distribution-focus
                @if($distributionShift && $viewMode === 'timeline' && $nativeOperations)
                    x-on:click="revealDay(@js($selectedDay)); $dispatch('operations-timeline-distribution-focus', { owner: @js($this->getId()), shiftId: {{ $distributionShift->id }}, date: @js($distributionShift->starts_at->copy()->setTimezone($displayTimezone)->toDateString()), selected: false })"
                @endif
                x-bind:disabled="distributionTab !== 'shifts' || @js(! $distributionShift || $viewMode !== 'timeline' || ! $nativeOperations)"
                @disabled(! $distributionShift || $viewMode !== 'timeline' || ! $nativeOperations) aria-label="Ausgewählte Schicht in der Zeitleiste zeigen" title="Ausgewählte Schicht in der Zeitleiste zeigen">
                <i class="far fa-location-crosshairs" aria-hidden="true"></i>
            </button>
            <button type="button" class="rt-shift-distribution__footer-button" data-distribution-auto x-ref="distributionAutoTrigger"
                x-on:click="distributionMode = 'auto'; $nextTick(() => $refs.distributionAutoHeading?.focus({ preventScroll: true }))"
                x-bind:disabled="distributionTab !== 'shifts' || @js(! $nativeOperations || ! auth()->user()?->can('operations.manage'))"
                @disabled(! $nativeOperations || ! auth()->user()?->can('operations.manage'))
                aria-label="Alle offenen Schichten automatisch zuordnen" title="Alle offenen Schichten automatisch zuordnen" aria-controls="{{ $distributionTabsId }}-auto-panel">
                <i class="far fa-magic" aria-hidden="true"></i>
            </button>
        </div>
        @foreach(['shifts' => $pendingShifts, 'orders' => $unplannedOrders] as $tab => $pages)
            <nav class="rt-shift-distribution__pagination" aria-label="{{ $tab === 'shifts' ? 'Offene Schichten blättern' : 'Ungeplante Leistungen blättern' }}"
                x-show="distributionMode === 'list' && distributionTab === @js($tab)" :inert="distributionMode !== 'list' || distributionTab !== @js($tab)"
                @if(($pendingShifts->total() > 0 || $unplannedOrders->total() === 0 ? 'shifts' : 'orders') !== $tab) style="display: none" @endif>
                <button type="button" class="rt-shift-distribution__footer-button" wire:click="previousPage('{{ $pages->getPageName() }}')"
                    @disabled($pages->onFirstPage()) aria-label="Vorherige Seite" title="Vorherige Seite"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                <span class="rt-shift-distribution__page" aria-live="polite" aria-atomic="true"><span class="sr-only">Seite </span>{{ $pages->currentPage() }} / {{ $pages->lastPage() }}</span>
                <button type="button" class="rt-shift-distribution__footer-button" wire:click="nextPage('{{ $pages->getPageName() }}')"
                    @disabled(! $pages->hasMorePages()) aria-label="Nächste Seite" title="Nächste Seite"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
            </nav>
        @endforeach
        <button type="button" class="rt-shift-distribution__auto-back" data-distribution-auto-back
            x-cloak x-show="distributionMode === 'auto'" :inert="distributionMode !== 'auto'" style="display: none"
            x-on:click="distributionMode = 'list'; $nextTick(() => $refs.distributionAutoTrigger?.focus({ preventScroll: true }))"
            aria-label="Zur Schichtliste zurück" title="Zur Schichtliste zurück"><i class="far fa-arrow-left" aria-hidden="true"></i><span>Schichtliste</span></button>
    </footer>
</div>
