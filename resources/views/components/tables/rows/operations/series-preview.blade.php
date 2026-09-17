@foreach($columnsMeta as $column)<div class="min-w-0 px-2 py-2 text-sm">
<span class="text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span>
@if($column['key']==='error')<span @class(['text-red-700 dark:text-red-300'=>$item->error])>{{ $item->error ?: 'Bereit' }}</span>
@elseif(in_array($column['key'],['starts_at','ends_at'])){{ $item->{$column['key']} ? \Carbon\CarbonImmutable::parse($item->{$column['key']})->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') : '—' }}
@else{{ \Carbon\CarbonImmutable::parse($item->date)->format('d.m.Y') }}@endif
</div>@endforeach
