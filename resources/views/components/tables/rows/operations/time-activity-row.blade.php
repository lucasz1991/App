@foreach($columnsMeta as $column)
    @php
        $key = $column['key'];
        $value = match($key) {
            'kind' => \App\Services\Operations\WorkTimeActivityService::KINDS[$item->kind] ?? $item->kind,
            'source' => $item->source === 'needs_review' ? 'Abschnitte prüfen' : 'Erfasst',
            'starts_at', 'ends_at' => data_get($item, $key)?->setTimezone($item->display_timezone)->format('d.m. H:i:s') ?? 'Läuft',
            default => data_get($item, $key),
        };
    @endphp
    <x-tables.cell :column="$column" dense><span>{{ $value }}</span></x-tables.cell>
@endforeach
