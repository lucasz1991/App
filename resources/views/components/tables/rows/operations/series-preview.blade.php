@foreach($columnsMeta as $column)<x-tables.cell :column="$column" class="text-sm">
@if($column['key']==='error')<span @class(['text-red-700 dark:text-red-300'=>$item->error])>{{ $item->error ?: 'Bereit' }}</span>
@elseif(in_array($column['key'],['starts_at','ends_at'])){{ $item->{$column['key']} ? \Carbon\CarbonImmutable::parse($item->{$column['key']})->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') : '—' }}
@else{{ \Carbon\CarbonImmutable::parse($item->date)->format('d.m.Y') }}@endif
</x-tables.cell>@endforeach
