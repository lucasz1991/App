{{-- Zeiterfassung; gemeinsam für „Heute“ und „Zeiten“. --}}
<div class="rt-personal-clock-block ops-stack">
    @if($workTimeReady)
        @include('operations.partials.work-clock', ['compactMobileClock' => $compactMobileClock ?? false, 'compactActionClock' => $compactActionClock ?? false])
        @php($generalFormId = 'employee-time-'.$this->getId())
        <x-operations.modal wire:model="generalManualOpen" title="Arbeitszeit nachtragen">
            <form id="{{ $generalFormId }}" wire:submit="saveGeneralManual" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
                <fieldset class="rt-employee-form__section ops-full">
                    <legend>Arbeitskontext</legend>
                    <div class="rt-employee-form__choices">
                        @foreach(['internal' => 'Interne Arbeit', 'training' => 'Schulung', 'unplanned' => 'Ungeplant'] as $contextValue => $contextLabel)
                            <label><input type="radio" name="{{ $generalFormId }}-context" value="{{ $contextValue }}" wire:model.live="generalManual.work_context"><span>{{ $contextLabel }}</span></label>
                        @endforeach
                    </div>
                    <x-ui.forms.input-error for="generalManual.work_context" />
                </fieldset>
                <x-operations.field label="Tätigkeit" model="generalManual.title" required maxlength="180" :wide="true" />
                @if(($generalManual['work_context'] ?? 'internal') === 'training')
                    <x-operations.field label="Schulung" model="generalManual.training_session_id" type="select" :wide="true" required><option value="">Schulung auswählen</option>@foreach($clockTrainings as $training)<option value="{{ $training->id }}">{{ $training->title }} · {{ $training->starts_at->setTimezone($displayTimezone)->format('d.m.Y H:i') }}</option>@endforeach</x-operations.field>
                @endif
                <fieldset class="rt-employee-form__section ops-full">
                    <legend>Zeitraum und Pause</legend>
                    <div class="rt-employee-form__grid">
                        <x-operations.field label="Beginn · Datum und Uhrzeit" model="generalManual.starts_at" type="datetime-local" required />
                        <x-operations.field label="Ende · Datum und Uhrzeit" model="generalManual.ends_at" type="datetime-local" required />
                        <x-operations.field label="Pause in Minuten" model="generalManual.pause_minutes" type="number" min="0" max="1440" required />
                        <p class="rt-employee-form__hint">Für Nachtdienste das Enddatum auf den Folgetag setzen. Alle Zeiten gelten in {{ $generalManual['timezone'] ?: $displayTimezone }}.</p>
                    </div>
                    @if($generalManualSummary ?? null)<div class="rt-employee-form__summary" role="status">{{ $generalManualSummary }}</div>@endif
                </fieldset>
                <div class="ops-full">
                    <x-operations.field label="Grund des Nachtrags" model="generalManual.note" type="textarea" rows="2" required minlength="5" maxlength="1000" />
                    <p class="rt-employee-form__hint">Mindestens 5 Zeichen.</p>
                </div>
                <details class="rt-employee-form__more ops-full" @if($errors->has('generalManual.timezone')) open @endif>
                    <summary>Weitere Angaben <span>· {{ $generalManual['timezone'] ?: $displayTimezone }}</span></summary>
                    <div class="rt-employee-form__grid"><x-operations.field label="Zeitzone" model="generalManual.timezone" required /></div>
                </details>
            </form>
            <x-slot:footer><div class="rt-employee-form__actions">
                <x-ui.buttons.button-basic type="button" wire:click="$set('generalManualOpen', false)" wire:loading.attr="disabled" wire:target="saveGeneralManual">Abbrechen</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="submit" form="{{ $generalFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="saveGeneralManual">Nachtrag speichern</x-ui.buttons.button-basic>
            </div></x-slot:footer>
        </x-operations.modal>
    @elseif($activeTime)
        <section class="ops-panel ops-service ops-stack" aria-label="Laufende Zeiterfassung" wire:poll.15s><header class="ops-toolbar"><div><p class="ops-kicker">Seit {{ $activeTime->starts_at->format('H:i') }} · {{ $activeTime->timezone }}</p><h2>{{ $activeTime->plan_snapshot['title'] }}</h2></div><x-operations.status :value="$activeTime->status" /></header><p class="ops-clock">{{ \App\Support\Operations\OperationsDateTime::duration($activeTime->netSeconds()) }}</p><div class="ops-actions">@if($activeTime->status === 'running')<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'pause')" wire:loading.attr="disabled">Pause starten</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic mode="primary" wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'resume')" wire:loading.attr="disabled">Weiterarbeiten</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'stop')" wire:confirm="Zeiterfassung beenden?" wire:loading.attr="disabled">Dienst beenden</x-ui.buttons.button-basic></div></section>
    @endif
</div>
