@php
    $reserved = $item->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability())->count();
    $confirmed = $item->assignments->where('status', \App\Enums\ShiftAssignmentStatus::Confirmed)->count();
    $missing = max(0, $item->required_staff - $reserved);
    $closed = in_array($item->status, [\App\Enums\ShiftStatus::Completed, \App\Enums\ShiftStatus::Cancelled], true);
    $displayTimezone = (string) config('operations.display_timezone', 'Europe/Berlin');
    $displayStart = $item->starts_at?->setTimezone($displayTimezone);
    $displayEnd = $item->ends_at?->setTimezone($displayTimezone);
@endphp
@foreach($columnsMeta as $column)
    <div class="rt-table-cell {{ $column['key'] === 'shift' ? 'rt-table-cell--primary' : '' }} {{ $column['key'] === 'status' ? 'rt-table-cell--status' : '' }} {{ $hideClass($column['hideOn']) }}" data-rt-table-label="{{ $column['label'] }}">
        @switch($column['key'])
            @case('shift')
                <div class="rt-table-record">
                    <span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-calendar-day"></i></span>
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow">{{ $item->order?->order_number }}</span>
                        <x-ui.buttons.button-basic type="button" mode="link" wire:click="openDetails({{ $item->id }})" wire:loading.attr="disabled" wire:target="openDetails" class="rt-table-record__title">{{ $item->title }}</x-ui.buttons.button-basic>
                        <span class="rt-table-record__meta">{{ $item->role_name }}</span>
                        @if($item->order?->title && $item->order->title !== $item->title)<span class="rt-table-record__meta">{{ $item->order->title }}</span>@endif
                    </div>
                </div>
                @break
            @case('customer')
                <div>
                    <span class="rt-table-value">{{ $item->order?->customer?->company_name ?? 'Kein Kunde hinterlegt' }}</span>
                    <span class="rt-table-meta">{{ $item->location_name ?: $item->order?->location_name ?: 'Einsatzort offen' }}</span>
                </div>
                @break
            @case('schedule')
                <div>
                    <span class="rt-table-value tabular-nums">{{ $displayStart?->format('d.m.Y H:i') }}</span>
                    <span class="rt-table-meta tabular-nums">bis {{ $displayEnd?->format('d.m.Y H:i') }}</span>
                    <span class="rt-table-meta">{{ $displayTimezone }}</span>
                </div>
                @break
            @case('staffing')
                <div>
                    <span class="rt-table-value tabular-nums">{{ $reserved }} / {{ $item->required_staff }} <span class="rt-table-label">eingeplant</span></span>
                    @if(!$closed && $item->required_staff > 0)
                        <progress class="rt-table-coverage" max="{{ $item->required_staff }}" value="{{ min($reserved, $item->required_staff) }}" aria-label="Besetzung: {{ $reserved }} von {{ $item->required_staff }} eingeplant"></progress>
                    @endif
                    <span class="rt-table-meta tabular-nums">{{ $confirmed }} bestätigt @if($reserved > $confirmed) · {{ $reserved - $confirmed }} angefragt @endif</span>
                    @if(!$closed && $missing > 0)<p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300">{{ $missing }} {{ $missing === 1 ? 'Platz offen' : 'Plätze offen' }}</p>@endif
                    @if($item->planning_conflict_count)<p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300"><i class="far fa-triangle-exclamation" aria-hidden="true"></i> {{ $item->planning_conflict_count }} mit Konflikt</p>@endif
                    @if($item->feedback_pending)<span class="rt-table-meta">{{ $item->feedback_pending }} {{ $item->feedback_pending === 1 ? 'Rückmeldung offen' : 'Rückmeldungen offen' }}</span>@endif
                    @if($item->feedback_declined)<p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300">{{ $item->feedback_declined }} abgelehnt</p>@endif
                </div>
                @break
            @case('status')
                <div>
                    <x-operations.status :value="$item->status->value" :label="$item->status->label()" />
                    @if($item->revision !== null)
                        <span class="rt-table-meta">{{ $item->published_revision === $item->revision ? 'Veröffentlicht' : ($item->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht') }}</span>
                    @endif
                </div>
                @break
        @endswitch
    </div>
@endforeach
