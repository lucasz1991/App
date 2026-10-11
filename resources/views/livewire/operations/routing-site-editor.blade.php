<section class="space-y-3 border-t border-rt-border py-4 dark:border-rt-dark-border" aria-label="Bestätigter Einsatzstandort">
    <div><h3 class="font-semibold">Einsatzstandort für die Routenplanung</h3>
        @if(\App\Services\Operations\SiteSnapshotService::confirmed($record->location_snapshot))
            <p class="text-sm">{{ $record->location_snapshot['name'] }} · {{ $record->location_snapshot['address'] }}<span class="block text-rt-muted">Bestätigt · {{ $record->location_snapshot['latitude'] }}, {{ $record->location_snapshot['longitude'] }} · Stand {{ $record->location_snapshot['revision'] }}</span></p>
        @else
            <p class="text-sm text-amber-700 dark:text-amber-300">Standort noch nicht präzise bestätigt. Bei aktivierter Routenplanung ist eine verbindliche Zuteilung oder Veröffentlichung erst nach Bestätigung möglich.</p>
        @endif
    </div>
    <form wire:submit="save" class="flex flex-wrap items-end gap-3">
        <div class="min-w-0 grow"><label for="routing-site-{{ $subjectType }}-{{ $subjectId }}" class="text-sm">Geprüften Kundenstandort auswählen</label>
            <x-ui.forms.select id="routing-site-{{ $subjectType }}-{{ $subjectId }}" wire:model="customerLocationId"><option value="">Bitte auswählen</option>@foreach($locations as $location)<option value="{{ $location->id }}" @disabled(!$location->coordinates_confirmed_at)>{{ $location->name }}{{ $location->coordinates_confirmed_at ? '' : ' · Koordinaten fehlen' }}</option>@endforeach</x-ui.forms.select>
            @error('customerLocationId')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
            @error('workflow')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        </div>
        <x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled" mode="primary">Standort übernehmen</x-ui.buttons.button-basic>
    </form>
    <p class="text-sm text-rt-muted">Koordinaten unter Kunden → Ansprechpartner & Einsatzorte bestätigen. Änderungen dort verschieben diesen gespeicherten Einsatz nicht automatisch.</p>
</section>
