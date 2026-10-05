<section class="ops-panel ops-stack" aria-label="Arbeitszeiterfassung" data-worktime-capture wire:ignore>
    <header class="ops-toolbar">
        <h2 x-text="active && ['running','paused'].includes(active.status) ? active.title : 'Arbeitszeit'"></h2>
        <span class="ops-badge" aria-live="polite" x-text="!ready ? 'Online anmelden' : (conflicts ? conflicts + ' zu prüfen' : (pending ? pending + ' ausstehend' : (offline ? 'Offline' : 'Synchronisiert')))"></span>
    </header>
    <p class="ops-errors" role="alert" x-show="error" x-text="error" x-cloak></p>
    <template x-if="active && ['running','paused'].includes(active.status)">
        <div class="ops-stack">
            <p class="ops-muted" x-text="active ? 'Seit ' + new Date(active.starts_at).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}) : ''"></p>
            <p class="ops-clock" x-text="elapsed()"></p>
            <div class="ops-actions">
                <x-ui.buttons.button-basic x-show.important="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'pause'})">Pause starten</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic mode="primary" x-show.important="active?.status === 'paused'" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'resume'})">Weiterarbeiten</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="capture({action:'stop'})">Arbeit beenden</x-ui.buttons.button-basic>
            </div>
            <fieldset x-show="active?.status === 'running'" x-bind:disabled="busy || !ready || conflicts > 0"><x-ui.forms.label value="Arbeitsabschnitt" /><x-ui.forms.select aria-label="Arbeitsabschnitt" x-model="activityKind" change="capture({action:'activity',kind:$event.target.value})">@foreach(\App\Services\Operations\WorkTimeActivityService::KINDS as $kind => $label)@if($kind !== 'break')<option value="{{ $kind }}">{{ $label }}</option>@endif @endforeach</x-ui.forms.select></fieldset>
        </div>
    </template>
    <template x-if="!active || !['running','paused'].includes(active.status)">
        <div class="ops-actions"><x-ui.buttons.button-basic mode="primary" x-bind:disabled="busy || !ready || conflicts > 0" x-on:click="startDialogOpen=true">Arbeit starten</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="openGeneralManual">Zeit nachtragen</x-ui.buttons.button-basic></div>
    </template>
    <x-operations.modal wire:model="generalStartOpen" show-expression="startDialogOpen" :show-offline="false" title="Arbeit starten">
        <form class="ops-form" x-on:submit.prevent="await startGeneral(); if(!error) startDialogOpen = false">
            <div><x-ui.forms.label value="Arbeitskontext" /><x-ui.forms.select x-model="context" aria-label="Arbeitskontext"><option value="internal">Interne Arbeit</option><option value="training">Schulung</option><option value="unplanned">Ungeplante Arbeit</option></x-ui.forms.select></div>
            <div><x-ui.forms.label value="Tätigkeit" /><x-ui.forms.input x-model="title" maxlength="180" aria-label="Tätigkeit" /></div>
            <div x-show="context === 'training'"><x-ui.forms.label value="Schulung" /><x-ui.forms.select x-model="trainingId" aria-label="Schulung"><option value="">Auswählen</option>@foreach($clockTrainings as $training)<option value="{{ $training->id }}">{{ $training->title }} · {{ $training->starts_at->format('d.m. H:i') }}</option>@endforeach</x-ui.forms.select></div>
            <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" x-bind:disabled="busy || !ready || conflicts > 0">Starten</x-ui.buttons.button-basic></div>
        </form>
    </x-operations.modal>
    <x-ui.buttons.button-basic x-show.important="pending && !offline" x-bind:disabled="syncing || !ready" x-on:click="sync()">Synchronisieren</x-ui.buttons.button-basic>
</section>
