@php
    $heading = $item->title ?? $item->name ?? ($item->month ? $item->month.' · '.($item->user?->name ?? 'Mitarbeiter #'.$item->user_id) : 'Mitarbeiter #'.$item->user_id);
    $status = $item->status ?? ($item instanceof \App\Models\OperationsRateRule ? 'approved' : ($item->revoked_at ? 'revoked' : 'active'));
@endphp
@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @switch($column['key'])
            @case('title')<span class="font-semibold">{{ $heading }}</span>@break
            @case('user_id')@if($item->user)<x-user.public-info :user="$item->user" :show-presence="false" />@else<span class="ops-muted">—</span>@endif @break
            @case('status')<x-operations.status :value="$status" />@break
            @case('revision')<span class="tabular-nums">{{ $item->revision ?? 1 }}</span>@break
            @case('action')<x-ui.buttons.button-basic type="button" wire:click="openDetails({{ $item->id }})">Öffnen</x-ui.buttons.button-basic>@break
        @endswitch
    </div>
@endforeach
