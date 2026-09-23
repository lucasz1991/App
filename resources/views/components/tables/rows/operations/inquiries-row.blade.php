@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}" wire:key="inquiry-{{ $item->id }}-{{ $column['key'] }}">
        @switch($column['key'])
            @case('title')
                <span class="rt-disposition-record-code">{{ $item->number }}</span>
                <button type="button" class="rt-disposition-record-title" wire:click="select({{ $item->id }})" wire:loading.attr="disabled" wire:target="select">{{ $item->title }}</button>
                <span class="rt-disposition-record-meta">@if($item->required_staff){{ $item->required_staff }} × @endif{{ $item->role_name ?: 'Tätigkeit noch offen' }}</span>
                @break
            @case('customer')
                <span>{{ $item->customer?->company_name ?? 'Noch nicht zugeordnet' }}</span>
                <span class="rt-disposition-record-meta">{{ ['email' => 'E-Mail', 'phone' => 'Telefon', 'portal' => 'Portal', 'manual' => 'Manuell'][$item->channel] ?? $item->channel }}</span>
                @break
            @case('schedule')
                @if($item->starts_at)
                    <span class="tabular-nums">{{ $item->starts_at->format('d.m.Y') }}</span>
                    <span class="rt-disposition-record-meta tabular-nums">{{ $item->starts_at->format('H:i') }}@if($item->ends_at)–{{ $item->ends_at->format($item->starts_at->isSameDay($item->ends_at) ? 'H:i' : 'd.m. H:i') }}@endif</span>
                    <span class="rt-disposition-record-meta">{{ $item->timezone }}</span>
                @else
                    <span class="rt-disposition-record-meta">Termin noch offen</span>
                @endif
                @if($item->location_name)<span class="rt-disposition-record-meta">{{ $item->location_name }}</span>@endif
                @break
            @case('status')
                <x-operations.status :value="$item->status" />
                @break
        @endswitch
    </div>
@endforeach
