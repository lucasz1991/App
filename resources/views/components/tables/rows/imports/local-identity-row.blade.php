{{-- Lokaler Excel-Import: Bezeichnung einem Konto oder Dienstleister zuordnen. --}}
<x-tables.cell :column="$columnsMeta[0]" :primary="true">
    <span class="block break-words text-sm font-semibold">{{ $item->details['display_name'] ?? $item->alias }}</span>
    <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ match($item->kind) { 'employee' => 'Mitarbeiterkonto zugeordnet', 'provider' => 'Dienstleister', default => 'Zuordnung offen' } }}</span>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[1]">
    <x-ui.forms.select wire:model="targets.{{ $item->id }}" aria-label="Zuordnung für {{ $item->details['display_name'] ?? $item->alias }}"><option value="">Bitte auswählen</option><option value="provider">Dienstleister (kein Benutzerkonto)</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach @if($canCreateTestUsers && $item->kind === 'unresolved')<option value="new">Neues lokales Testkonto</option>@endif</x-ui.forms.select>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[2]">
    <x-ui.buttons.button-basic type="button" mode="secondary" wire:click="assign({{ $item->id }})" wire:loading.attr="disabled">Zuordnung speichern</x-ui.buttons.button-basic>
</x-tables.cell>
