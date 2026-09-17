@foreach($columnsMeta as $column)<div class="min-w-0 px-2 py-2">
@if($column['key']==='message')
    <p class="font-semibold">{{ \App\Services\Operations\DutyActivityService::REPORTS[$item->kind] }}@if($item->delay_minutes) · {{ $item->delay_minutes }} min @endif</p>
    <p class="break-words text-sm">{{ $item->message }}</p><p class="ops-muted">{{ $item->user?->name }} · Planrevision {{ $item->plan_revision }}</p>
    @if($item->resolution)<p class="mt-2 text-sm">{{ $item->resolution }}</p>@endif
@else
    <span class="ops-badge" data-state="{{ $item->status==='open' ? 'pending' : 'approved' }}">{{ $item->status==='open' ? 'Offen' : 'Erledigt' }}</span>
    @if(!$this->employeeMode && $item->status==='open')<x-ui.buttons.button-basic wire:click="openResolution({{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>@endif
@endif
</div>@endforeach
