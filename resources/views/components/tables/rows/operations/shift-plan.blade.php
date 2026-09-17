@php
    $reserved = $item->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability())->count();
    $confirmed = $item->assignments->where('status', \App\Enums\ShiftAssignmentStatus::Confirmed)->count();
    $missing = max(0, $item->required_staff - $reserved);
    $closed = in_array($item->status, [\App\Enums\ShiftStatus::Completed, \App\Enums\ShiftStatus::Cancelled], true);
@endphp
@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @switch($column['key'])
            @case('shift')
                <x-ui.buttons.button-basic type="button" mode="link" wire:click="openDetails({{ $item->id }})" class="min-h-11 max-w-full text-left font-semibold"><span class="break-words">{{ $item->title }}</span></x-ui.buttons.button-basic>
                <p class="mt-1 break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->role_name }}</p>
                @break
            @case('customer')
                <p class="break-words text-sm text-rt-text dark:text-rt-dark-text">{{ $item->order?->customer?->company_name ?? '—' }}</p>
                <p class="mt-1 break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->location_name ?: $item->order?->location_name ?: '—' }}</p>
                @break
            @case('schedule')
                <p class="text-sm tabular-nums text-rt-text dark:text-rt-dark-text"><span class="mr-1 text-xs text-rt-muted md:hidden">Von:</span>{{ $item->starts_at?->format('d.m.Y H:i') }}</p>
                <p class="mt-1 text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">bis {{ $item->ends_at?->format('d.m.Y H:i') }}</p>
                <p class="mt-1 break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->timezone }}</p>
                @break
            @case('staffing')
                <p class="text-sm font-semibold tabular-nums text-rt-text dark:text-rt-dark-text">{{ $reserved }} / {{ $item->required_staff }} <span class="text-xs font-normal text-rt-muted dark:text-rt-dark-muted">eingeplant</span></p>
                <p class="mt-1 text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $confirmed }} bestätigt @if($reserved > $confirmed) · {{ $reserved - $confirmed }} angefragt @endif</p>
                @if(!$closed && $missing > 0)<p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300">{{ $missing }} {{ $missing === 1 ? 'Platz offen' : 'Plätze offen' }}</p>@endif
                @break
            @case('status')
                <x-operations.status :value="$item->status->value" />
                @if($item->revision !== null)
                    <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->published_revision === $item->revision ? 'Veröffentlicht' : ($item->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht') }}</p>
                @endif
                @break
        @endswitch
    </div>
@endforeach
