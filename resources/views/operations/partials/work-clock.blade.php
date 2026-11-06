<section class="ops-panel ops-stack rt-personal-work-clock{{ ($compactActionClock ?? false) ? ' rt-personal-work-clock--action' : '' }}" aria-label="Arbeitszeiterfassung" x-bind:data-clock-state="active?.status ?? 'idle'" data-worktime-capture wire:ignore
    @if($compactMobileClock ?? false) x-data="{ overviewClockExpanded: false }" x-id="['overview-clock-details']" data-compact-clock data-overview-expanded="false" :data-overview-expanded="overviewClockExpanded ? 'true' : 'false'" :data-overview-idle="!active || !['running','paused'].includes(active.status) ? 'true' : 'false'" @endif>
    <header class="ops-toolbar">
        @if($compactMobileClock ?? false)<i class="far fa-clock rt-personal-clock-overview-icon" aria-hidden="true"></i>@endif
        <h2 x-text="active && ['running','paused'].includes(active.status) ? active.title : '{{ ($compactActionClock ?? false) ? 'Arbeitszeit' : 'Arbeitszeit erfassen' }}'"></h2>
        @if($compactMobileClock ?? false)<span class="rt-personal-clock-overview-label" x-show="!active || !['running','paused'].includes(active.status)">Arbeitszeit</span>@endif
        <span class="ops-badge" aria-live="polite" x-text="!ready ? 'Online anmelden' : (conflicts ? conflicts + ' zu prüfen' : (pending ? pending + ' ausstehend' : (offline ? 'Offline' : 'Synchronisiert')))"></span>
        @if($compactMobileClock ?? false)
            <x-ui.buttons.button-basic mode="link" class="rt-personal-clock-expand" x-show="!active || !['running','paused'].includes(active.status)"
                x-bind:aria-expanded="overviewClockExpanded" x-bind:aria-controls="$id('overview-clock-details')" x-on:click="overviewClockExpanded = !overviewClockExpanded">
                <span x-text="overviewClockExpanded ? 'Weniger' : 'Erfassen'"></span><i class="far fa-chevron-down" :class="{ 'rotate-180': overviewClockExpanded }" aria-hidden="true"></i>
            </x-ui.buttons.button-basic>
        @endif
    </header>
    <p class="ops-errors" role="alert" x-show="error" x-text="error" x-cloak></p>
    <template x-if="active && ['running','paused'].includes(active.status)">
        <div class="rt-personal-clock-running">
            <div class="rt-personal-clock-reading">
                <p class="rt-personal-clock-label" x-text="active?.status === 'paused' ? 'In Pause' : 'Arbeitszeit läuft'"></p>
                <p class="ops-clock" aria-label="Erfasste Arbeitszeit" x-text="elapsed()"></p>
                <p class="ops-muted" x-text="active ? 'Gestartet um ' + new Date(active.starts_at).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}) : ''"></p>
            </div>
            <div class="ops-stack rt-personal-clock-controls">
                <div class="ops-actions">
                    <x-ui.buttons.button-basic x-show.important="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'pause'})">Pause starten</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic mode="primary" x-show.important="active?.status === 'paused'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'resume'})">Weiterarbeiten</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'stop'})">Arbeit beenden</x-ui.buttons.button-basic>
                </div>
                <fieldset x-show="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0"><x-ui.forms.label value="Arbeitsabschnitt" /><x-ui.forms.select aria-label="Arbeitsabschnitt" x-model="activityKind" change="capture({action:'activity',kind:$event.target.value})">@foreach(\App\Services\Operations\WorkTimeActivityService::KINDS as $kind => $label)@if($kind !== 'break')<option value="{{ $kind }}">{{ $label }}</option>@endif @endforeach</x-ui.forms.select></fieldset>
            </div>
        </div>
    </template>
    <template x-if="!active || !['running','paused'].includes(active.status)">
        <div class="rt-personal-clock-idle" @if($compactMobileClock ?? false) :id="$id('overview-clock-details')" @endif>
            @unless($compactActionClock ?? false)<p class="ops-muted">Erfasse interne Arbeit, Schulungen oder ungeplante Tätigkeiten. Einen Dienst startest du direkt im Dienstplan.</p>@endunless
            <div class="ops-actions">
                <x-ui.buttons.button-basic mode="primary" aria-label="Arbeit starten" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="startDialogOpen=true"><span @if($compactActionClock ?? false) class="rt-clock-label-full" @endif>Arbeit starten</span>@if($compactActionClock ?? false)<span class="rt-clock-label-short" aria-hidden="true">Starten</span>@endif</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic aria-label="Zeit nachtragen" wire:click="openGeneralManual"><span @if($compactActionClock ?? false) class="rt-clock-label-full" @endif>Zeit nachtragen</span>@if($compactActionClock ?? false)<span class="rt-clock-label-short" aria-hidden="true">Nachtragen</span>@endif</x-ui.buttons.button-basic>
            </div>
        </div>
    </template>
    @php($startFormId = 'employee-clock-start-'.(isset($__livewire) ? $__livewire->getId() : 'preview'))
    <x-operations.modal wire:model="generalStartOpen" show-expression="startDialogOpen" :show-offline="false" title="Arbeit starten">
        <form id="{{ $startFormId }}" class="ops-form rt-personal-form rt-employee-form" data-employee-form x-on:submit.prevent="await startGeneral(); if(!error) startDialogOpen = false">
            <p class="ops-errors ops-full" role="alert" x-show="error" x-text="error" x-cloak></p>
            <fieldset class="rt-employee-form__section ops-full"><legend>Arbeitskontext</legend>
                <div class="rt-employee-form__choices">
                    @foreach(['internal' => 'Interne Arbeit', 'training' => 'Schulung', 'unplanned' => 'Ungeplant'] as $contextValue => $contextLabel)
                        <label><input type="radio" name="{{ $startFormId }}-context" value="{{ $contextValue }}" x-model="context"><span>{{ $contextLabel }}</span></label>
                    @endforeach
                </div>
            </fieldset>
            <div class="ops-full"><x-ui.forms.label :for="$startFormId.'-title'" value="Tätigkeit" /><x-ui.forms.input :id="$startFormId.'-title'" x-model="title" maxlength="180" aria-label="Tätigkeit" /></div>
            <div class="ops-full" x-show="context === 'training'" x-cloak><x-ui.forms.label :for="$startFormId.'-training'" value="Schulung" /><x-ui.forms.select :id="$startFormId.'-training'" x-model="trainingId" aria-label="Schulung"><option value="">Schulung auswählen</option>@foreach($clockTrainings as $training)<option value="{{ $training->id }}">{{ $training->title }} · {{ $training->starts_at->format('d.m. H:i') }}</option>@endforeach</x-ui.forms.select></div>
            <p class="rt-employee-form__hint ops-full">Die Erfassung beginnt beim Starten. Einen geplanten Dienst startest du direkt in deinem Dienstplan.</p>
        </form>
        <x-slot:footer><div class="rt-employee-form__actions">
            <x-ui.buttons.button-basic type="button" x-on:click="startDialogOpen = false" x-bind:disabled="busy">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" form="{{ $startFormId }}" mode="primary" x-bind:disabled="busy || !ready || conflicts > 0">Arbeit starten</x-ui.buttons.button-basic>
        </div></x-slot:footer>
    </x-operations.modal>
    <x-ui.buttons.button-basic x-show.important="pending && !offline" x-bind:disabled="syncing || !ready" x-on:click="sync()">Synchronisieren</x-ui.buttons.button-basic>
</section>
