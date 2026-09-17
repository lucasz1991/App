@foreach($columnsMeta as $column)
    @php
        $key = $column['key'];
        $value = match($key) {
            'qualification_label' => $item->type?->name,
            'validity' => $item->validityLabel(),
            'absence_label' => ['vacation'=>'Urlaub', 'unavailable'=>'Nicht verfügbar', 'other'=>'Abwesenheit'][$item->kind] ?? $item->kind,
            'net_time' => \App\Support\Operations\OperationsDateTime::duration($item->netSeconds()),
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
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @if($loop->first && $safeDetailAction)
            <x-ui.buttons.button-basic mode="link" type="button" wire:click="{{ $safeDetailAction }}({{ $item->id }})" class="min-h-11 text-left font-semibold">{{ $value ?: '—' }}</x-ui.buttons.button-basic>
        @elseif($key === 'status')
            <x-operations.status :value="$status" />
        @else
            <span class="mr-1 text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span><span class="break-words text-rt-muted dark:text-rt-dark-muted">{{ $value ?? '—' }}</span>
        @endif
    </div>
@endforeach
