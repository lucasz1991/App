@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}" role="cell">
        @switch($column['key'])
            @case('title')
                <strong class="block break-words font-semibold">{{ $item->title }}</strong>
                <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ ['document'=>'Kundenunterlage','invoice'=>'Rechnungsdokument','attachment'=>'Eingangsanlage'][$item->kind] ?? 'Dokument' }}@if($item->source==='attachment' && in_array($item->source_type,['submission','request'],true)) · {{ $item->source_type==='submission'?'Leistungsanfrage':'Kundenanliegen' }} #{{ $item->source_id }}@endif</span>
                @break
            @case('status')
                <x-operations.status :value="$item->status" :label="['quarantined'=>'Prüfung offen','published'=>'Freigegeben','withdrawn'=>'Zurückgezogen','reviewed'=>'Eingang geprüft','rejected'=>'Abgelehnt'][$item->status] ?? 'In Prüfung'" />
                @break
            @case('revision')<span class="tabular-nums">{{ $item->revision }}</span>@break
            @case('file')
                <span class="block text-sm">{{ ['application/pdf'=>'PDF','image/jpeg'=>'JPEG','image/png'=>'PNG'][$item->file_mime] ?? 'Datei' }}</span>
                <span class="block text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $item->file_size===null?'—':number_format($item->file_size/1024,1,',','.').' KB' }}</span>
                @break
            @case('date')<span class="text-sm tabular-nums">{{ $item->created_at?\Carbon\CarbonImmutable::parse($item->created_at,config('app.timezone'))->setTimezone(config('operations.display_timezone','Europe/Berlin'))->format('d.m.Y H:i'):'—' }}</span>@break
        @endswitch
    </div>
@endforeach
