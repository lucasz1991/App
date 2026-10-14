@props([
    'request' => null,
    'calendar' => null,
    'open' => false,
    'notice' => '',
    'requestedRevision' => null,
    'id' => null,
    'livewire' => true,
    'showExpression' => null,
    'showOffline' => true,
    'closeAction' => 'closeConsentRequest',
    'respondAction' => 'respondToRequest',
    'reviewAction' => 'openConsentRequest',
    'confirmModel' => 'requestConfirmed',
    'context' => 'request',
])
@php
    $scopedId = $id ?: 'personal-request-'.\Illuminate\Support\Str::uuid();
    $row = $request['row'] ?? null;
    $canRespond = $row && ($request['can_respond'] ?? false);
    $isCapacity = $row && $row['type'] === 'capacity';
    $start = $row ? \Carbon\CarbonImmutable::instance($row['starts_at'])->setTimezone($row['timezone']) : null;
    $end = $row ? \Carbon\CarbonImmutable::instance($row['ends_at'])->setTimezone($row['timezone']) : null;
    $acceptLabel = $isCapacity ? 'Verfügbar' : (! empty($row['changed_plan']) ? 'Änderung bestätigen' : 'Dienst bestätigen');
    $declineLabel = $isCapacity ? 'Nicht verfügbar' : 'Ablehnen';
    $stateKey = $scopedId.'-'.($row ? $row['type'].'-'.$row['id'].'-'.$row['revision'].'-'.$requestedRevision : 'unavailable');
    $durationMinutes = $row['duration_minutes'] ?? ($start && $end ? max(0, (int) (($end->getTimestamp() - $start->getTimestamp()) / 60)) : 0);
    $durationLabel = trim(($durationMinutes >= 60 ? intdiv($durationMinutes, 60).' Std. ' : '').($durationMinutes % 60 ? ($durationMinutes % 60).' Min.' : '')) ?: '0 Min.';
    $requestTabs = [
        $scopedId.'-assignment' => ['label' => 'Übersicht', 'icon' => 'fal fa-th-large'],
        $scopedId.'-details' => ['label' => 'Details', 'icon' => 'fal fa-list-ul'],
        $scopedId.'-calendar' => ['label' => 'Kalender', 'icon' => 'fal fa-calendar-alt'],
    ];
    $hasActivity = isset($activity) && trim((string) $activity) !== '';
    if ($hasActivity) {
        $requestTabs[$scopedId.'-activity'] = ['label' => 'Dienst', 'icon' => 'fal fa-train'];
    }
