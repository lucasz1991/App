@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}" role="cell">
        @switch($column['key'])
            @case('title')<strong class="block break-words">{{ $item->title ?? $item->name ?? 'Vorgang' }}</strong>@if(filled($item->number ?? null))<span class="block text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $item->number }}</span>@endif @if(filled($item->summary ?? null))<span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->summary }}</span>@endif @break
            @case('period')<span class="tabular-nums">{{ filled($item->starts_at ?? null) ? \Carbon\CarbonImmutable::parse($item->starts_at)->setTimezone($item->timezone ?? config('operations.display_timezone','Europe/Berlin'))->format('d.m.Y H:i') : '—' }}</span>@if(filled($item->ends_at ?? null))<span class="block text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">bis {{ \Carbon\CarbonImmutable::parse($item->ends_at)->setTimezone($item->timezone ?? config('operations.display_timezone','Europe/Berlin'))->format('d.m.Y H:i') }}</span>@endif @break
            @case('status')<span class="rt-customer-portal__status" data-state="{{ $item->status ?? 'new' }}">{{ \App\Livewire\CustomerPortal\Workspace::statusLabel($item->status ?? 'new') }}</span>@break
            @case('revision')<span class="tabular-nums">{{ $item->revision ?? 1 }}</span>@break
            @case('actions')<x-ui.buttons.button-basic type="button" mode="link" wire:click="openDetails('{{ $item->subject_type ?? 'request' }}', {{ $item->publication_id ?? $item->action_id ?? $item->id }})" :aria-label="'Öffnen: '.($item->title ?? $item->name ?? 'Vorgang')">Öffnen<x-customer-portal.icon name="arrow-right" /></x-ui.buttons.button-basic>@break
        @endswitch
    </div>
@endforeach
