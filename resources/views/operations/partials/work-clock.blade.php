<section class="ops-panel rt-personal-work-clock rt-day-clock" aria-label="Arbeitszeiterfassung" data-worktime-capture wire:ignore.self
    x-bind:data-clock-state="active?.status ?? 'idle'" @if($compactActionClock ?? false) data-compact-action-clock @endif>
    <header class="rt-day-clock__header">
        <h2>Meine Arbeitszeit</h2>
        <span class="rt-clock-transfer" role="status" aria-atomic="true" wire:ignore><span class="sr-only">Übertragung: </span><span x-text="transferStatus()"></span></span>
    </header>
    <p class="ops-errors" role="alert" x-show="error" x-text="error" x-cloak wire:ignore></p>
    <div class="rt-clock-conflict" x-show="conflicts > 0" x-cloak wire:ignore>
        <div><strong>Die Zeitprüfung klärt diese Erfassung.</strong><p>Weitere Uhraktionen auf diesem Gerät sind bis zur Klärung gesperrt. <span x-text="conflictReason"></span></p>
            <p x-show="offline">Sobald du online bist, kannst du den Vorgang öffnen.</p></div>
        <x-ui.buttons.button-basic x-bind:disabled="offline || !ready" x-on:click="$wire.showCaptureConflicts(conflictEventKey)">Konflikt ansehen</x-ui.buttons.button-basic>
    </div>

    <div class="rt-day-clock__start" x-show="!active || !['running','paused'].includes(active.status)" x-cloak>
        <div>
            <p class="rt-day-clock__state"><i class="far fa-clock" aria-hidden="true"></i>{{ ($clockEndedToday ?? false) ? 'Arbeitszeit beendet' : 'Noch nicht gestartet' }}</p>
            @if(isset($clockTargetMinutes))
                <p class="rt-day-clock__meta">Heute sind {{ \App\Support\Operations\OperationsDateTime::duration($clockTargetMinutes * 60) }} geplant.</p>
            @else
                <p class="rt-day-clock__meta">Für heute ist kein Tages-Soll hinterlegt.</p>
            @endif
        </div>
        <x-ui.buttons.button-basic mode="primary" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({ action: 'start_day' })">Arbeitszeit starten</x-ui.buttons.button-basic>
    </div>
    <div class="rt-day-clock__running" x-show="active && ['running','paused'].includes(active.status)" x-cloak wire:ignore>
        <p class="rt-day-clock__duration" aria-label="Erfasste Nettoarbeitszeit"><span x-text="elapsed()"></span><small>h</small></p>
        <div>
            <p class="rt-day-clock__state" x-text="active?.status === 'paused' ? 'In Pause' : 'Arbeitszeit läuft'"></p>
            <p class="rt-day-clock__meta" x-text="active ? 'Seit ' + clockTime(active.starts_at) : ''"></p>
            <p class="rt-day-clock__meta" x-show="active && !active.day_clock" x-text="active?.title"></p>
        </div>
    </div>
    <div class="rt-day-clock__duty rt-day-clock__duty--active" x-show="active?.active_duty && ['running','paused'].includes(active.status)" x-cloak wire:ignore>
        <i class="far fa-train" aria-hidden="true"></i>
        <div><strong x-text="active?.active_duty?.title"></strong>
            <p class="rt-day-clock__meta" x-text="active?.active_duty ? 'Dienst seit ' + clockTime(active.active_duty.started_at) : ''"></p></div>
        <span class="rt-day-clock__duty-status">Dienst aktiv</span>
    </div>
    @foreach($clockOffers ?? collect() as $offer)
        @php
            $duty = $offer->shift;
            $start = $duty->starts_at->setTimezone($duty->timezone);
            $end = $duty->ends_at->setTimezone($duty->timezone);
            $firstStart = $start->subMinutes(config('operations.clock_start_early_minutes'));
            $today = now($duty->timezone);
        @endphp
        <div class="rt-day-clock__duty" wire:key="clock-offer-{{ $offer->id }}-{{ $duty->revision }}"
            x-show="canAttachDuty() && !active?.duty_assignment_ids?.includes({{ (int) $offer->id }}) && now < {{ $end->getTimestampMs() }}" x-cloak>
            <i class="far fa-train" aria-hidden="true"></i>
            <div><strong>{{ $duty->title }}</strong>
                <p class="rt-day-clock__meta">Geplant {{ $start->format($start->isSameDay($today) ? 'H:i' : 'd.m. H:i') }}–{{ $end->format($end->isSameDay($start) ? 'H:i' : 'd.m. H:i') }}@if($duty->timezone !== $displayTimezone) · {{ $duty->timezone }}@endif @if(filled($duty->location_name)) · {{ $duty->location_name }}@endif</p>
                <p class="rt-day-clock__hint" x-show="now < {{ $firstStart->getTimestampMs() }}">Start möglich ab {{ $firstStart->format($firstStart->isSameDay($today) ? 'H:i' : 'd.m. H:i') }} Uhr.</p>
                <p class="rt-day-clock__hint" x-show="active?.status === 'running' && now >= {{ $firstStart->getTimestampMs() }}">Die Arbeitsuhr läuft weiter.</p>
                <p class="rt-day-clock__hint" x-show="active?.status === 'paused'">Zum Dienststart zuerst die Arbeit fortsetzen.</p>
            </div>
            @include('livewire.operations.partials.personal-shift-actions', ['assignment' => $offer, 'shift' => $duty, 'knownClockOffer' => true, 'hideDutyProof' => true])
        </div>
    @endforeach
    <div class="rt-day-clock__controls" x-show="active && ['running','paused'].includes(active.status)" x-cloak>
        <div class="rt-day-clock__actions" wire:ignore>
            <x-ui.buttons.button-basic x-show.important="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'pause'})">Pause starten</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="primary" x-show.important="active?.status === 'paused'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'resume'})">Arbeit fortsetzen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="link" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'stop'})">Arbeitszeit beenden</x-ui.buttons.button-basic>
        </div>
        @include('operations.partials.work-clock-activities')
    </div>
    <div class="rt-day-clock__more" x-show="!active || !['running','paused'].includes(active.status)" x-cloak>
        <x-ui.buttons.button-basic mode="link" wire:click="openGeneralManual" wire:loading.attr="disabled" x-bind:disabled="offline"><i class="far fa-clock" aria-hidden="true"></i> Zeit nachtragen</x-ui.buttons.button-basic>
        <details class="rt-clock-more" x-on:click.outside="$el.open = false" x-on:keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()">
            <summary>Weitere Aktionen <i class="far fa-chevron-down" aria-hidden="true"></i></summary>
            <div class="rt-clock-more__menu">
                <x-ui.buttons.button-basic x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="$el.closest('details').querySelector('summary').focus(); $el.closest('details').open = false; startDialogOpen = true">Andere Arbeit starten</x-ui.buttons.button-basic>
                @foreach($clockOffers ?? collect() as $offer)
                    @include('livewire.operations.partials.personal-shift-actions', ['assignment' => $offer, 'shift' => $offer->shift, 'proofOnly' => true])
                @endforeach
            </div>
        </details>
    </div>
    @php($startFormId = 'employee-clock-start-'.(isset($__livewire) ? $__livewire->getId() : 'preview'))
    @php($availableTrainings = $startableTrainings ?? $clockTrainings)
    <x-operations.modal wire:model="generalStartOpen" show-expression="startDialogOpen" :show-offline="false" title="Arbeit starten">
        <form id="{{ $startFormId }}" class="ops-form rt-personal-form rt-employee-form" data-employee-form data-training-ids="{{ $availableTrainings->pluck('id')->values()->toJson() }}" x-on:submit.prevent="await startGeneral(JSON.parse($el.dataset.trainingIds)); if(!error || conflicts) startDialogOpen = false">
            <p class="ops-errors ops-full" role="alert" x-show="error" x-text="error" x-cloak></p>
            <fieldset class="rt-employee-form__section ops-full"><legend>Arbeitskontext</legend>
                <div class="rt-employee-form__choices">
                    @foreach(['internal' => 'Interne Arbeit', 'training' => 'Schulung', 'unplanned' => 'Ungeplant'] as $contextValue => $contextLabel)
                        <label><input type="radio" name="{{ $startFormId }}-context" value="{{ $contextValue }}" x-model="context"><span>{{ $contextLabel }}</span></label>
                    @endforeach
                </div>
            </fieldset>
            <div class="ops-full"><x-ui.forms.label :for="$startFormId.'-title'" value="Tätigkeit" /><x-ui.forms.input :id="$startFormId.'-title'" x-model="title" required minlength="3" maxlength="180" aria-label="Tätigkeit" /></div>
            <div class="ops-full" x-show="context === 'training'" x-cloak>
                <x-ui.forms.label :for="$startFormId.'-training'" value="Schulung" />
                <x-ui.forms.select :id="$startFormId.'-training'" x-model="trainingId" aria-label="Schulung">
                    <option value="">Schulung auswählen</option>
                    @foreach($availableTrainings as $training)
                        <option value="{{ $training->id }}">{{ $training->title }} · {{ $training->starts_at->format('d.m. H:i') }}</option>
                    @endforeach
                </x-ui.forms.select>
                @if($availableTrainings->isEmpty())<p class="rt-employee-form__hint">Zurzeit läuft keine Schulung mit bestätigter Teilnahme. Vergangene Schulungen kannst du nachtragen.</p>@endif
            </div>
            <p class="rt-employee-form__hint ops-full">Die Erfassung beginnt mit dem aktuellen Zeitpunkt.</p>
        </form>
        <x-slot:footer><div class="rt-employee-form__actions">
            <x-ui.buttons.button-basic type="button" x-on:click="startDialogOpen = false" x-bind:disabled="busy">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" form="{{ $startFormId }}" mode="primary" x-bind:disabled="busy || !ready || conflicts > 0 || (context === 'training' && !trainingId)">Arbeit starten</x-ui.buttons.button-basic>
        </div></x-slot:footer>
    </x-operations.modal>
    <x-ui.buttons.button-basic x-show.important="pending && !offline" x-bind:disabled="syncing || !ready" x-on:click="sync()">Synchronisieren</x-ui.buttons.button-basic>
</section>
