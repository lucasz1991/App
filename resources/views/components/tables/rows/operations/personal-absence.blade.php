@php
    $record = $item;
    $wholeDays = $record->starts_at->format('H:i:s') === '00:00:00' && $record->ends_at->format('H:i:s') === '00:00:00';
    $lastDay = $wholeDays ? $record->ends_at->copy()->subDay() : $record->ends_at;
@endphp
<div class="min-w-0 px-3 py-2">
    <div class="ops-row">
        <div>
            <h3>{{ ['vacation'=>'Urlaub','unavailable'=>'Nicht verfügbar','other'=>'Abwesenheit','sick'=>'Krankmeldung'][$record->kind] ?? 'Abwesenheit' }}</h3>
            <p class="ops-muted">{{ $record->starts_at->format($wholeDays ? 'd.m.Y' : 'd.m.Y H:i') }} – {{ $lastDay->format($wholeDays ? 'd.m.Y' : 'd.m.Y H:i') }}{{ $wholeDays ? ' · einschließlich' : '' }}</p>
            @if($record->review_note)<p>{{ $record->review_note }}</p>@endif
            @if($record->status === 'pending')<x-ui.buttons.button-basic mode="link" wire:click="withdraw({{ $record->id }},{{ $record->revision }})" wire:loading.attr="disabled">Zurückziehen</x-ui.buttons.button-basic>@endif
        </div>
        <x-operations.status :value="$record->status" />
    </div>
</div>
