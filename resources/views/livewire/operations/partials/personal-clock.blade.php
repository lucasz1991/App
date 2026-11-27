{{-- Zeiterfassung; gemeinsam für „Heute“ und „Zeiten“. --}}
<div class="rt-personal-clock-block ops-stack">
    @if($workTimeReady)
        @include('operations.partials.work-clock', ['compactMobileClock' => $compactMobileClock ?? false, 'compactActionClock' => $compactActionClock ?? false])
        <p x-data="{ submitted: false }" x-show="submitted" x-cloak role="status" tabindex="-1"
            x-on:work-time-day-submitted.window="if ($event.detail.workspace === $el.closest('[data-personal-workspace]')?.getAttribute('wire:id')) { submitted = true; $nextTick(() => $el.focus()) }"
            x-on:work-time-day-opened.window="submitted = false"
            x-on:worktime-synced.window="submitted = false">Tagesbeleg bestätigt und zur Prüfung eingereicht.</p>
        @if(isset($dayReviewEntry))
            <div id="personal-day-review" tabindex="-1" x-on:work-time-day-opened.window="$nextTick(() => $el.focus())">
                <livewire:operations.work-time-day-review :entry-id="$dayReviewEntry->id" :key="'day-review-'.$tab.'-'.$dayReviewEntry->id.'-'.$dayReviewEntry->revision" />
            </div>
        @endif
        @php($generalFormId = 'employee-time-'.(isset($__livewire) ? $__livewire->getId() : 'preview'))
        @php($generalTimezone = is_string($generalManual['timezone'] ?? null) ? $generalManual['timezone'] : '')
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
                <x-operations.work-time-interval model="generalManual" :values="$generalManual ?? []" :timezone="$generalTimezone" />
                <div class="ops-full">
                    <x-operations.field label="Grund des Nachtrags" model="generalManual.note" type="textarea" rows="2" required minlength="5" maxlength="1000" />
                    <p class="rt-employee-form__hint">Mindestens 5 Zeichen.</p>
                </div>
                <details class="rt-employee-form__more ops-full" @if($errors->has('generalManual.timezone')) open @endif>
                    <summary>Weitere Angaben <span>· {{ $generalTimezone ?: 'Zeitzone' }}</span></summary>
                    <div class="rt-employee-form__grid"><x-operations.field label="Zeitzone" model="generalManual.timezone" :live="true" required /></div>
                </details>
            </form>
            <x-slot:footer><div class="rt-employee-form__actions">
                <x-ui.buttons.button-basic type="button" wire:click="$set('generalManualOpen', false)" wire:loading.attr="disabled" wire:target="saveGeneralManual">Abbrechen</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="submit" form="{{ $generalFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="saveGeneralManual">Nachtrag speichern</x-ui.buttons.button-basic>
            </div></x-slot:footer>
        </x-operations.modal>
    @elseif($activeTime)
        <x-ui.surface.card variant="ops" class="ops-service ops-stack" aria-label="Laufende Zeiterfassung" wire:poll.visible.15s><x-ui.section-header :eyebrow="'Seit '.$activeTime->starts_at->format('H:i').' · '.$activeTime->timezone" :title="$activeTime->plan_snapshot['title']"><x-slot:actions><x-operations.status :value="$activeTime->status" /></x-slot:actions></x-ui.section-header><p class="ops-clock">{{ \App\Support\Operations\OperationsDateTime::duration($activeTime->netSeconds()) }}</p><div class="ops-actions">@if($activeTime->status === 'running')<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'pause')" wire:loading.attr="disabled">Pause starten</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic mode="primary" wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'resume')" wire:loading.attr="disabled">Weiterarbeiten</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'stop')" wire:confirm="Zeiterfassung beenden?" wire:loading.attr="disabled">Dienst beenden</x-ui.buttons.button-basic></div></x-ui.surface.card>
    @elseif(isset($clockDuty) && ($tab ?? '') === 'time')
        <x-ui.surface.card variant="ops" class="rt-clock-duty" aria-label="Geplanter Dienst">
            <div><strong>{{ $clockDuty->shift->title }}</strong><p>{{ $clockDuty->shift->starts_at->format('d.m. H:i') }} · {{ $clockDuty->shift->timezone }}</p></div>
            @include('livewire.operations.partials.personal-shift-actions', ['assignment' => $clockDuty, 'shift' => $clockDuty->shift])
        </x-ui.surface.card>
    @endif
</div>
