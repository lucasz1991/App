@php
    $key = $employeeId.':'.$direction;
    $known = ($leg['status'] ?? null) === 'known' && !in_array($state, ['pending', 'disabled'], true);
    $sameSite = !$arrivalMode && ($leg['same_location'] ?? false);
    $canMap = $known && !$sameSite;
    $duration = $known ? (int) ceil(($leg['duration_seconds'] ?? 0) / 60) : null;
    $distance = $known ? number_format(($leg['distance_meters'] ?? 0) / 1000, 1, ',', '.').' km' : null;
    $slack = $known && !$arrivalMode ? (int) floor(($leg['available_seconds'] - $leg['duration_seconds'] - ($leg['reserve_minutes'] + $leg['rest_minutes']) * 60) / 60) : null;
    $missing = !$arrivalMode && $known ? (int) ($leg['missing_minutes'] ?? 0) : 0;
    $tone = $missing > 0 ? 'conflict' : (!$known || ($arrivalMode && ($leg['late_hint'] ?? false)) ? 'warning' : 'known');
    $label = $arrivalMode ? 'Aktueller Standort' : ($direction === 'before' ? 'Vorher' : 'Nachher');
    $origin = $arrivalMode ? 'Letzter Standort' : ($leg['origin_label'] ?: 'Einsatzort unbestätigt');
    $destination = $arrivalMode ? ($shift->location_snapshot['name'] ?? $shift->location_name ?: 'Einsatzort unbestätigt') : ($leg['destination_label'] ?: 'Einsatzort unbestätigt');
    $reason = $arrivalMode ? ($leg['reason_label'] ?? \App\Services\Routing\TravelAssessmentService::reasonLabel($leg['reason'] ?? 'point_unavailable')) : \App\Services\Routing\TravelAssessmentService::reasonLabel($leg['reason'] ?? null);
    $hasMap = $geometry !== [] && (int) ($geometryBasis['employee_id'] ?? 0) === (int) $employeeId && ($geometryBasis['direction'] ?? null) === $direction;
    $previewId = 'travel-'.$this->getId().'-'.$employeeId.'-'.$direction;
@endphp
<x-ui.dropdown.anchor-dropdown
    align="left" width="96" :max-height="540" :open-on-hover="false"
    content-role="dialog" content-label="Route {{ $label }} · {{ $names[$employeeId] ?? 'Mitarbeiter' }}"
    :dropdown-id="$previewId" layer-group="travel-previews-{{ $this->getId() }}"
    class="rt-travel__anchor" trigger-classes="w-full" dropdown-classes="rt-travel-popover"
    content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    x-on:dropdown-open.stop="{{ $canMap ? 'requestRoute('.(int) $employeeId.', '.\Illuminate\Support\Js::from($direction).')' : '' }}"
    x-on:dropdown-closed.stop="{{ 'dismissRoute('.\Illuminate\Support\Js::from($key).')' }}"
