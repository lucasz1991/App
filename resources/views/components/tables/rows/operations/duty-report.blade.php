@foreach($columnsMeta as $column)<div class="min-w-0 px-2 py-2">
@if($column['key']==='message')
    <p class="font-semibold">{{ \App\Services\Operations\DutyActivityService::REPORTS[$item->kind] }}@if($item->delay_minutes) · {{ $item->delay_minutes }} min @endif</p>
    <p class="break-words text-sm">{{ $item->message }}</p>
    <div class="mt-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
        @if($item->user)
            <x-user.public-info :user="$item->user" :size="6" :show-email="false" :show-presence="false" />
        @else
            <span class="ops-muted">Unbekannter Mitarbeiter</span>
        @endif
        <span class="ops-muted">Planrevision {{ $item->plan_revision }}</span>
    </div>
    @if($item->resolution)<p class="mt-2 text-sm">{{ $item->resolution }}</p>@endif
@else
    <span class="ops-badge" data-state="{{ $item->status==='open' ? 'pending' : 'approved' }}">{{ $item->status==='open' ? 'Offen' : 'Erledigt' }}</span>
    @if(!$this->employeeMode && $item->status==='open')<x-ui.buttons.button-basic wire:click="openResolution({{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>@endif
@endif
</div>@endforeach
