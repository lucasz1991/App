<form wire:submit="save" class="ops-stack">
    <x-operations.feedback />
    <p class="ops-muted">{{ $entry->starts_at->format('d.m.Y H:i:s') }} – {{ $entry->ends_at->format('d.m.Y H:i:s') }} · {{ $entry->timezone }} · {{ intdiv($entry->pause_seconds,60) }} min Pause</p>
    @foreach($sections as $index => $section)
        <fieldset class="ops-panel ops-form" wire:key="actual-section-{{ $entryId }}-{{ $index }}"><legend>Abschnitt {{ $index + 1 }}</legend>
            <x-operations.field label="Tätigkeit" :model="'sections.'.$index.'.kind'" type="select">@foreach($kinds as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach</x-operations.field>
            <x-operations.field label="Beginn" :model="'sections.'.$index.'.starts_at'" type="datetime-local" step="1" required />
            <x-operations.field label="Ende" :model="'sections.'.$index.'.ends_at'" type="datetime-local" step="1" required />
            <x-operations.field label="Notiz" :model="'sections.'.$index.'.note'" maxlength="500" />
            <div class="ops-full"><x-ui.buttons.button-basic type="button" wire:click="remove({{ $index }})">Entfernen</x-ui.buttons.button-basic></div>
        </fieldset>
    @endforeach
    <div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="add">Abschnitt ergänzen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Abschnitte speichern</x-ui.buttons.button-basic></div>
</form>
