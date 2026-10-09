@php
    $calendarPanelId = 'calendar-shift-detail-'.$this->getId();
    $calendarTabsPrefix = 'calendar-shift-tabs-'.$this->getId();
    if ($selectedShift) {
        $panelStatus = $selectedShift->status instanceof \BackedEnum ? $selectedShift->status->value : (string) $selectedShift->status;
        $panelAssignments = $selectedShift->assignments
            ->sortBy(fn ($assignment) => ['confirmed' => 0, 'requested' => 1, 'declined' => 2, 'cancelled' => 3][$assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status] ?? 9)
            ->values();
        $panelReserved = $panelAssignments->filter(fn ($assignment) => in_array($assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status, ['requested', 'confirmed'], true))->count();
        $panelStart = $selectedShift->starts_at?->setTimezone($displayTimezone)->locale('de');
        $panelEnd = $selectedShift->ends_at?->setTimezone($displayTimezone)->locale('de');
        $panelWindow = $panelStart && $panelEnd
            ? ($panelStart->isSameDay($panelEnd)
                ? $panelStart->isoFormat('dd, DD.MM.').' · '.$panelStart->format('H:i').'–'.$panelEnd->format('H:i')
                : $panelStart->isoFormat('dd, DD.MM. HH:mm').' – '.$panelEnd->isoFormat('dd, DD.MM. HH:mm'))
            : null;
        $panelLocation = $selectedShift->location_name ?: $selectedShift->order?->location_name;
        $panelClosed = in_array($panelStatus, ['cancelled', 'completed'], true);
        $panelOpenSlots = $panelClosed ? 0 : max(0, $selectedShift->required_staff - $panelReserved);
        $panelTabs = [
            'overview' => ['label' => 'Übersicht'],
            'staffing' => ['label' => 'Besetzung', 'count' => $panelReserved.'/'.$selectedShift->required_staff],
        ];
    }
