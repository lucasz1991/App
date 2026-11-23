@foreach($columnsMeta as $column)
<x-tables.cell :column="$column" :primary="$column['key'] === 'title'">
@if($column['key'] === 'title')
    @can('operations.manage')<a class="ops-link" href="{{ route('operations.workspace', ['module'=>'shift-management', 'shift'=>$item->id]) }}">{{ $item->title }}</a>@else{{ $item->title }}@endcan
@else
    {{ $item->{$column['key']}->format('d.m.Y H:i') }}
@endif
</x-tables.cell>
@endforeach
