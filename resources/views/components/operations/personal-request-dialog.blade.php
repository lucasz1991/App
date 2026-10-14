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
@endphp
<x-operations.modal :id="$scopedId" :title="$row['label'] ?? 'Anfrage prüfen'" max-width="3xl" :show-expression="$showExpression" :show-offline="$showOffline" {{ $attributes }}>
    @if($open)
        <div class="rt-request-dialog rt-personal-form" data-personal-request-dialog wire:key="{{ $stateKey }}" x-data="{ requestTab: 'assignment' }" x-effect="if (typeof show !== 'undefined' && !show) requestTab = 'assignment'">
            @if($row)
                <header class="rt-request-dialog__intro"><p class="rt-request-dialog__eyebrow">{{ $row['label'] }} · Version {{ $row['revision'] }}</p><h3>{{ $row['title'] }}</h3><p>{{ $row['customer'] ?: 'Kunde nicht hinterlegt' }}</p></header>
                <x-operations.panel.tabs :tabs="['assignment' => ['label' => 'Einsatz'], 'details' => ['label' => 'Details & Änderungen'], 'calendar' => ['label' => 'Mein Kalender']]" label="Anfrage prüfen" :id-prefix="$scopedId" model="requestTab" class="rt-request-dialog__tabs" />
                <section id="{{ $scopedId }}-panel-assignment" class="rt-request-dialog__panel" role="tabpanel" aria-labelledby="{{ $scopedId }}-tab-assignment" tabindex="0" x-show="requestTab === 'assignment'">
                    <p class="rt-request-dialog__eyebrow">Angefragter Zeitraum</p>
                    <p class="rt-request-dialog__period"><span>{{ $start->locale('de')->isoFormat('dd, D. MMM YYYY') }}</span><strong>{{ $start->format('H:i') }} – {{ $end->format($start->isSameDay($end) ? 'H:i' : 'd.m. H:i') }}</strong></p>
                    <dl class="rt-request-dialog__facts"><div><dt>Einsatzort</dt><dd>{{ $row['location'] ?: 'Nicht hinterlegt' }}</dd></div><div><dt>Funktion</dt><dd>{{ $row['role'] ?: 'Nicht hinterlegt' }}</dd></div><div><dt>Kunde</dt><dd>{{ $row['customer'] ?: 'Nicht hinterlegt' }}</dd></div></dl>
                </section>
                <section id="{{ $scopedId }}-panel-details" class="rt-request-dialog__panel" role="tabpanel" aria-labelledby="{{ $scopedId }}-tab-details" tabindex="0" x-show="requestTab === 'details'" x-cloak>
                    <dl class="rt-request-dialog__facts rt-request-dialog__facts--details"><div><dt>Beginn</dt><dd>{{ $start->format('d.m.Y H:i') }}</dd></div><div><dt>Ende</dt><dd>{{ $end->format('d.m.Y H:i') }}</dd></div><div><dt>Zeitzone</dt><dd>{{ $row['timezone'] }}</dd></div><div><dt>Geplante Pause</dt><dd>{{ $row['break_minutes'] }} Minuten</dd></div></dl>
                    <section class="rt-request-dialog__changes" aria-label="Veröffentlichte Planänderungen"><h4>Was sich geändert hat</h4>
                        @if(! empty($request['changes']))<dl>@foreach($request['changes'] as $change)<div><dt>{{ $change['label'] }}</dt><dd><span>Bisher: {{ $change['before'] }}</span><strong>Aktuell: {{ $change['after'] }}</strong></dd></div>@endforeach</dl>
                        @else<p class="rt-request-dialog__muted">Keine veröffentlichten Änderungen zu diesem Vorgang.</p>@endif
                    </section>
                </section>
                <section id="{{ $scopedId }}-panel-calendar" class="rt-request-dialog__panel" role="tabpanel" aria-labelledby="{{ $scopedId }}-tab-calendar" tabindex="0" x-show="requestTab === 'calendar'" x-cloak><x-operations.personal-request-calendar :calendar="$calendar" :start="$start" :end="$end" /></section>
            @endif
            @if($notice || ($request && ! $canRespond))
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
                @if($open && $canRespond)
                    @if($livewire)
                        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="{{ $respondAction }}(true)" wire:loading.attr="disabled" wire:target="{{ $respondAction }}">{{ $acceptLabel }}</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic type="button" wire:click="{{ $respondAction }}(false)" wire:confirm="{{ $isCapacity ? 'Diese Kapazitätsanfrage ablehnen?' : 'Diesen Dienst ablehnen?' }}" wire:loading.attr="disabled" wire:target="{{ $respondAction }}">{{ $declineLabel }}</x-ui.buttons.button-basic>
                    @else
                        <x-ui.buttons.button-basic type="button" mode="primary" disabled>{{ $acceptLabel }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" disabled>{{ $declineLabel }}</x-ui.buttons.button-basic>
                    @endif
                @endif
                @if($livewire)<x-ui.buttons.button-basic type="button" mode="link" wire:click="{{ $closeAction }}" wire:loading.attr="disabled" wire:target="{{ $respondAction }}">Schließen</x-ui.buttons.button-basic>
                @else<x-ui.buttons.button-basic type="button" mode="link" x-on:click="$dispatch('close')">Schließen</x-ui.buttons.button-basic>@endif
            </div>
        </div>
    </x-slot:footer>
</x-operations.modal>
