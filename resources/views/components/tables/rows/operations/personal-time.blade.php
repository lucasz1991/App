@php
    $entry = $item;
    $submitClockAction = '$dispatch(\'work-clock\', '.\Illuminate\Support\Js::from(['action'=>'submit','entry_capture_id'=>$entry->capture_id,'revision'=>$entry->revision,'timezone'=>$entry->timezone]).')';
@endphp
<div class="rt-time-entry">
    <header class="rt-time-entry__main"><div><h3>{{ $entry->plan_snapshot['title'] ?? $entry->contextLabel() }}</h3><p class="rt-time-entry__meta">{{ $entry->starts_at->format('d.m.Y') }} · {{ $entry->contextLabel() }}</p></div><x-operations.status :value="$entry->status" /></header>
    <div class="rt-time-entry__main"><p class="rt-time-entry__meta">{{ $entry->starts_at->format('H:i') }} – {{ $entry->ends_at?->format($entry->ends_at?->isSameDay($entry->starts_at) ? 'H:i' : 'd.m. H:i') ?? 'Läuft' }} · {{ intdiv($entry->pause_seconds,60) }} min Pause</p><strong class="rt-time-entry__duration">{{ \App\Support\Operations\OperationsDateTime::duration($entry->netSeconds()) }}</strong></div>
    @if($entry->review_note)<p class="ops-errors">{{ $entry->review_note }}</p>@endif
    <div class="ops-actions">
        @if(in_array($entry->status,['completed','returned']))
            <x-ui.buttons.button-basic wire:click="editTime({{ $entry->id }})">Korrigieren</x-ui.buttons.button-basic>
            @if(\App\Support\Operations\WorkTimeSchema::ready())<x-ui.buttons.button-basic wire:click="openSections({{ $entry->id }})">Arbeitsabschnitte</x-ui.buttons.button-basic>@endif
        @endif
        @if($entry->status === 'completed')
            @if(\App\Support\Operations\WorkTimeSchema::ready() && $entry->capture_id)
                <x-ui.buttons.button-basic mode="primary" :x-on:click="$submitClockAction" x-bind:disabled="busy || !ready || conflicts > 0">Zur Prüfung einreichen</x-ui.buttons.button-basic>
            @else
                <x-ui.buttons.button-basic mode="primary" wire:click="clock({{ $entry->id }},{{ $entry->revision }},'submit')" wire:loading.attr="disabled">Zur Prüfung einreichen</x-ui.buttons.button-basic>
            @endif
        @endif
    </div>
</div>
