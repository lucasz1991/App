<section class="space-y-3 border-t border-rt-border py-4 dark:border-rt-dark-border" aria-label="Fahrzeiten und Routen" x-data x-init="if (!@js($loaded)) $nextTick(() => $wire.load())">
    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold">Fahrzeiten & Routen</h3><x-ui.buttons.button-basic type="button" size="sm" wire:click="load" wire:loading.attr="disabled">Neu prüfen</x-ui.buttons.button-basic></div>
    <p wire:loading wire:target="load,showRoute" role="status" class="text-sm">Fahrt wird geprüft …</p>
    @if(!$loaded)<p class="text-sm text-rt-muted">Die Kandidaten sind bereits bedienbar. Fahrzeiten werden nachgeladen.</p>@endif
    @foreach($assessments as $employeeId => $assessment)
        <details class="border-b border-rt-border pb-3 text-sm dark:border-rt-dark-border" wire:key="travel-{{ $shiftId }}-{{ $revision }}-{{ $employeeId }}">
            <summary class="cursor-pointer py-2"><strong>{{ $names[$employeeId] ?? 'Mitarbeiter' }}</strong> · @if(collect($assessment['issues'])->contains(fn($issue) => str_starts_with($issue['code'], 'travel_')))<span class="text-red-700 dark:text-red-300">Fahrzeitkonflikt</span>@elseif($assessment['warnings'])<span class="text-amber-700 dark:text-amber-300">Fahrt ungeprüft</span>@else<span>Übergänge geprüft</span>@endif</summary>
            <div class="space-y-3 pt-2">
                @forelse($assessment['travel'] as $direction => $leg)
                    <div><p class="font-semibold">{{ $direction === 'before' ? 'Vom vorherigen Einsatz' : 'Zum folgenden Einsatz' }} · {{ $leg['origin_label'] }} → {{ $leg['destination_label'] }}</p>
                        @if($leg['status'] === 'known')
                            <p>{{ $leg['duration_minutes'] }} min Fahrt + {{ $leg['reserve_minutes'] }} min Reserve + {{ $leg['rest_minutes'] }} min Ruhezeit · {{ $leg['available_minutes'] }} min verfügbar</p>
                            @if($leg['missing_minutes'] > 0)<p class="text-red-700 dark:text-red-300">{{ $leg['missing_minutes'] }} Minuten fehlen. Eine begründete Ausnahme ist erforderlich.</p>@endif
                            @unless($leg['same_location'])<button type="button" class="min-h-11 underline" wire:click="showRoute({{ $employeeId }}, '{{ $direction }}')" wire:loading.attr="disabled">Route anzeigen</button>@endunless
                        @else<p class="text-amber-700 dark:text-amber-300">Fahrt ungeprüft: {{ \App\Services\Routing\TravelAssessmentService::reasonLabel($leg['reason']) }}.</p>@endif
                    </div>
                @empty<p class="text-rt-muted">Keine benachbarten Einsätze. Ein privater Anfahrtsweg wird nicht berechnet.</p>@endforelse
                @if(isset($assessment['arrival']))
                    @php($arrival = $assessment['arrival'])
                    <div class="border-t border-rt-border pt-3 dark:border-rt-dark-border"><p class="font-semibold">Anreise ab letzter Standortmessung</p>
                        @if($arrival['status'] === 'known')
                            <p>{{ (int) ceil($arrival['duration_seconds'] / 60) }} min reine Fahrt · Abfahrtannahme: jetzt</p><p class="text-rt-muted">Messung {{ \Carbon\CarbonImmutable::parse($arrival['measured_at'])->setTimezone('Europe/Berlin')->format('d.m. H:i') }} · Genauigkeit {{ round($arrival['accuracy_m']) }} m. Laufende Dienste und Ruhezeiten bleiben maßgeblich.</p>
                            @if($arrival['late_hint'])<p class="text-amber-700 dark:text-amber-300">Mögliche verspätete Anreise ab diesem Messpunkt; bitte die tatsächliche Verfügbarkeit klären.</p>@endif
                            <button type="button" class="min-h-11 underline" wire:click="showRoute({{ $employeeId }}, 'arrival')" wire:loading.attr="disabled">Anreiseroute anzeigen</button>
                        @else<p class="text-rt-muted">Aktueller Anfahrtsweg nicht verfügbar. Die geplante Standortkette wird weiter geprüft.</p>@endif
                    </div>
                @endif
            </div>
        </details>
    @endforeach
    @error('route')<p role="alert" class="text-sm text-amber-700 dark:text-amber-300">{{ $message }}</p>@enderror
    @if($geometry)
        <div wire:ignore wire:key="routing-map-{{ md5(json_encode($geometry)) }}" x-data="rtRoutingMap(@js($geometry), @js(route('operations.routing.tile', ['z' => '__z__', 'x' => '__x__', 'y' => '__y__'], false)))" class="relative z-0 h-64 w-full overflow-hidden rounded-lg border border-rt-border" aria-label="Berechnete Route" role="img"></div>
    @endif
</section>
