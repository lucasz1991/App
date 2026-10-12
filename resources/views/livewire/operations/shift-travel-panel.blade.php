<section class="space-y-3 border-t border-rt-border py-4 dark:border-rt-dark-border" aria-label="Fahrzeiten und Routen" x-data x-intersect.once="if (!@js($loaded) && !@js($parentManaged)) $wire.load()">
    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold">Fahrzeiten & Routen</h3><x-ui.buttons.button-basic type="button" size="sm" wire:click="load" wire:loading.attr="disabled">Neu prüfen</x-ui.buttons.button-basic></div>
    <p class="text-sm text-rt-muted"><strong class="text-rt-text dark:text-rt-dark-text">{{ $shift->title }}</strong> · {{ $shift->starts_at->setTimezone($shift->timezone)->format('d.m.Y H:i') }}–{{ $shift->ends_at->setTimezone($shift->timezone)->format('d.m.Y H:i') }} · {{ $shift->location_snapshot['name'] ?? $shift->location_name ?: 'Einsatzort fehlt' }}@unless(($shift->location_snapshot['confirmed'] ?? false) === true) · Einsatzort noch nicht bestätigt@endunless</p>
    <p wire:loading wire:target="load,showRoute" role="status" class="text-sm">Fahrt wird geprüft …</p>
    @if(!$loaded)<p class="text-sm text-rt-muted">Die Kandidaten sind bereits bedienbar. Fahrzeiten werden nachgeladen.</p>@endif
    @error('travel')<p role="status" class="text-sm text-amber-700 dark:text-amber-300">{{ $message }}</p>@enderror
    @foreach($assessments as $employeeId => $assessment)
        @php
            $state = $assessment['display_state'];
            $stateLabels = ['known' => 'Übergänge geprüft', 'conflict' => 'Fahrzeitkonflikt', 'unknown' => 'Fahrt ungeprüft', 'pending' => 'Fahrt noch nicht geprüft', 'no_transition' => 'Keine benachbarten Einsätze', 'disabled' => 'Fahrzeitprüfung deaktiviert'];
        @endphp
        <details wire:ignore.self class="border-b border-rt-border pb-3 text-sm dark:border-rt-dark-border" wire:key="travel-{{ $shiftId }}-{{ $revision }}-{{ $employeeId }}">
            <summary class="min-h-11 cursor-pointer py-2"><strong>{{ $names[$employeeId] ?? 'Mitarbeiter' }}</strong> · <span @class(['text-red-700 dark:text-red-300' => $state === 'conflict', 'text-amber-700 dark:text-amber-300' => in_array($state, ['unknown', 'pending'], true), 'text-rt-muted' => in_array($state, ['disabled', 'no_transition'], true)])>{{ $stateLabels[$state] }}</span></summary>
            <div class="space-y-3 pt-2">
                @if($state === 'pending')<p role="status" class="text-rt-muted">Die aktuelle Einsatzfolge benötigt eine Fahrzeitprüfung. Angezeigte Planungsdaten sind noch keine bestätigte Fahrtdauer.</p>@endif
                @if($state === 'disabled')<p class="text-rt-muted">Die Routenplanung ist in den Systemeinstellungen deaktiviert. Die übrigen Eignungsregeln bleiben wirksam.</p>@endif
                @forelse($assessment['travel'] as $direction => $leg)
                    @php
                        $departure = \Carbon\CarbonImmutable::parse($leg['departure'])->setTimezone($shift->timezone);
                        $nextStart = $departure->addSeconds($leg['available_seconds']);
                        $slack = $leg['status'] === 'known' ? (int) floor(($leg['available_seconds'] - $leg['duration_seconds'] - ($leg['reserve_minutes'] + $leg['rest_minutes']) * 60) / 60) : null;
                    @endphp
                    <div class="space-y-1 break-words"><p class="font-semibold">{{ $direction === 'before' ? 'Vorheriger Einsatz → diese Schicht' : 'Diese Schicht → nächster Einsatz' }}</p>
                        <p>{{ $leg['origin_label'] ?: 'Unbestätigter Einsatzort' }} → {{ $leg['destination_label'] ?: 'Unbestätigter Einsatzort' }}</p>
                        <p class="text-rt-muted">{{ $direction === 'before' ? $leg['title'] : $shift->title }} endet {{ $departure->format('d.m. H:i') }} · {{ $direction === 'before' ? $shift->title : $leg['title'] }} beginnt {{ $nextStart->format('d.m. H:i') }} ({{ $shift->timezone }})</p>
                        <p>{{ $leg['available_minutes'] }} min Übergangszeit · {{ $leg['rest_minutes'] }} min erforderliche Ruhezeit</p>
                        @if($leg['status'] === 'known' && $state !== 'pending')
                            <p>{{ $leg['duration_minutes'] }} min Fahrt@if(!$leg['same_location']) · {{ number_format($leg['distance_meters'] / 1000, 1, ',', '.') }} km@endif + {{ $leg['reserve_minutes'] }} min Reserve + {{ $leg['rest_minutes'] }} min Ruhezeit</p>
                            @if($leg['same_location'])<p class="text-rt-muted">Gleicher bestätigter Einsatzort: keine Fahrt und keine Fahrtreserve.</p>@endif
                            @if(($leg['cache_hit'] ?? false) && !empty($leg['calculated_at']))
                                <p class="text-xs text-rt-muted">Server-Cache · berechnet {{ \Carbon\CarbonImmutable::parse($leg['calculated_at'])->setTimezone('Europe/Berlin')->format('d.m. H:i:s') }}@if(!empty($leg['expires_at'])) · gültig bis {{ \Carbon\CarbonImmutable::parse($leg['expires_at'])->setTimezone('Europe/Berlin')->format('H:i:s') }}@endif. Vor einer verbindlichen Zuteilung wird die Fahrt frisch geprüft.</p>
                            @endif
                            @if($leg['missing_minutes'] > 0)<p class="text-red-700 dark:text-red-300">{{ $leg['missing_minutes'] }} Minuten fehlen. Eine begründete Ausnahme ist erforderlich.</p>@else<p>{{ max(0, $slack) }} min verbleiben nach Fahrt, Reserve und Ruhezeit.</p>@endif
                            @unless($leg['same_location'])<button type="button" class="min-h-11 underline" wire:click="showRoute({{ $employeeId }}, '{{ $direction }}')" wire:loading.attr="disabled">Route anzeigen</button>@endunless
                        @else<p class="text-amber-700 dark:text-amber-300">Fahrt ungeprüft: {{ \App\Services\Routing\TravelAssessmentService::reasonLabel($leg['reason']) }}.</p>@endif
                    </div>
                @empty
                    @unless($state === 'disabled')<p class="text-rt-muted">Keine benachbarten Einsätze. Ein privater Anfahrtsweg wird nicht berechnet.</p>@endunless
                @endforelse
                @if(isset($assessment['arrival']))
                    @php($arrival = $assessment['arrival'])
                    <div class="border-t border-rt-border pt-3 dark:border-rt-dark-border"><p class="font-semibold">Anreise ab letzter Standortmessung</p>
                        @if($arrival['status'] === 'known')
                            <p>{{ (int) ceil($arrival['duration_seconds'] / 60) }} min reine Fahrt · Abfahrtannahme: jetzt</p><p class="text-rt-muted">Messung {{ \Carbon\CarbonImmutable::parse($arrival['measured_at'])->setTimezone('Europe/Berlin')->format('d.m. H:i') }} · Genauigkeit {{ round($arrival['accuracy_m']) }} m. Laufende Dienste und Ruhezeiten bleiben maßgeblich.</p>
                            @if($arrival['late_hint'])<p class="text-amber-700 dark:text-amber-300">Mögliche verspätete Anreise ab diesem Messpunkt; bitte die tatsächliche Verfügbarkeit klären.</p>@endif
                            <button type="button" class="min-h-11 underline" wire:click="showRoute({{ $employeeId }}, 'arrival')" wire:loading.attr="disabled">Anreiseroute anzeigen</button>
                        @else<p class="text-rt-muted">Aktueller Anfahrtsweg nicht verfügbar: {{ $arrival['reason_label'] }} Die geplante Standortkette wird weiter geprüft.</p>@endif
                    </div>
                @endif
            </div>
        </details>
    @endforeach
    @error('route')<p role="alert" class="text-sm text-amber-700 dark:text-amber-300">{{ $message }}</p>@enderror
    @if($geometry)
        @if(($geometryCache['cache_hit'] ?? false) && !empty($geometryCache['calculated_at']))
            <p class="text-xs text-rt-muted">Route aus dem Server-Cache · berechnet {{ \Carbon\CarbonImmutable::parse($geometryCache['calculated_at'])->setTimezone('Europe/Berlin')->format('d.m. H:i:s') }}@if(!empty($geometryCache['expires_at'])) · gültig bis {{ \Carbon\CarbonImmutable::parse($geometryCache['expires_at'])->setTimezone('Europe/Berlin')->format('H:i:s') }}@endif. Vor einer verbindlichen Zuteilung wird die Fahrt frisch geprüft.</p>
        @endif
        <div wire:ignore wire:key="routing-map-{{ md5(json_encode($geometry)) }}" x-data="rtRoutingMap(@js($geometry), @js(route('operations.routing.tile', ['z' => '__z__', 'x' => '__x__', 'y' => '__y__'], false)))" class="relative z-0 h-64 w-full overflow-hidden rounded-lg border border-rt-border" aria-label="Berechnete Route" role="region"><p x-show="errorText" x-cloak x-text="errorText" role="status" class="pointer-events-none absolute bottom-6 left-2 right-2 z-[1000] rounded bg-rt-surface p-2 text-xs text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"></p></div>
    @endif
</section>
