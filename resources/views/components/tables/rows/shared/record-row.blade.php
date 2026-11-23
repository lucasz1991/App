@php
    // Plan/Ist-Vergleich: vom Livewire-Component je Zeile vorberechnet
    // (time_comparison); nur ohne Vorberechnung einmal je Zeile hier.
    $comparison = collect($columnsMeta)->contains(fn ($column) => in_array($column['key'], ['planned_net', 'time_delta'], true))
        ? ($item->getAttributes()['time_comparison'] ?? app(\App\Services\Operations\WorkTimeService::class)->comparison($item))
        : null;
@endphp
@foreach($columnsMeta as $column)
    @php
        $key = $column['key'];
        $value = match($key) {
            'qualification_label' => $item->type?->name,
            'validity' => $item->validityLabel(),
            'absence_label' => ['vacation'=>'Urlaub', 'unavailable'=>'Nicht verfügbar', 'other'=>'Abwesenheit', 'sick'=>'Krankmeldung'][$item->kind] ?? $item->kind,
            'net_time' => \App\Support\Operations\OperationsDateTime::duration($item->netSeconds()),
            'planned_net' => $comparison['planned'] === null ? '—' : \App\Support\Operations\OperationsDateTime::duration($comparison['planned']),
            'time_delta' => null,
            'channel' => ['email'=>'E-Mail', 'phone'=>'Telefon', 'portal'=>'Portal', 'manual'=>'Manuell'][$item->channel] ?? $item->channel,
            default => data_get($item, $key),
        };
        $status = $value instanceof \BackedEnum ? $value->value : (string) $value;
        if ($value instanceof \Carbon\CarbonInterface) {
            $value = $value->format(in_array($key, ['valid_from', 'valid_until']) ? 'd.m.Y' : 'd.m.Y H:i');
        } elseif ($value instanceof \BackedEnum) {
            $value = method_exists($value, 'label') ? $value->label() : $value->value;
        } elseif (is_bool($value)) {
            $value = $value ? 'Aktiv' : 'Inaktiv';
        }
    @endphp
    <x-tables.cell :column="$column" dense :primary="$loop->first && $safeDetailAction" :status="$key === 'status'">
        @if($loop->first && $safeDetailAction)
            <x-ui.buttons.button-basic mode="link" type="button" wire:click="{{ $safeDetailAction }}({{ $item->id }})" class="min-h-11 text-left font-semibold">{{ $value ?: '—' }}</x-ui.buttons.button-basic>
        @elseif($key === 'time_delta')
            <p class="tabular-nums">{{ $comparison['planned'] === null ? '—' : ($comparison['delta'] === null ? 'Läuft' : ($comparison['delta'] > 0 ? '+' : ($comparison['delta'] < 0 ? '−' : '')).\App\Support\Operations\OperationsDateTime::duration(abs($comparison['delta']))) }}</p>
            @foreach($comparison['warnings'] as $warning)<p class="text-xs text-amber-700 dark:text-amber-300">{{ $warning }}</p>@endforeach
        @elseif($key === 'status')
            <x-operations.status :value="$status" />
        @else
            <span class="break-words text-rt-muted dark:text-rt-dark-muted">{{ $value ?? '—' }}</span>
        @endif
    </x-tables.cell>
@endforeach
