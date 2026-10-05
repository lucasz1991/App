@foreach($columnsMeta as $column)<div class="rt-table-cell {{ in_array($column['key'], ['contact','location','condition']) ? 'rt-table-cell--primary' : '' }}" data-rt-table-label="{{ $column['label'] }}">
    @switch($column['key'])
        @case('contact')<x-ui.buttons.button-basic mode="link" wire:click="editContact({{ $item->id }})">{{ $item->name }}</x-ui.buttons.button-basic><span class="rt-table-meta">{{ $item->email }} {{ $item->phone }}</span>@break
        @case('roles')<span>{{ collect($item->roles)->map(fn($role) => \App\Models\CustomerContact::ROLES[$role] ?? $role)->implode(' · ') }}</span>@break
        @case('active')<span>{{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}</span>@break
        @case('location')<x-ui.buttons.button-basic mode="link" wire:click="editLocation({{ $item->id }})">{{ $item->name }}</x-ui.buttons.button-basic><span class="rt-table-meta">{{ $item->access_note }}</span>@break
        @case('address')<span>{{ $item->street }} {{ $item->postal_code }} {{ $item->city }} {{ $item->country }}</span>@break
        @case('condition')<span class="rt-table-value">{{ $item->label }}</span><span class="rt-table-meta">{{ $item->code }}</span>@break
        @case('price')<span class="tabular-nums">{{ number_format($item->unit_price_cents / 100, 2, ',', '.') }} € / {{ $item->unit }}</span>@break
        @case('valid')<span>{{ $item->valid_from->format('d.m.Y') }} – {{ $item->valid_until?->format('d.m.Y') ?? 'offen' }}</span>@if(!$item->valid_until)<x-ui.buttons.button-basic mode="link" size="sm" wire:click="endCondition({{ $item->id }})">Beenden</x-ui.buttons.button-basic>@endif @break
    @endswitch
</div>@endforeach