@endphp
{{-- Gleiches Seitenpanel wie im Schichtplan (Geometrie, Einfahrt, Lade-Skelett über rtShiftDetailDrawer). --}}
<x-operations.panel :id="$calendarPanelId" show-expression="detailVisible" label="Schichtdetails" data-calendar-shift-panel>
    <div class="rt-ops-panel__mode" data-panel-mode="detail" x-data="{ tab: 'overview' }" wire:key="calendar-panel-{{ $selectedShift?->id ?? 'none' }}">
        <div class="rt-ops-panel__accent" aria-hidden="true"></div>

        <header class="rt-ops-panel__header" x-show="loading || error" style="display: none" data-calendar-panel-placeholder>
            <span class="rt-ops-panel__icon" aria-hidden="true"><i class="far fa-clock"></i></span>
            <div class="rt-ops-panel__heading" aria-hidden="true">
                <span class="rt-ops-panel__eyebrow">Schichtdetails</span>
                <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--title"></span>
                <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--line"></span>
            </div>
            <div class="rt-ops-panel__actions">
                <button type="button" class="rt-ops-panel__icon-button" x-on:click="$dispatch('close')" aria-label="Schichtdetails schließen" title="Schichtdetails schließen"><i class="far fa-times" aria-hidden="true"></i></button>
            </div>
        </header>

        @if($selectedShift)
            <x-operations.panel.header x-show="!loading && !error" eyebrow="Schichtdetails" :title="$selectedShift->title"
                :subtitle="collect([$selectedShift->order?->order_number, $selectedShift->order?->title, $selectedShift->order?->customer?->company_name])->filter()->implode(' · ')"
                icon="fa-clock" close-label="Schichtdetails schließen">
                <x-slot:actions>
                    <x-ui.buttons.button-basic type="button" wire:click="openShift({{ $selectedShift->id }})" wire:loading.attr="disabled" wire:target="openShift" data-calendar-panel-plan>
                        <i class="far fa-external-link" aria-hidden="true"></i>Im Schichtplan
                    </x-ui.buttons.button-basic>
                </x-slot:actions>
                <x-slot:meta>
                    @if(filled($selectedShift->role_name))
                        <span class="rt-ops-panel__fact"><i class="far fa-id-badge" aria-hidden="true"></i>{{ $selectedShift->role_name }}</span>
                    @endif
                    @if($panelWindow)
                        <span class="rt-ops-panel__fact rt-ops-panel__fact--strong"><i class="far fa-calendar" aria-hidden="true"></i><time datetime="{{ $panelStart->toIso8601String() }}">{{ $panelWindow }}</time></span>
                    @endif
                    @if(filled($panelLocation))
                        <span class="rt-ops-panel__fact"><i class="far fa-map-marker-alt" aria-hidden="true"></i>{{ $panelLocation }}</span>
                    @endif
                    <span class="rt-ops-panel__meta-end">
                        <x-operations.status :value="$panelStatus" :label="$selectedShift->status->label()" />
                        @unless($panelClosed)
                            <span class="rt-ops-panel__pill" data-tone="{{ $panelOpenSlots === 0 ? 'success' : 'critical' }}">{{ $panelReserved }}/{{ $selectedShift->required_staff }} eingeplant</span>
                        @endunless
                    </span>
                </x-slot:meta>
            </x-operations.panel.header>
            <x-operations.panel.tabs x-show="!loading && !error" :tabs="$panelTabs" label="Bereiche der Schichtdetails" :id-prefix="$calendarTabsPrefix" />
        @else
            <x-operations.panel.header x-show="!loading && !error" eyebrow="Schichtdetails" title="Keine Schicht ausgewählt" icon="fa-clock" close-label="Schichtdetails schließen" />
        @endif

        <div class="rt-ops-panel__body rt-modal-content" data-calendar-panel-body>
            <div x-show="loading" x-cloak>
                <p class="mb-4 flex items-center gap-2 text-sm text-rt-muted dark:text-rt-dark-muted" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i>Schichtdetails werden geladen …</p>
                <x-ui.loading.skeleton variant="list" :rows="4" />
            </div>
            <div x-show="!loading && error" x-cloak class="space-y-4 py-6">
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted" x-text="error" role="alert"></p>
                <x-ui.buttons.button-basic type="button" size="sm" x-on:click="openShiftDetail(requestedShiftId)"><i class="far fa-redo" aria-hidden="true"></i>Erneut versuchen</x-ui.buttons.button-basic>
            </div>
            <div x-show="!loading && !error" x-bind:aria-busy="loading" data-calendar-panel-content>
                @if($selectedShift)
                    <x-operations.panel.tab name="overview" :id-prefix="$calendarTabsPrefix">
                        @if($panelOpenSlots > 0)
                            <x-operations.panel.group tone="warning">
                                <div class="rt-ops-panel__notice">
                                    <i class="far fa-exclamation-triangle" aria-hidden="true"></i>
                                    <div class="rt-ops-panel__notice-text">
                                        <strong>{{ $panelOpenSlots }} {{ $panelOpenSlots === 1 ? 'Platz' : 'Plätze' }} offen</strong>
                                        <span>{{ $panelReserved }} von {{ $selectedShift->required_staff }} eingeplant</span>
                                    </div>
                                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="openShift({{ $selectedShift->id }})" wire:loading.attr="disabled">Besetzen</x-ui.buttons.button-basic>
                                </div>
                            </x-operations.panel.group>
                        @endif
                        @if($panelStart && $panelEnd)
                            <x-operations.panel.group title="Zeit & Ort">
                                <div class="rt-ops-panel__previews">
                                    <x-operations.timeline-mini-calendar :start="$panelStart" :end="$panelEnd" />
                                    <x-operations.timeline-mini-map :preview="\App\Support\Operations\TimelineLocationPreview::fromShift($selectedShift)" :location="$panelLocation" />
                                </div>
                            </x-operations.panel.group>
                        @endif
                        <x-operations.panel.group title="Einsatz">
                            <dl class="rt-ops-panel__rows">
                                <x-operations.panel.row label="Leistung">{{ $selectedShift->order ? $selectedShift->order->order_number.' · '.$selectedShift->order->title : 'Leistung nicht verfügbar' }}</x-operations.panel.row>
                                <x-operations.panel.row label="Kunde">{{ $selectedShift->order?->customer?->company_name ?: '—' }}</x-operations.panel.row>
                                <x-operations.panel.row label="Tätigkeit">{{ $selectedShift->role_name ?: '—' }}</x-operations.panel.row>
                                <x-operations.panel.row label="Einsatzort">{{ $panelLocation ?: 'Kein Einsatzort hinterlegt' }}</x-operations.panel.row>
                                <x-operations.panel.row label="Zeitfenster">
                                    <span class="rt-ops-panel__num">{{ $panelStart?->format('d.m.Y H:i') }} – {{ $panelEnd?->format('d.m.Y H:i') }}</span>
                                    <small>{{ $displayTimezone }}</small>
                                </x-operations.panel.row>
                                <x-operations.panel.row label="Geplante Pause"><span class="rt-ops-panel__num">{{ (int) $selectedShift->planned_break_minutes }} Minuten</span></x-operations.panel.row>
                                @if($selectedShift->relationLoaded('qualifications'))
                                    <x-operations.panel.row label="Nachweise">{{ $selectedShift->qualifications->pluck('name')->filter()->implode(', ') ?: 'Keine besonderen Nachweise' }}</x-operations.panel.row>
                                @endif
                                <x-operations.panel.row label="Planstand">
                                    @if((int) $selectedShift->published_revision === 0)
                                        Noch nicht veröffentlicht
                                    @elseif((int) $selectedShift->published_revision !== (int) $selectedShift->revision)
                                        Änderung unveröffentlicht · Rev. {{ $selectedShift->revision }}
                                    @else
                                        Veröffentlicht · Rev. {{ $selectedShift->revision }}
                                    @endif
                                </x-operations.panel.row>
                            </dl>
                        </x-operations.panel.group>
                    </x-operations.panel.tab>
                    <x-operations.panel.tab name="staffing" :id-prefix="$calendarTabsPrefix">
                        <x-operations.panel.group title="Eingeteilte Mitarbeitende" :meta="$panelReserved.' von '.$selectedShift->required_staff">
                            <div class="rt-ops-panel__list">
                                @forelse($panelAssignments as $assignment)
                                    @php
                                        $assignmentValue = $assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status;
                                    @endphp
                                    <div @class(['flex min-h-16 items-center gap-3 px-3.5 py-2.5', 'opacity-60' => in_array($assignmentValue, ['declined', 'cancelled'], true)]) wire:key="calendar-assignment-{{ $assignment->id }}">
                                        <div class="min-w-0 flex-1">
                                            @if($assignment->user)<x-user.person-anchor-preview :user="$assignment->user" :show-presence="false" :show-email="false" :size="9" />@else<span class="ops-muted">Unbekannter Mitarbeiter</span>@endif
                                            <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ method_exists($assignment->status, 'label') ? $assignment->status->label() : \Illuminate\Support\Str::headline($assignmentValue) }}@if($assignment->note) · {{ $assignment->note }}@endif</p>
                                        </div>
                                    </div>
                                @empty
                                    <p class="px-4 py-7 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch niemand eingeteilt.</p>
                                @endforelse
                                @for($slot = 0; $slot < $panelOpenSlots; $slot++)
                                    <div class="flex min-h-12 items-center gap-3 px-3.5 py-2 text-sm text-rt-muted dark:text-rt-dark-muted"><span class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-dashed border-rt-line dark:border-rt-dark-line" aria-hidden="true"><i class="far fa-user-plus"></i></span>Platz offen</div>
                                @endfor
                            </div>
                        </x-operations.panel.group>
                    </x-operations.panel.tab>
                @endif
            </div>
        </div>
        @if($selectedShift)
            <x-operations.panel.footer x-show="!loading && !error">
                <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('close')">Schließen</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" mode="primary" wire:click="openShift({{ $selectedShift->id }})" wire:loading.attr="disabled" wire:target="openShift">Im Schichtplan bearbeiten</x-ui.buttons.button-basic>
            </x-operations.panel.footer>
        @endif
    </div>
</x-operations.panel>
