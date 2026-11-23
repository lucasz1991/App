{{-- Dropbox-Abgleich: offener Konflikt; "Prüfen" bzw. Doppelklick oeffnet ihn. --}}
<x-tables.cell :column="$columnsMeta[0]" :primary="true">
    <span class="block text-sm font-semibold">{{ \App\Services\Dropbox\SyncLabels::error($item->reason) }}</span>
    <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->snapshot['sheet'] ?? '' }} {{ isset($item->snapshot['slot']) ? '· '.$item->snapshot['slot'] : '' }}</span>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[1]">
    <span class="text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $item->updated_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[2]">
    <x-ui.buttons.button-basic type="button" mode="secondary" wire:click="selectConflict({{ $item->id }})">Prüfen</x-ui.buttons.button-basic>
</x-tables.cell>