>
    <x-slot:trigger>
        <button type="button" class="rt-travel__route" data-state="{{ $tone }}" aria-label="{{ $label }}: {{ $known ? ($sameSite ? 'Gleicher Ort' : $duration.' min Fahrt · '.$distance) : 'Fahrt ungeprüft' }}{{ $missing ? ', '.$missing.' min fehlen' : '' }}. Routendetails öffnen">
            <span class="rt-travel__direction"><i class="far {{ $arrivalMode ? 'fa-map-marker-alt' : 'fa-route' }}" aria-hidden="true"></i><span>{{ $label }}</span></span>
            <span class="rt-travel__value">
                @if($known)
                    <strong>{{ $sameSite ? 'Gleicher Ort' : $duration.' min' }}</strong>
                    @unless($sameSite)
                        <small>{{ $distance }}</small>
                    @endunless
                @else
                    <strong>{{ $state === 'pending' ? 'Ausstehend' : 'Ungeprüft' }}</strong>
                @endif
            </span>
            <span class="rt-travel__balance">
                @if($missing > 0)
                    {{ $missing }} min fehlen
                @elseif($slack !== null)
                    {{ max(0, $slack) }} min frei
                @elseif($arrivalMode && $known && ($leg['late_hint'] ?? false))
                    Evtl. verspätet
                @endif
            </span>
            <i class="far fa-chevron-right rt-travel__chevron" aria-hidden="true"></i>
        </button>
    </x-slot:trigger>
    <x-slot:content>
        <article class="rt-travel-preview" data-rt-dropdown-keep-open data-route-preview="{{ $key }}">
            <header class="rt-travel-preview__header">
                <div class="rt-travel-preview__places"><strong>{{ $origin }}</strong><span><i class="far fa-arrow-right" aria-hidden="true"></i>{{ $destination }}</span></div>
                <button type="button" class="rt-travel__icon" x-on:click.stop="close(true)" aria-label="Routenvorschau schließen"><i class="far fa-times" aria-hidden="true"></i></button>
            </header>
            @if($canMap)
                <div class="rt-travel-preview__map-slot">
                    <p class="rt-travel-preview__map-state" x-show="activeKey === @js($key) && loading" role="status">Route wird geladen …</p>
                    <p class="rt-travel-preview__map-state" x-show="activeKey === @js($key) && !loading && errorText" x-text="errorText" role="status" x-cloak></p>
                    @if($hasMap)
                        <template x-if="open && activeKey === @js($key) && !loading && !errorText">
                            <div wire:ignore wire:key="route-map-{{ $employeeId }}-{{ $direction }}-{{ md5(json_encode($geometry)) }}" x-data="rtRoutingMap(@js($geometry), @js(route('operations.routing.tile', ['z' => '__z__', 'x' => '__x__', 'y' => '__y__'], false)))" class="rt-travel-preview__map" aria-label="Berechnete Route" role="region">
                                <p x-show="errorText" x-cloak x-text="errorText" role="status" class="rt-travel-preview__map-error"></p>
                            </div>
                        </template>
                    @else
                        <p class="rt-travel-preview__map-state" x-show="activeKey === @js($key) && !loading && !errorText" x-cloak role="status">Route nicht verfügbar.</p>
                    @endif
                </div>
            @elseif(!$known)
                <p class="rt-travel-preview__notice" role="status">{{ $state === 'pending' ? 'Fahrzeitprüfung ausstehend.' : $reason }}</p>
            @else
                <p class="rt-travel-preview__notice">Gleicher bestätigter Einsatzort</p>
            @endif
            @if($known && !$arrivalMode)
                <dl class="rt-travel-preview__metrics">
                    <div><dt>Fahrt</dt><dd>{{ $duration }} min</dd></div>
                    <div><dt>Reserve</dt><dd>{{ $leg['reserve_minutes'] }} min</dd></div>
                    <div><dt>Ruhezeit</dt><dd>{{ $leg['rest_minutes'] }} min</dd></div>
                    <div><dt>Verfügbar</dt><dd>{{ $leg['available_minutes'] }} min</dd></div>
                </dl>
                <footer class="rt-travel-preview__footer" data-state="{{ $tone }}">
                    <span>{{ \Carbon\CarbonImmutable::parse($leg['departure'])->setTimezone($shift->timezone)->format('d.m. H:i') }} → {{ \Carbon\CarbonImmutable::parse($leg['departure'])->addSeconds($leg['available_seconds'])->setTimezone($shift->timezone)->format('d.m. H:i') }}</span>
                    <strong>{{ $missing > 0 ? $missing.' min fehlen' : max(0, $slack).' min frei' }}</strong>
                </footer>
            @elseif($arrivalMode && $known)
                <dl class="rt-travel-preview__metrics">
                    <div><dt>Fahrt</dt><dd>{{ $duration }} min</dd></div>
                    <div><dt>Strecke</dt><dd>{{ $distance }}</dd></div>
                    <div><dt>Messung</dt><dd>{{ \Carbon\CarbonImmutable::parse($leg['measured_at'])->setTimezone($shift->timezone)->format('H:i') }}</dd></div>
                    <div><dt>Genauigkeit</dt><dd>{{ round($leg['accuracy_m']) }} m</dd></div>
                </dl>
                <p class="rt-travel-preview__notice">Abfahrtannahme: jetzt · Anreisehinweis</p>
                @if($leg['late_hint'] ?? false)
                    <p class="rt-travel-preview__notice" data-state="warning">Mögliche verspätete Anreise</p>
                @endif
            @endif
        </article>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
