@foreach($columnsMeta as $column)<div class="min-w-0 px-2 py-2">
@switch($column['key'])
@case('role')<x-ui.buttons.button-basic mode="link" wire:click="edit({{ $item->id }})">{{ $item->role_name }}</x-ui.buttons.button-basic>@if($item->status==='cancelled')<x-operations.status value="cancelled" />@endif @break
@case('interval')<p class="text-sm">{{ $item->starts_at->format('d.m.Y H:i') }} – {{ $item->ends_at->format('d.m.Y H:i') }}</p><span class="ops-muted">{{ $item->timezone }}</span>@break
@case('coverage')<p>{{ $item->required_staff }} benötigt · {{ $item->coverage['planned'] }} geplant</p><p class="ops-muted">{{ $item->coverage['confirmed'] }} bestätigt · {{ $item->coverage['open'] }} ungeplant</p>@break
@case('actions')@if($item->status==='active')<div class="ops-actions">@if($item->coverage['open'])<x-ui.buttons.button-basic wire:click="generate({{ $item->id }},{{ $item->revision }})" wire:loading.attr="disabled">Schicht ableiten</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic wire:click="cancel({{ $item->id }},{{ $item->revision }})" wire:confirm="Bedarf stornieren?" wire:loading.attr="disabled">Stornieren</x-ui.buttons.button-basic></div>@endif @break
@endswitch
</div>@endforeach
