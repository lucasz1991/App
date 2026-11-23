<x-tables.cell label="Anfrage" primary><button type="button" wire:click="openCase('{{ $item->public_id }}')" class="text-left font-semibold text-slate-900 hover:underline">{{ $item->subject }}</button><p class="text-xs text-slate-500">{{ $item->updated_at->format('d.m.Y. H:i') }}</p></x-tables.cell>
<x-tables.cell label="Mitarbeiter">{{ $item->user?->name }}</x-tables.cell>
<x-tables.cell label="Gerät">{{ $item->device?->display_name ?? 'Ohne Gerätebindung' }}@if ($item->device?->ownership === 'byod')<span class="block text-xs text-rose-600">Privatgerät</span>@endif</x-tables.cell>
<x-tables.cell label="Status" status>{{ \App\Services\Support\SupportCaseService::STATUSES[$item->status] ?? $item->status }}</x-tables.cell>