@endphp
<x-operations.modal :id="$scopedId" max-width="3xl" :show-expression="$showExpression" :show-offline="$showOffline" {{ $attributes }}>
    <x-slot:title>
        @if($open && $row)
            <span class="rt-request-dialog__intro" data-personal-request-heading>
                <span class="rt-request-dialog__eyebrow">{{ $row['label'] }} <span aria-hidden="true">·</span> Version {{ $row['revision'] }}</span>
                <span class="rt-request-dialog__title">{{ $row['title'] }}</span>
                <span class="rt-request-dialog__customer">{{ $row['customer'] ?: 'Kunde nicht hinterlegt' }}</span>
                @if($context === 'shift' && filled($row['status'] ?? null))<span class="rt-request-dialog__status"><x-operations.status :value="$row['status']" /></span>@endif
            </span>
        @else
            Anfrage prüfen
        @endif
    </x-slot:title>
    @if($open)
        <div class="rt-request-dialog rt-personal-form" data-personal-request-dialog data-has-activity="{{ $hasActivity ? 'true' : 'false' }}" wire:key="{{ $stateKey }}">
            @if($row)
                <x-ui.accordion.tabs :tabs="$requestTabs" :default="$scopedId.'-assignment'" :persist="false" :reset-on-open="true" :persist-key="$stateKey.'-tabs'" :aria-label="$context === 'shift' ? 'Dienst ansehen' : 'Anfrage prüfen'" content-class="rt-request-dialog__panels" class="rt-request-dialog__tabs" data-anim="none">
                    <x-ui.accordion.tab-panel :for="$scopedId.'-assignment'" :order="0" content-class="rt-request-dialog__panel">
                        <div class="rt-request-dialog__schedule">
                            <p class="rt-request-dialog__eyebrow">{{ $context === 'shift' ? 'Dein Dienst' : ($isCapacity ? 'Angefragte Verfügbarkeit' : 'Angefragter Dienst') }}</p>
                            <p class="rt-request-dialog__period"><span>{{ $start->locale('de')->isoFormat('dddd, D. MMM YYYY') }}</span><strong>{{ $start->format('H:i') }} <span aria-hidden="true">–</span><span class="sr-only">bis</span> {{ $end->format($start->isSameDay($end) ? 'H:i' : 'd.m. H:i') }}</strong></p>
                            <p class="rt-request-dialog__duration">{{ $durationLabel }} Zeitraum <span aria-hidden="true">·</span> {{ $row['break_minutes'] }} Min. geplante Pause</p>
                        </div>
                        <dl class="rt-request-dialog__facts rt-request-dialog__facts--assignment">
                            <div><dt><i class="fal fa-user-hard-hat" aria-hidden="true"></i>Deine Funktion</dt><dd>{{ $row['role'] ?: 'Noch nicht hinterlegt' }}</dd></div>
                            <div><dt><i class="fal fa-building" aria-hidden="true"></i>Kunde</dt><dd>{{ $row['customer'] ?: 'Noch nicht hinterlegt' }}</dd></div>
                        </dl>
                        <div class="rt-request-dialog__previews">
                            <div class="rt-request-dialog__mini-calendar"><x-operations.timeline-mini-calendar :start="$start" :end="$end" /></div>
                            <x-operations.personal-request-map :preview="$row['location_preview'] ?? null" :location="$row['location'] ?? null" :id="$scopedId.'-map'" />
                        </div>
                    </x-ui.accordion.tab-panel>
                    <x-ui.accordion.tab-panel :for="$scopedId.'-details'" :order="1" content-class="rt-request-dialog__panel">
                        <section class="rt-request-dialog__section" aria-label="Einsatzdetails">
                            <h3>Einsatzdetails</h3>
                            <div class="rt-request-dialog__detail-period">
                                <div class="rt-request-dialog__mini-calendar"><x-operations.timeline-mini-calendar :start="$start" :end="$end" /></div>
                                <dl class="rt-request-dialog__facts rt-request-dialog__facts--period">
                                    <div><dt>Beginn</dt><dd><time datetime="{{ $start->toIso8601String() }}">{{ $start->format('d.m.Y') }}<strong>{{ $start->format('H:i') }}</strong></time></dd></div>
                                    <div><dt>Ende</dt><dd><time datetime="{{ $end->toIso8601String() }}">{{ $end->format('d.m.Y') }}<strong>{{ $end->format('H:i') }}</strong></time></dd></div>
                                    <div><dt>Gesamter Zeitraum</dt><dd>{{ $durationLabel }}</dd></div>
                                    <div><dt>Geplante Pause</dt><dd>{{ $row['break_minutes'] }} Minuten</dd></div>
                                </dl>
                            </div>
                            <dl class="rt-request-dialog__facts rt-request-dialog__facts--details">
                                <div><dt>Einsatzort</dt><dd>{{ $row['location'] ?: 'Noch nicht hinterlegt' }}</dd></div>
                                <div><dt>Deine Funktion</dt><dd>{{ $row['role'] ?: 'Noch nicht hinterlegt' }}</dd></div>
                                <div><dt>Kunde</dt><dd>{{ $row['customer'] ?: 'Noch nicht hinterlegt' }}</dd></div>
                                @if(filled($row['order_reference'] ?? null))<div><dt>Auftragsnummer</dt><dd>{{ $row['order_reference'] }}</dd></div>@endif
                                <div><dt>Zeitzone</dt><dd>{{ $row['timezone'] }}<small>UTC{{ $start->format('P') }}@if($start->offset !== $end->offset) → UTC{{ $end->format('P') }}@endif</small></dd></div>
                            </dl>
                        </section>
                        @if(filled($row['notes'] ?? null))
                            <section class="rt-request-dialog__section rt-request-dialog__notes"><h3>Hinweise zum Einsatz</h3><p>{{ $row['notes'] }}</p></section>
                        @endif
                        @if(! empty($request['changes']))
                            <section class="rt-request-dialog__changes" aria-label="Veröffentlichte Planänderungen"><h3>Was sich geändert hat <span>Version {{ $row['revision'] }}</span></h3>
                                <dl>@foreach($request['changes'] as $change)<div><dt>{{ $change['label'] }}</dt><dd><span>Bisher: {{ $change['before'] }}</span><strong>Aktuell: {{ $change['after'] }}</strong></dd></div>@endforeach</dl>
                            </section>
                        @endif
                    </x-ui.accordion.tab-panel>
                    <x-ui.accordion.tab-panel :for="$scopedId.'-calendar'" :order="2" content-class="rt-request-dialog__panel"><x-operations.personal-request-calendar :calendar="$calendar" :start="$start" :end="$end" :selected-label="$context === 'shift' ? 'Dieser Dienst' : 'Diese Anfrage'" /></x-ui.accordion.tab-panel>
                    @if($hasActivity)
                        <x-ui.accordion.tab-panel :for="$scopedId.'-activity'" :order="3" content-class="rt-request-dialog__panel rt-request-dialog__activity">{{ $activity }}</x-ui.accordion.tab-panel>
                    @endif
                </x-ui.accordion.tabs>
            @endif
            @if($notice || ($request && ! $canRespond && filled($request['notice'] ?? null)))
                <div class="rt-request-dialog__notice" role="status"><p>{{ $notice ?: $request['notice'] }}</p>
                    @if($row && ! $canRespond && $row['revision'] !== $requestedRevision)
                        @if($livewire)<x-ui.buttons.button-basic type="button" wire:click="{{ $reviewAction }}('{{ $row['type'] }}', {{ $row['id'] }}, {{ $row['revision'] }})" wire:loading.attr="disabled">Aktuelle Anfrage prüfen</x-ui.buttons.button-basic>
                        @else<x-ui.buttons.button-basic type="button" disabled>Aktuelle Anfrage prüfen</x-ui.buttons.button-basic>@endif
                    @endif
                </div>
            @endif
        </div>
    @endif
    <x-slot:footer>
        <div class="rt-request-dialog__footer rt-personal-form" data-personal-request-footer>
            @if($open && $canRespond && $isCapacity)
                <div class="rt-request-dialog__confirmation"><p>Du bestätigst hier deine Verfügbarkeit. Die Kapazität wird anschließend separat freigegeben.</p>
                    @if($livewire)<x-ui.forms.checkbox :id="$scopedId.'-confirmed'" wire:model="{{ $confirmModel }}" label="Ich habe den angefragten Zeitraum geprüft." />
                    @else<x-ui.forms.checkbox :id="$scopedId.'-confirmed'" disabled label="Ich habe den angefragten Zeitraum geprüft." />@endif
                    @error($confirmModel)<p class="ops-errors" role="alert">{{ $message }}</p>@enderror
                </div>
            @endif
            <div class="rt-request-dialog__actions">
                @if(isset($actions))
                    {{ $actions }}
                @else
                @if($open && $canRespond)
                    @if($livewire)
                        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="{{ $respondAction }}(true)" wire:loading.attr="disabled" wire:target="{{ $respondAction }}">{{ $acceptLabel }}</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic type="button" wire:click="{{ $respondAction }}(false)" wire:confirm="{{ $isCapacity ? 'Diese Kapazitätsanfrage ablehnen?' : 'Diesen Dienst ablehnen?' }}" wire:loading.attr="disabled" wire:target="{{ $respondAction }}">{{ $declineLabel }}</x-ui.buttons.button-basic>
                    @else
                        <x-ui.buttons.button-basic type="button" mode="primary" disabled>{{ $acceptLabel }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" disabled>{{ $declineLabel }}</x-ui.buttons.button-basic>
                    @endif
                @endif
                @if(! $canRespond)
                    @if($livewire)<x-ui.buttons.button-basic type="button" mode="link" wire:click="{{ $closeAction }}" wire:loading.attr="disabled" wire:target="{{ $respondAction }}">Schließen</x-ui.buttons.button-basic>
                    @else<x-ui.buttons.button-basic type="button" mode="link" x-on:click="$dispatch('close')">Schließen</x-ui.buttons.button-basic>@endif
                @endif
                @endif
            </div>
        </div>
    </x-slot:footer>
</x-operations.modal>
