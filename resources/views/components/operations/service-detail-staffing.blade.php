@props(['item'])
@php
    $activeEntries = $item->shiftDetails->where('active', true)->values();
    $closedEntries = $item->shiftDetails->where('active', false)->values();
    $viewer = auth()->user();
    $canViewProfiles = $viewer?->canViewManagementDashboard() && $viewer->can('employees.view');
@endphp
<section class="rt-service-detail-tab rt-service-detail-staffing" data-service-staffing-tab aria-label="Besetzung der gesamten Leistung">
    <header class="rt-service-detail-tab__heading">
        <div><h3>Menschen & Einsatzplätze</h3><p>Besetzung je Schicht · angefragt und bestätigt getrennt erkennbar</p></div>
        <div class="rt-service-detail-tab__total"><strong>{{ $item->summary['reserved'] }}<span>/{{ $item->summary['required'] }}</span></strong><span>Einsatzplätze</span></div>
    </header>
    <div class="rt-service-detail-staffing__facts" aria-label="Aktive Besetzung">
        <span><i class="far fa-circle-check" aria-hidden="true"></i><strong>{{ $item->summary['confirmed'] }}</strong> bestätigt</span>
        <span><i class="far fa-clock" aria-hidden="true"></i><strong>{{ $item->summary['reserved'] - $item->summary['confirmed'] }}</strong> angefragt</span>
        <span data-open="{{ $item->summary['open'] > 0 ? 'true' : 'false' }}"><i class="far fa-user-plus" aria-hidden="true"></i><strong>{{ $item->summary['open'] }}</strong> offen</span>
    </div>
    @if($activeEntries->isEmpty())<div class="rt-service-detail-tab__empty"><i class="far fa-users" aria-hidden="true"></i><h4>{{ $item->count === 0 ? 'Noch keine Einsatzplätze' : 'Keine aktive Besetzung' }}</h4><p>{{ $item->count === 0 ? 'Sobald Schichten angelegt sind, erscheint hier die zugehörige Besetzung.' : 'Alle Schichten sind abgeschlossen oder storniert. Ihre Besetzung bleibt im Verlauf unten sichtbar.' }}</p></div>@endif
    @foreach(['active'=>$activeEntries, 'closed'=>$closedEntries] as $group => $entries)
        @if($entries->isNotEmpty())
            @if($group === 'closed')
                <details class="rt-service-detail-staffing__archive" data-service-staffing-archive><summary><i class="far fa-clock-rotate-left" aria-hidden="true"></i>Abgeschlossene & stornierte Schichten <span>{{ $entries->count() }}</span></summary><p class="rt-service-detail-tab__note">Historische Zuteilungen · nicht im aktiven Personalbedarf enthalten</p>
            @endif
            <div class="rt-service-detail-staffing__groups">
                @foreach($entries as $entry)
                    @php
                        $shift = $entry->shift;
                        $currentAssignments = $entry->assignments->where('blocking', true);
                        $pastAssignments = $entry->assignments->where('blocking', false);
                    @endphp
                    <section class="rt-service-detail-staffing__shift" data-service-staffing-shift="{{ $shift->id }}" data-active="{{ $entry->active ? 'true' : 'false' }}" wire:key="service-staffing-shift-{{ $item->id }}-{{ $shift->id }}" aria-label="Besetzung: {{ $shift->title ?: 'Schicht' }}">
                        <header class="rt-service-detail-staffing__shift-heading"><div><h4>{{ $shift->title ?: 'Schicht' }}</h4><p><i class="far fa-calendar-day" aria-hidden="true"></i>{{ $entry->from?->format('d.m.Y H:i') ?? 'Beginn offen' }} – {{ $entry->until?->format('d.m.Y H:i') ?? 'Ende offen' }}</p><p><i class="far fa-location-dot" aria-hidden="true"></i>{{ $shift->location_name ?: 'Einsatzort offen' }} · {{ $shift->role_name ?: 'Rolle offen' }}</p></div><x-operations.status :value="$shift->status->value" :label="$shift->status->label()" /></header>
                        <ul class="rt-service-detail-staffing__people" aria-label="{{ $entry->active ? 'Aktuelle Einsatzplätze' : 'Historische Zuteilungen' }}">
                            @foreach($currentAssignments as $assignment)
                                <li class="rt-service-detail-staffing__person" data-service-detail-assignment="{{ $assignment['id'] }}" wire:key="service-detail-assignment-{{ $item->id }}-{{ $assignment['id'] }}">
                                    @if($assignment['user'])
                                        @if($canViewProfiles)
                                            <a href="{{ route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $assignment['user']->id) }}" wire:navigate class="rt-service-detail-staffing__profile" aria-label="Profil öffnen: {{ $assignment['user']->name }}" data-service-detail-profile="{{ $assignment['user']->id }}"><x-user.public-info :user="$assignment['user']" :size="12" :show-email="false" :show-presence="false" :local-initials="true" /></a>
                                        @else
                                            <x-user.public-info :user="$assignment['user']" :size="12" :show-email="false" :show-presence="false" :local-initials="true" />
                                        @endif
                                    @else
                                        <div class="rt-service-detail-staffing__unavailable"><span aria-hidden="true">?</span><strong>Eingeplante Person nicht verfügbar</strong></div>
                                    @endif
                                    <span class="rt-service-detail-status"><i class="far {{ $assignment['status'] === \App\Enums\ShiftAssignmentStatus::Confirmed ? 'fa-circle-check' : 'fa-clock' }}" aria-hidden="true"></i><x-operations.status :value="$assignment['status']->value" :label="$assignment['status']->label()" /></span>
                                </li>
                            @endforeach
                            @for($slot = 0; $slot < $entry->summary['open']; $slot++)
                                <li class="rt-service-detail-staffing__person rt-service-detail-staffing__person--open" data-service-detail-open-seat="{{ $shift->id }}-{{ $slot + 1 }}"><div class="rt-service-detail-staffing__vacancy"><span class="rt-service-detail-staffing__vacancy-avatar" aria-hidden="true">?<i></i></span><div><strong>Offener Einsatzplatz {{ $slot + 1 }}</strong><span>{{ $shift->role_name ?: 'Rolle offen' }} · noch nicht eingeplant</span></div></div><span class="rt-service-detail-staffing__open-label">Offen</span></li>
                            @endfor
                        </ul>
                        @if($currentAssignments->isEmpty() && $entry->summary['open'] === 0)<p class="rt-service-detail-tab__note">Keine aktuellen Zuteilungen.</p>@endif
                        @if($pastAssignments->isNotEmpty())
                            <details class="rt-service-detail-staffing__previous"><summary>Frühere Zuteilungen <span>{{ $pastAssignments->count() }}</span></summary><ul class="rt-service-detail-staffing__people">
                                @foreach($pastAssignments as $assignment)
                                    <li class="rt-service-detail-staffing__person" data-service-detail-past-assignment="{{ $assignment['id'] }}">
                                        @if($assignment['user'])
                                            @if($canViewProfiles)
                                                <a href="{{ route($viewer->usesAdminLayout() ? 'admin.user-profile' : 'employees.show', $assignment['user']->id) }}" wire:navigate class="rt-service-detail-staffing__profile" aria-label="Profil öffnen: {{ $assignment['user']->name }}" data-service-detail-profile="{{ $assignment['user']->id }}"><x-user.public-info :user="$assignment['user']" :size="10" :show-email="false" :show-presence="false" :local-initials="true" /></a>
                                            @else
                                                <x-user.public-info :user="$assignment['user']" :size="10" :show-email="false" :show-presence="false" :local-initials="true" />
                                            @endif
                                        @else<span>Eingeplante Person nicht verfügbar</span>@endif
                                        <x-operations.status :value="$assignment['status']->value" :label="$assignment['status']->label()" />
                                    </li>
                                @endforeach
                            </ul></details>
                        @endif
                    </section>
                @endforeach
            </div>
            @if($group === 'closed')</details>@endif
        @endif
    @endforeach
    <p class="rt-service-detail-tab__note">Einteilung und Freigabe erfolgen weiterhin im Schichtplan mit den bestehenden Prüfungen.</p>
</section>
