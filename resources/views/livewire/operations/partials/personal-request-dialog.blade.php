<x-operations.modal wire:model="requestOpen" :title="$selectedRequest['row']['label'] ?? 'Anfrage prüfen'" max-width="2xl">
    @if($requestOpen)
        <div class="ops-stack rt-personal-request-dialog rt-personal-form" data-personal-request-dialog>
            @if($selectedRequest && $selectedRequest['row'])
                @php
                    $row = $selectedRequest['row'];
                    $start = $row['starts_at']->setTimezone($row['timezone']);
                    $end = $row['ends_at']->setTimezone($row['timezone']);
                @endphp
                <div class="rt-personal-request-dialog__intro"><p class="ops-kicker">{{ $row['label'] }} · Version {{ $row['revision'] }}</p><h3>{{ $row['title'] }}</h3><p>{{ $row['customer'] ?: 'Kunde nicht hinterlegt' }}</p></div>
                <dl class="rt-personal-request-dialog__details">
                    <div><dt>Beginn</dt><dd>{{ $start->locale('de')->isoFormat('dd, D. MMM YYYY') }} · {{ $start->format('H:i') }}</dd></div>
                    <div><dt>Ende</dt><dd>{{ $end->locale('de')->isoFormat('dd, D. MMM YYYY') }} · {{ $end->format('H:i') }}</dd></div>
                    <div><dt>Einsatzort</dt><dd>{{ $row['location'] ?: 'Nicht hinterlegt' }}</dd></div>
                    <div><dt>Funktion</dt><dd>{{ $row['role'] ?: 'Nicht hinterlegt' }}</dd></div>
                    <div><dt>Zeitzone</dt><dd>{{ $row['timezone'] }}</dd></div>
                    <div><dt>Geplante Pause</dt><dd>{{ $row['break_minutes'] }} Minuten</dd></div>
                </dl>
                @if($selectedRequest['changes'])
                    <section class="rt-personal-request-dialog__changes" aria-label="Veröffentlichte Planänderungen">
                        <h4>Was sich geändert hat</h4>
                        <dl>@foreach($selectedRequest['changes'] as $change)<div><dt>{{ $change['label'] }}</dt><dd><span>Bisher: {{ $change['before'] }}</span><strong>Aktuell: {{ $change['after'] }}</strong></dd></div>@endforeach</dl>
                    </section>
                @endif
            @endif
            @if($requestNotice || ($selectedRequest && ! $selectedRequest['can_respond']))
                <p class="rt-personal-request-dialog__notice" role="status">{{ $requestNotice ?: $selectedRequest['notice'] }}</p>
                @if($selectedRequest && $selectedRequest['row'] && ! $selectedRequest['can_respond'] && $selectedRequest['row']['revision'] !== $requestRevision)
                    <x-ui.buttons.button-basic type="button" wire:click="openConsentRequest('{{ $selectedRequest['row']['type'] }}', {{ $selectedRequest['row']['id'] }}, {{ $selectedRequest['row']['revision'] }})" wire:loading.attr="disabled">Aktuelle Anfrage prüfen</x-ui.buttons.button-basic>
                @endif
            @endif
            @if($selectedRequest && $selectedRequest['can_respond'])
                @if($row['type'] === 'capacity')
                    <p class="ops-muted">Du bestätigst hier deine Verfügbarkeit. Die Kapazität wird anschließend separat freigegeben.</p>
                    <x-ui.forms.checkbox wire:model="requestConfirmed" label="Ich habe den angefragten Zeitraum geprüft." />
                    @error('requestConfirmed')<p class="ops-errors" role="alert">{{ $message }}</p>@enderror
                @endif
                <div class="ops-actions rt-personal-request-dialog__actions">
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="respondToRequest(true)" wire:loading.attr="disabled" wire:target="respondToRequest">{{ $row['type'] === 'capacity' ? 'Verfügbar' : ($row['changed_plan'] ? 'Änderung bestätigen' : 'Dienst bestätigen') }}</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" wire:click="respondToRequest(false)" wire:confirm="{{ $row['type'] === 'capacity' ? 'Diese Kapazitätsanfrage ablehnen?' : 'Diesen Dienst ablehnen?' }}" wire:loading.attr="disabled" wire:target="respondToRequest">{{ $row['type'] === 'capacity' ? 'Nicht verfügbar' : 'Ablehnen' }}</x-ui.buttons.button-basic>
                </div>
            @endif
        </div>
    @endif
    <x-slot:footer><x-ui.buttons.button-basic type="button" mode="link" wire:click="closeConsentRequest" wire:loading.attr="disabled" wire:target="respondToRequest">Schließen</x-ui.buttons.button-basic></x-slot:footer>
</x-operations.modal>
