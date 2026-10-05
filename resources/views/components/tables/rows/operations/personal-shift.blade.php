@php
    $assignment = $item;
    $shift = $assignment->shift;
    $startClockAction = '$dispatch(\'work-clock\', '.\Illuminate\Support\Js::from(['action'=>'start','work_context'=>'shift','assignment_id'=>$assignment->id,'plan_revision'=>$shift->revision,'title'=>$shift->title,'timezone'=>$shift->timezone]).')';
@endphp
<div class="min-w-0 px-3 py-2 space-y-3">
<header class="ops-toolbar"><div><p class="ops-service-date">{{ $shift->starts_at->translatedFormat('D, d. M') }} · {{ $shift->starts_at->format('H:i') }} – {{ $shift->ends_at->format($shift->starts_at->isSameDay($shift->ends_at) ? 'H:i' : 'd.m. H:i') }}</p><h2>{{ $shift->title }}</h2></div><x-operations.status :value="$assignment->status->value" /></header><p>{{ $shift->location_name }} <span class="ops-muted">· {{ $shift->role_name }} · {{ $shift->timezone }}</span></p><p class="ops-muted">{{ $shift->order?->customer?->company_name }} · {{ $shift->order?->order_number }} · {{ $shift->planned_break_minutes }} min Pause</p>
                    @if($assignment->plan_is_stale)<span class="ops-badge" data-state="pending">Planänderung in Prüfung</span>@elseif($assignment->status->value === 'requested')<div class="ops-actions"><x-ui.buttons.button-basic mode="primary" wire:click="respond({{ $assignment->id }},{{ $shift->revision }},true)" wire:loading.attr="disabled">Dienst bestätigen</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="respond({{ $assignment->id }},{{ $shift->revision }},false)" wire:confirm="Dienst ablehnen?" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic></div>
                    @elseif(!$assignment->has_active_time && !$assignment->timeEntry && now()->utc()->gte($shift->starts_at->subMinutes(config('operations.clock_start_early_minutes'))) && now()->utc()->lt($shift->ends_at))<div>
                    @if(\App\Support\Operations\WorkTimeSchema::ready())<x-ui.buttons.button-basic mode="primary" x-bind:disabled="busy || !ready || conflicts > 0 || (!!active && ['running','paused'].includes(active.status))" :x-on:click="$startClockAction">Dienst starten</x-ui.buttons.button-basic>
                    @else<x-ui.buttons.button-basic mode="primary" wire:click="start({{ $assignment->id }},{{ $shift->revision }})" wire:loading.attr="disabled">Dienst starten</x-ui.buttons.button-basic>@endif
                    </div>@endif

                
</div>
