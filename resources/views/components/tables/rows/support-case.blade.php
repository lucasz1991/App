<div class="min-w-0"><button type="button" wire:click="openCase('{{ $item->public_id }}')" class="text-left font-semibold text-slate-900 hover:underline">{{ $item->subject }}</button><p class="text-xs text-slate-500">{{ $item->updated_at->format('d.m.Y. H:i') }}</p></div>
<div>{{ $item->user?->name }}</div>
<div>{{ $item->device?->display_name ?? 'Ohne Gerätebindung' }}@if ($item->device?->ownership === 'byod')<span class="block text-xs text-rose-600">Privatgerät</span>@endif</div>
<div>{{ \App\Services\Support\SupportCaseService::STATUSES[$item->status] ?? $item->status }}</div>
