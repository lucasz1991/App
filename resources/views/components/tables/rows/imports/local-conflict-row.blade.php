{{-- Lokaler Excel-Import: offener Importhinweis mit Fundstelle und Meldungen. --}}
<x-tables.cell :column="$columnsMeta[0]" :primary="true">
    <span class="block text-sm font-semibold">{{ \App\Services\Dropbox\SyncLabels::error($item->reason) }}</span>
    @foreach($item->snapshot['messages'] ?? [] as $messages)@foreach((array) $messages as $message)<span class="mt-1 block text-sm">{{ $message }}</span>@endforeach @endforeach
    @if($item->reason === 'field_conflict')<span class="mt-1 block text-sm">App und Datei wurden unterschiedlich geändert. Die App-Werte bleiben erhalten. Werte in der Originaldatei oder App angleichen und die Datei erneut prüfen/importieren.</span>@endif
</x-tables.cell>
<x-tables.cell :column="$columnsMeta[1]">
    <span class="text-xs">{{ $item->snapshot['sheet'] ?? '' }} · {{ $item->snapshot['slot'] ?? $item->snapshot['row'] ?? '' }}</span>
</x-tables.cell>
