{{-- Zeiterfassung samt Standortstatus; gemeinsam für „Heute“ und „Zeiten“. --}}
<div class="rt-personal-today__clock ops-stack">
    @if($workTimeReady)
        @include('operations.partials.work-clock')
        <x-operations.modal wire:model="generalManualOpen" title="Arbeitszeit nachtragen"><form wire:submit="saveGeneralManual" class="ops-form rt-personal-form">
            <x-operations.field label="Arbeitskontext" model="generalManual.work_context" type="select"><option value="internal">Interne Arbeit</option><option value="unplanned">Ungeplante Arbeit</option><option value="training">Schulung</option></x-operations.field>
            <x-operations.field label="Tätigkeit" model="generalManual.title" required maxlength="180" />
            <x-operations.field label="Schulung (bei Schulungszeit)" model="generalManual.training_session_id" type="select"><option value="">Auswählen</option>@foreach($clockTrainings as $training)<option value="{{ $training->id }}">{{ $training->title }}</option>@endforeach</x-operations.field>
            <x-operations.field label="Zeitzone" model="generalManual.timezone" required />
            <x-operations.field label="Beginn" model="generalManual.starts_at" type="datetime-local" required />
            <x-operations.field label="Ende" model="generalManual.ends_at" type="datetime-local" required />
            <x-operations.field label="Pause (min)" model="generalManual.pause_minutes" type="number" min="0" required />
            <x-operations.field label="Grund des Nachtrags" model="generalManual.note" required minlength="5" :wide="true" />
            <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic></div>
        </form></x-operations.modal>
    @elseif($activeTime)
        <section class="ops-panel ops-service ops-stack" aria-label="Laufende Zeiterfassung" wire:poll.15s><header class="ops-toolbar"><div><p class="ops-kicker">Seit {{ $activeTime->starts_at->format('H:i') }} · {{ $activeTime->timezone }}</p><h2>{{ $activeTime->plan_snapshot['title'] }}</h2></div><x-operations.status :value="$activeTime->status" /></header><p class="ops-clock">{{ \App\Support\Operations\OperationsDateTime::duration($activeTime->netSeconds()) }}</p><div class="ops-actions">@if($activeTime->status === 'running')<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'pause')" wire:loading.attr="disabled">Pause starten</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic mode="primary" wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'resume')" wire:loading.attr="disabled">Weiterarbeiten</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'stop')" wire:confirm="Zeiterfassung beenden?" wire:loading.attr="disabled">Dienst beenden</x-ui.buttons.button-basic></div></section>
    @endif
    <livewire:operations.employee-location-status :key="'employee-location-own-'.auth()->id()" />
</div>
