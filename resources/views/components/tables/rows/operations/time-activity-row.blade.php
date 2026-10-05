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
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}"><span class="mr-1 text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span><span>{{ $value }}</span></div>
@endforeach
