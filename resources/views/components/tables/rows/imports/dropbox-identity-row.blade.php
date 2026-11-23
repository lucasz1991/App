{{-- Dropbox-Abgleich: Excel-Bezeichnung einem Konto oder Dienstleister zuordnen. --}}
<x-tables.cell :column="$columnsMeta[0]" :primary="true">
    <span class="block break-words text-sm font-semibold">{{ $item->details['display_name'] ?? $item->alias }}</span>
    <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->kind === 'unresolved' ? 'Zuordnung offen' : 'Zuordnung gespeichert' }}</span>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[1]">
    <x-ui.forms.label :for="'identity-kind-'.$item->id" value="Art" class="sr-only" />
    <x-ui.forms.select :id="'identity-kind-'.$item->id" wire:model="identityForms.{{ $item->id }}.kind"><option value="unresolved">Noch offen</option><option value="employee">Mitarbeiter</option><option value="provider">Dienstleister</option></x-ui.forms.select>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[2]">
    <x-ui.forms.label :for="'identity-user-'.$item->id" value="Mitarbeiterkonto" class="sr-only" />
    <x-ui.forms.select :id="'identity-user-'.$item->id" wire:model="identityForms.{{ $item->id }}.user_id"><option value="">Kein Konto</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</x-ui.forms.select>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[3]">
    <x-ui.buttons.button-basic type="button" mode="secondary" wire:click="saveIdentity({{ $item->id }})" wire:loading.attr="disabled">Zuordnung speichern</x-ui.buttons.button-basic>
</x-tables.cell>
