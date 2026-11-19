<form wire:submit="save" class="rt-personnel-management rt-time-sections ops-stack">
    <x-operations.feedback />
    <header class="rt-personnel-section-heading"><div><h3>Zeitraum der Meldung</h3>
        <p>{{ $entry->starts_at->format('d.m.Y H:i:s') }} – {{ $entry->ends_at->format('d.m.Y H:i:s') }} · {{ $entry->timezone }}</p>
        <p>Erfasse alle Abschnitte lückenlos, einschließlich {{ \App\Support\Operations\OperationsDateTime::duration($entry->pause_seconds) }} Pause.</p>
    </div></header>
    @forelse($sections as $index => $section)
        <fieldset class="rt-personnel-section ops-form" wire:key="actual-section-{{ $entryId }}-{{ $index }}"><legend>Abschnitt {{ $index + 1 }}</legend>
            <x-operations.field label="Tätigkeit" :model="'sections.'.$index.'.kind'" type="select">@foreach($kinds as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach</x-operations.field>
            <x-operations.field label="Beginn" :model="'sections.'.$index.'.starts_at'" type="datetime-local" step="1" required />
            <x-operations.field label="Ende" :model="'sections.'.$index.'.ends_at'" type="datetime-local" step="1" required />
            <x-operations.field label="Notiz" :model="'sections.'.$index.'.note'" maxlength="500" />
            <div class="ops-full"><x-ui.buttons.button-basic type="button" wire:click="remove({{ $index }})" aria-label="Abschnitt {{ $index + 1 }} entfernen">Entfernen</x-ui.buttons.button-basic></div>
        </fieldset>
    @empty
        <div class="rt-personnel-empty"><strong>Noch keine Abschnitte</strong><p>Ergänze die erste Tätigkeit und ihren tatsächlichen Zeitraum.</p></div>
    @endforelse
    <div class="rt-personnel-form-actions ops-actions"><x-ui.buttons.button-basic type="button" wire:click="add">Abschnitt ergänzen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Abschnitte speichern</x-ui.buttons.button-basic></div>
</form>
