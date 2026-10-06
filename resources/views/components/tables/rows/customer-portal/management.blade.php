@php($tab = $__livewire->tab)
@php($portalEnabled = $item->portal_enabled ?? false)
@php($actionId = $item->action_id ?? $item->id)
@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}" role="cell">
        @switch($column['key'])
            @case('title')<strong class="block break-words">{{ $item->title ?? $item->name ?? $item->event ?? 'Vorgang' }}</strong><span class="block break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item->summary ?? $item->recipient ?? '' }}</span>@break
            @case('role'){{ $item->role ?? '—' }}@break
            @case('status')<span class="rt-customer-portal__status">{{ \App\Livewire\CustomerPortal\Workspace::statusLabel($item->status ?? ($item->approved_at ? 'approved' : 'draft')) }}</span>@break
            @case('revision')<span class="tabular-nums">{{ $item->revision ?? '—' }}</span>@break
            @case('actions')
                <div class="flex flex-wrap gap-2">
                @if(in_array($tab,['access','contacts']))<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('contact',{{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>@if($portalEnabled && $item->status !== 'active')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('invite',{{ $item->id }})">Einladen</x-ui.buttons.button-basic>@endif @if($item->status !== 'revoked')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('revoke',{{ $item->id }})">Widerrufen</x-ui.buttons.button-basic>@endif
                @elseif($tab==='automation')@if(($item->kind ?? '')==='commitment')@if($item->status==='consented')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('approve-commitment',{{ $item->id }})">Kontingent freigeben</x-ui.buttons.button-basic>@endif @else<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('profile',{{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>@unless($item->approved_at)<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('approve-profile',{{ $item->id }})">Freigeben</x-ui.buttons.button-basic>@endunless @endif
                @elseif($tab==='publications')@if(($item->status ?? '')==='quarantined')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('review-document',{{ $item->id }})">Prüfen</x-ui.buttons.button-basic>@elseif(($item->status ?? '')==='published')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('withdraw',{{ $item->id }})">Zurückziehen</x-ui.buttons.button-basic>@endif
                @elseif($tab==='requests')
                    @if(($item->subject_type ?? '')==='submission')
                        @if($__livewire->canDecide())<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('decision',{{ $actionId }})">Entscheiden</x-ui.buttons.button-basic>@endif
                        @if($__livewire->canCustomer('customers.portal.automation'))<x-ui.buttons.button-basic type="button" mode="link" wire:click="simulate({{ $actionId }})">Prüfen</x-ui.buttons.button-basic>@endif
                    @elseif(($item->subject_type ?? '')==='request' && in_array($item->status,['submitted','reviewing']))<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('review-request',{{ $actionId }})">Bearbeiten</x-ui.buttons.button-basic>
                    @elseif(($item->subject_type ?? '')==='message')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('reply',{{ $actionId }})">Antworten</x-ui.buttons.button-basic>
                    @elseif(($item->subject_type ?? '')==='attachment')
                        @if(\Illuminate\Support\Facades\Route::has('customer-portal.manager.attachment'))<a class="rt-ui-button rt-ui-button-secondary" href="{{ route('customer-portal.manager.attachment',['id'=>$actionId]) }}">Datei prüfen</a>@endif
                        @if($item->status==='quarantined')<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('review-attachment',{{ $actionId }})">Prüfung bestätigen</x-ui.buttons.button-basic>@endif
                    @endif
                @endif
                </div>
                @break
        @endswitch
    </div>
@endforeach
