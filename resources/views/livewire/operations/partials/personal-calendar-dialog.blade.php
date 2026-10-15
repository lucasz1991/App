    @if($selectedCalendarEvent?->kind === 'shift')
        <div wire:key="personal-calendar-shift-dialog-{{ $this->getId() }}">
        <x-operations.personal-request-dialog :request="$selectedCalendarRequest" :calendar="$selectedCalendarContext" :open="$calendarEventOpen" :requested-revision="$selectedCalendarEvent->record->plan_revision" :id="'personal-calendar-'.$this->getId()" close-action="closeCalendarEvent" context="shift" wire:model.live="calendarEventOpen">
            @if(\App\Support\Operations\PlanningSchema::ready())
                <x-slot:activity><livewire:operations.duty-activity :shift-id="$selectedCalendarEvent->record->shift_id" :employee-mode="true" :key="'employee-duty-'.$selectedCalendarEvent->record->id.'-'.$selectedCalendarEvent->record->plan_revision" /></x-slot:activity>
            @endif
            <x-slot:actions>
                @php($assignment = $selectedCalendarEvent->record)
                @php($shift = $assignment->shift)
                @include('livewire.operations.partials.personal-shift-actions')
                <x-ui.buttons.button-basic type="button" mode="link" wire:click="closeCalendarEvent" wire:loading.attr="disabled">Schließen</x-ui.buttons.button-basic>
            </x-slot:actions>
        </x-operations.personal-request-dialog>
        </div>
    @else
    <div wire:key="personal-calendar-other-dialog-{{ $this->getId() }}">
    <x-operations.modal wire:model="calendarEventOpen" :title="$selectedCalendarEvent?->title ?? 'Mein Termin'">
        @if($selectedCalendarEvent)
                <div class="ops-toolbar"><h3>{{ $selectedCalendarEvent->title }}</h3><x-operations.status :value="$selectedCalendarEvent->status" /></div>
                <dl class="rt-personal-calendar-details">
                    <div><dt>Beginn</dt><dd>{{ $selectedCalendarEvent->starts->format('d.m.Y H:i') }}</dd></div>
                    <div><dt>Ende</dt><dd>{{ $selectedCalendarEvent->ends->format('d.m.Y H:i') }}</dd></div>
                    <div><dt>Zeitzone</dt><dd>{{ $displayTimezone }}</dd></div>
                    @if($selectedCalendarEvent->record->note)<div><dt>Notiz</dt><dd>{{ $selectedCalendarEvent->record->note }}</dd></div>@endif
                    @if($selectedCalendarEvent->record->review_note)<div><dt>Rückmeldung</dt><dd>{{ $selectedCalendarEvent->record->review_note }}</dd></div>@endif
                </dl>
                @if($selectedCalendarEvent->status === 'pending')<div><x-ui.buttons.button-basic wire:click="withdraw({{ $selectedCalendarEvent->record->id }},{{ $selectedCalendarEvent->record->revision }})" wire:loading.attr="disabled">Antrag zurückziehen</x-ui.buttons.button-basic></div>@endif
        @endif
        <x-slot:footer><x-ui.buttons.button-basic wire:click="closeCalendarEvent">Schließen</x-ui.buttons.button-basic></x-slot:footer>
    </x-operations.modal>
    </div>
    @endif
