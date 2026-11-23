{{-- Dropbox-Abgleich: abgebildeter Fachdatensatz; "Bearbeiten" bzw. Doppelklick oeffnet ihn. --}}
<x-tables.cell :column="$columnsMeta[0]" :primary="true">
    <span class="block text-sm font-semibold">{{ match($item->domain) { 'planning' => 'Einsatz', 'contacts' => 'Kontakt', default => 'Kompetenzangabe' } }} #{{ $item->model_id }}</span>
    <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->metadata['subject'] ?? $item->metadata['employee_label'] ?? '' }} {{ $item->metadata['name'] ?? '' }}</span>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[1]">
    <x-ui.buttons.button-basic type="button" mode="secondary" wire:click="editRecord({{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>
</x-tables.cell>
