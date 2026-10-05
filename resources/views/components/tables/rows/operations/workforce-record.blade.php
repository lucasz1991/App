@php($personal = (bool)data_get($item,'personal_view',false))
@foreach($columnsMeta as $column)
<div class="min-w-0 px-2 py-2 {{ $hideClass($column['hideOn']) }}">
@switch($column['key'])
@case('name')
    @if($item instanceof \App\Models\WorkforcePool || (!$personal && $item instanceof \App\Models\AvailabilityPeriod))<x-ui.buttons.button-basic type="button" mode="link" wire:click="edit('{{ $item instanceof \App\Models\WorkforcePool ? 'pool' : 'period' }}',{{ $item->id }})">{{ $item->name }}</x-ui.buttons.button-basic>@else{{ $item->name }}@endif
    @break
@case('pool')<p>{{ ['regular'=>'Stammpool','springer'=>'Springer','reserve'=>'Reserve'][$item->kind] }}</p><p class="ops-muted">{{ $item->location_name ?: '—' }} · {{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}</p>@break
@case('users_count')<span class="tabular-nums">{{ $item->users_count }}</span>@break
@case('wish')<x-user.public-info :user="$item->user" :show-presence="false" :show-email="false" /><p class="text-sm font-semibold">{{ ['available'=>'Verfügbar','preferred'=>'Dienstwunsch','unavailable'=>'Freiwunsch'][$item->kind] }}</p>@break
@case('window')<p>{{ $item->from->format('d.m.Y') }} – {{ $item->until->format('d.m.Y') }}</p><p class="ops-muted">{{ collect($item->weekdays)->map(fn($day)=>[1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'][(int)$day])->implode(', ') }} · {{ $item->whole_day ? 'Ganztägig' : $item->start_time.'–'.$item->end_time }} · {{ $item->timezone }}</p>@break
@case('period')<p>{{ $item->from->format('d.m.Y') }} – {{ $item->until->format('d.m.Y') }}</p><p class="ops-muted">Abgabe {{ $item->due_at->setTimezone($item->timezone)->format('d.m.Y H:i') }}</p>@break
@case('due_at'){{ $item->due_at->setTimezone($item->timezone)->format('d.m.Y H:i') }}@break
@case('submission_state'){{ ['submitted'=>'Eingereicht','open'=>'Offen','overdue'=>'Überfällig'][$item->submission_state] }}@break
@case('offer')<p class="font-semibold">{{ $item->shift->title }}</p><p class="ops-muted">{{ $item->shift->starts_at->setTimezone($item->shift->timezone)->format('d.m.Y H:i') }} – {{ $item->shift->ends_at->setTimezone($item->shift->timezone)->format('d.m.Y H:i') }} · {{ $item->shift->role_name }} · {{ $item->shift->location_name }}</p>@break
@case('offer_status')<p>{{ $item->expires_at->setTimezone(config('operations.display_timezone','Europe/Berlin'))->format('d.m.Y H:i') }}</p><p class="ops-muted">{{ $item->expires_at->isPast() ? 'Abgelaufen' : ['open'=>'Offen','filled'=>'Besetzt'][$item->status] }}</p>@break
@case('transfer')<p class="font-semibold">{{ $item->source->shift->title }}</p><p class="ops-muted">{{ $item->source->user->name }} → {{ $item->targetUser->name }}{{ $item->target_assignment_id ? ' · Tausch' : ' · Übergabe' }}</p>@break
@case('transfer_status'){{ ['awaiting_target'=>'Zustimmung ausstehend','pending'=>'Freigabe ausstehend','approved'=>'Freigegeben','rejected'=>'Abgelehnt','declined'=>'Nicht angenommen','withdrawn'=>'Zurückgezogen'][$item->status] }}@break
@case('case')<p class="font-semibold">{{ $item->shift->title }}</p><p class="ops-muted">{{ ['failure'=>'Ausfall','relief'=>'Ablösung','reserve'=>'Bereitschaft','callout'=>'Abruf'][$item->kind] }}</p>@break
@case('case_status')<p>{{ $item->due_at->setTimezone(config('operations.display_timezone','Europe/Berlin'))->format('d.m.Y H:i') }}</p><p class="ops-muted">{{ ['open'=>'Offen','escalated'=>'Eskaliert','resolved'=>'Gelöst'][$item->status] }}{{ $item->due_at->isPast() && $item->status !== 'resolved' ? ' · Überfällig' : '' }}</p>@break
@case('actions')
<div class="ops-actions">
    @if($item instanceof \App\Models\EmployeeAvailability && $personal)<x-ui.buttons.button-basic type="button" wire:click="edit('wish',{{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>
    @elseif($item instanceof \App\Models\ShiftOffer)
        @if($personal)
            @php($response = $item->responses->firstWhere('user_id',auth()->id()))
            @if($response)<span class="ops-muted">{{ ['interested'=>'Interessiert','declined'=>'Abgelehnt','withdrawn'=>'Zurückgezogen','approved'=>'Zugewiesen','rejected'=>'Nicht ausgewählt'][$response->status] }}</span>@endif
            @if($item->status === 'open' && $item->expires_at->isFuture() && !in_array($response?->status,['approved','rejected'],true))<x-ui.buttons.button-basic type="button" wire:click="offerResponse({{ $item->id }},{{ $item->revision }},'interested')" wire:loading.attr="disabled">Interesse</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="offerResponse({{ $item->id }},{{ $item->revision }},'declined')" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic>@if($response?->status === 'interested')<x-ui.buttons.button-basic type="button" wire:click="offerResponse({{ $item->id }},{{ $item->revision }},'withdrawn')" wire:loading.attr="disabled">Zurückziehen</x-ui.buttons.button-basic>@endif @endif
        @else
            @foreach($item->responses as $response)<div class="space-y-1" wire:key="offer-response-{{ $response->id }}"><span class="text-sm">{{ $response->user->name }} · {{ ['interested'=>'Interessiert','declined'=>'Abgelehnt','withdrawn'=>'Zurückgezogen','approved'=>'Zugewiesen','rejected'=>'Nicht ausgewählt'][$response->status] }}</span>@if($response->status === 'interested' && $item->status === 'open' && $item->expires_at->isFuture())<div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="reviewOffer({{ $response->id }},{{ $response->revision }},true)" wire:loading.attr="disabled">Zuweisen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="reviewOffer({{ $response->id }},{{ $response->revision }},false)" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic></div>@endif</div>@endforeach
        @endif
    @elseif($item instanceof \App\Models\ShiftTransferRequest)
        @if(!$personal && $item->status === 'pending')<x-ui.buttons.button-basic type="button" wire:click="edit('review-transfer',{{ $item->id }})">Prüfen</x-ui.buttons.button-basic>
        @elseif($personal && $item->target_user_id === auth()->id() && $item->status === 'awaiting_target')<x-ui.buttons.button-basic type="button" wire:click="transferResponse({{ $item->id }},{{ $item->revision }},'accept')" wire:loading.attr="disabled">Zustimmen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="transferResponse({{ $item->id }},{{ $item->revision }},'decline')" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic>
        @elseif($personal && $item->source->user_id === auth()->id() && in_array($item->status,['awaiting_target','pending'],true))<x-ui.buttons.button-basic type="button" wire:click="transferResponse({{ $item->id }},{{ $item->revision }},'withdraw')" wire:loading.attr="disabled">Zurückziehen</x-ui.buttons.button-basic>@endif
    @elseif($item instanceof \App\Models\StaffingCase && $item->status !== 'resolved')<x-ui.buttons.button-basic type="button" wire:click="edit('case',{{ $item->id }})">Bearbeiten</x-ui.buttons.button-basic>@endif
</div>@break
@endswitch
</div>
@endforeach
