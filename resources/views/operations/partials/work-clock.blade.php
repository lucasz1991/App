<section class="ops-panel ops-stack rt-personal-work-clock{{ ($compactActionClock ?? false) ? ' rt-personal-work-clock--action' : '' }}" aria-label="Arbeitszeiterfassung" x-bind:data-clock-state="active?.status ?? 'idle'" data-worktime-capture wire:ignore.self
    @if($compactMobileClock ?? false) x-data="{ overviewClockExpanded: false }" x-id="['overview-clock-details']" data-compact-clock data-overview-expanded="false" :data-overview-expanded="overviewClockExpanded ? 'true' : 'false'" :data-overview-idle="!active || !['running','paused'].includes(active.status) ? 'true' : 'false'" @endif>
    <header class="ops-toolbar" wire:ignore>
        @if($compactMobileClock ?? false)<i class="far fa-clock rt-personal-clock-overview-icon" aria-hidden="true"></i>@endif
        <h2 x-text="active && ['running','paused'].includes(active.status) ? active.title : '{{ ($compactActionClock ?? false) ? 'Arbeitszeit' : 'Arbeitszeit erfassen' }}'"></h2>
        @if($compactMobileClock ?? false)<span class="rt-personal-clock-overview-label" x-show="!active || !['running','paused'].includes(active.status)">Arbeitszeit</span>@endif
        <span class="rt-clock-transfer" role="status" aria-atomic="true"><span class="sr-only">Übertragung: </span><span x-text="transferStatus()"></span></span>
        @if($compactMobileClock ?? false)
            <x-ui.buttons.button-basic mode="link" class="rt-personal-clock-expand" x-show="!active || !['running','paused'].includes(active.status)"
                x-bind:aria-expanded="overviewClockExpanded" x-bind:aria-controls="$id('overview-clock-details')" x-on:click="overviewClockExpanded = !overviewClockExpanded">
                <span x-text="overviewClockExpanded ? 'Weniger' : 'Erfassen'"></span><i class="far fa-chevron-down" :class="{ 'rotate-180': overviewClockExpanded }" aria-hidden="true"></i>
            </x-ui.buttons.button-basic>
        @endif
    </header>
    <p class="ops-errors" role="alert" x-show="error" x-text="error" x-cloak wire:ignore></p>
    <div class="rt-clock-conflict" x-show="conflicts > 0" x-cloak wire:ignore>
        <div><strong>Die Zeitprüfung klärt diese Erfassung.</strong><p>Weitere Uhraktionen auf diesem Gerät sind bis zur Klärung gesperrt. <span x-text="conflictReason"></span></p>
            <p x-show="offline">Sobald du online bist, kannst du den Vorgang öffnen.</p></div>
        <x-ui.buttons.button-basic x-bind:disabled="offline || !ready" x-on:click="$wire.showCaptureConflicts(conflictEventKey)">Konflikt ansehen</x-ui.buttons.button-basic>
    </div>
        <div class="rt-personal-clock-running" x-show.important="active && ['running','paused'].includes(active.status)" x-cloak wire:ignore>
            <div class="rt-personal-clock-reading">
                <p class="rt-personal-clock-label" x-text="active?.status === 'paused' ? 'In Pause' : 'Arbeitszeit läuft'"></p>
                <p class="ops-clock" aria-label="Erfasste Arbeitszeit" x-text="elapsed()"></p>
                <p class="ops-muted" x-text="active ? 'Gestartet um ' + new Date(active.starts_at).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit',timeZone:active.timezone}) + ' · ' + active.timezone : ''"></p>
            </div>
            <div class="ops-stack rt-personal-clock-controls">
                <div class="ops-actions">
                    <x-ui.buttons.button-basic mode="primary" x-show.important="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'pause'})">Pause starten</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic mode="primary" x-show.important="active?.status === 'paused'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'resume'})">Weiterarbeiten</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'stop'})">Arbeit beenden</x-ui.buttons.button-basic>
                </div>
                <fieldset x-show="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0"><x-ui.forms.label value="Arbeitsabschnitt" /><x-ui.forms.select aria-label="Arbeitsabschnitt" x-model="activityKind" change="capture({action:'activity',kind:$event.target.value})">@foreach(\App\Services\Operations\WorkTimeActivityService::KINDS as $kind => $label)@if($kind !== 'break')<option value="{{ $kind }}">{{ $label }}</option>@endif @endforeach</x-ui.forms.select></fieldset>
            </div>
        </div>
        <div class="rt-personal-clock-idle" x-show.important="!active || !['running','paused'].includes(active.status)" x-cloak wire:ignore.self @if($compactMobileClock ?? false) :id="$id('overview-clock-details')" @endif>
            @if(!($compactActionClock ?? false) && !isset($clockDuty))<p class="ops-muted">Erfasse interne Arbeit, Schulungen oder ungeplante Tätigkeiten. Einen Dienst startest du direkt im Dienstplan.</p>@endif
            @isset($clockDuty)
                @php
                    $dutyStart = $clockDuty->shift->starts_at->copy()->setTimezone($clockDuty->shift->timezone);
                    $dutyEnd = $clockDuty->shift->ends_at->copy()->setTimezone($clockDuty->shift->timezone);
                @endphp
                <div class="rt-clock-duty" wire:key="clock-duty-{{ $clockDuty->id }}-{{ $clockDuty->shift->revision }}">
                    <div><strong>{{ $clockDuty->shift->title }}</strong><p>{{ $dutyStart->format('d.m. H:i') }} – {{ $dutyEnd->format($dutyStart->isSameDay($dutyEnd) ? 'H:i' : 'd.m. H:i') }} · {{ $clockDuty->shift->timezone }}</p></div>
                    @include('livewire.operations.partials.personal-shift-actions', ['assignment' => $clockDuty, 'shift' => $clockDuty->shift])
                </div>
            @endisset
            <div class="ops-actions">
                <x-ui.buttons.button-basic :mode="isset($clockDuty) ? 'secondary' : 'primary'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="startDialogOpen=true">{{ isset($clockDuty) ? 'Andere Arbeit starten' : 'Arbeit starten' }}</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="openGeneralManual" wire:loading.attr="disabled" x-bind:disabled="offline">Zeit nachtragen</x-ui.buttons.button-basic>
            </div>
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
