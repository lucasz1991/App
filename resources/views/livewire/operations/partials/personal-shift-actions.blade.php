{{-- Aktionen eines eigenen Dienstes (bestätigen, ablehnen, starten); gemeinsam für Tabelle, Kalender und Zeitleiste. --}}
@php
    $modernClock = $workTimeReady ?? \App\Support\Operations\WorkTimeSchema::ready();
    $startClockAction = '$dispatch(\'work-clock\', '.\Illuminate\Support\Js::from(['action'=>'start_duty','assignment_id'=>$assignment->id,'plan_revision'=>$shift->revision,'title'=>$shift->title,'timezone'=>$shift->timezone]).')';
@endphp
@if(!($proofOnly ?? false))
@if($assignment->plan_is_stale)<x-operations.status value="pending" label="Planänderung in Prüfung" />@elseif($assignment->status->value === 'requested')<div class="ops-actions"><x-ui.buttons.button-basic mode="primary" wire:click="respond({{ $assignment->id }},{{ $shift->revision }},true)" wire:loading.attr="disabled">Dienst bestätigen</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="respond({{ $assignment->id }},{{ $shift->revision }},false)" wire:confirm="Dienst ablehnen?" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic></div>
                    @elseif($assignment->status->value === 'confirmed' && ($modernClock || !$assignment->has_active_time) && !$assignment->timeEntry && (($knownClockOffer ?? false) || now()->utc()->gte($shift->starts_at->subMinutes(config('operations.clock_start_early_minutes')))) && now()->utc()->lt($shift->ends_at))<div>
                    @if($modernClock)
                        @if(($knownClockOffer ?? false) || \App\Models\ShiftAssignment::whereKey($assignment->id)->withoutRecordedTime()->whereHas('shift', fn ($query) => $query->whereNotIn('status', ['draft','cancelled','completed']))->exists())
                            <x-ui.buttons.button-basic :mode="($knownClockOffer ?? false) ? 'secondary' : 'primary'" class="rt-day-clock__duty-start"
                                x-show.important="canAttachDuty() && !active?.duty_assignment_ids?.includes({{ (int) $assignment->id }}) && now >= {{ $shift->starts_at->subMinutes(config('operations.clock_start_early_minutes'))->getTimestampMs() }} && now < {{ $shift->ends_at->getTimestampMs() }}"
                                x-bind:disabled="busy || !ready || conflicts > 0 || active?.status === 'paused'" :x-on:click="$startClockAction">Dienst starten</x-ui.buttons.button-basic>
                        @endif
                    @else<x-ui.buttons.button-basic mode="primary" wire:click="start({{ $assignment->id }},{{ $shift->revision }})" wire:loading.attr="disabled">Dienst starten</x-ui.buttons.button-basic>@endif
                    </div>@endif
@endif

@if(!($hideDutyProof ?? false) && !$assignment->plan_is_stale && $shift->public_id && $shift->published_revision > 0 && $shift->published_revision === $shift->revision && in_array($assignment->status->value, ['requested','confirmed'], true) && \Illuminate\Support\Facades\Schema::hasTable('duty_activities') && \App\Support\Operations\OperationsEnhancementsSchema::ready())
    <x-ui.buttons.button-basic mode="link" :href="route('operations.duty-proof', ['duty' => $shift->public_id])">Nachweis zum Dienst</x-ui.buttons.button-basic>
@endif
