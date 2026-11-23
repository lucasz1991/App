@php
    $tab = data_get($item, 'view_tab', 'inbox');
    $zone = config('operations.display_timezone','Europe/Berlin');
@endphp
@foreach($columnsMeta as $column)
@php($cell = $column['key'])
<div class="min-w-0 px-2 py-2 {{ $hideClass($column['hideOn']) }}">
@if($cell === 'duty')<strong>{{ $item->user_name }}</strong><div class="ops-muted">{{ $item->title }}</div>
@elseif($cell === 'period')<span>{{ $item->starts_at ? $item->starts_at->setTimezone($zone)->format('d.m. H:i').' – '.$item->ends_at->setTimezone($zone)->format('d.m. H:i') : 'Planstand prüfen' }}</span><div class="ops-muted">{{ $item->actual_start ? 'Start '.$item->actual_start->setTimezone($zone)->format('H:i') : 'Kein Start gemeldet' }}@if($item->actual_end) · Ende {{ $item->actual_end->setTimezone($zone)->format('H:i') }}@endif</div>
@elseif($cell === 'state')<span class="ops-chip">{{ $item->status }}</span>
@elseif($cell === 'tolerances'){{ $item->start_grace_minutes }} / {{ $item->end_grace_minutes }} min
@elseif($cell === 'active'){{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}
@elseif($cell === 'preference'){{ \App\Services\Operations\OperationsReminderService::KINDS[$item->kind] ?? $item->kind }}
@elseif($cell === 'quiet'){{ $item->lead_minutes }} min<div class="ops-muted">{{ $item->quiet_from ? $item->quiet_from.' – '.$item->quiet_until : '—' }}</div>
@elseif($cell === 'work_item')<strong>{{ $item->title }}</strong><div class="ops-muted">{{ $item->kind }}@if(filled($item->subject)) · {{ $item->subject }}@endif</div>
@elseif($cell === 'due'){{ $item->due_at ? $item->due_at->setTimezone($zone)->format('d.m. H:i') : '—' }}<div class="ops-muted">{{ \App\Support\Operations\OperationsNavigation::status($item->status) }}</div>
@elseif($cell === 'actions')
    @if($tab === 'board' && in_array($item->state,['start_missing','end_overdue']))<x-ui.buttons.button-basic type="button" size="sm" wire:click="escalate({{ $item->assignment_id }},{{ $item->plan_revision }})">Meldung prüfen</x-ui.buttons.button-basic>
    @elseif(in_array($tab,['profiles','reminders']))<x-ui.buttons.button-basic type="button" size="sm" wire:click="edit({{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>
    @elseif($tab === 'inbox')<x-ui.buttons.button-basic type="button" size="sm" wire:click="openItem('{{ $item->id }}')">Öffnen</x-ui.buttons.button-basic>@endif
@else{{ $item->{$cell} ?? '—' }}@endif
</div>
@endforeach
