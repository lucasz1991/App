@foreach($columnsMeta as $column)
<div class="min-w-0 px-2 py-2">
@if($column['key'] === 'title')
    @can('operations.manage')<a class="ops-link" href="{{ route('operations.workspace', ['module'=>'shift-management', 'shift'=>$item->id]) }}">{{ $item->title }}</a>@else{{ $item->title }}@endcan
@else
    <span class="text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span> {{ $item->{$column['key']}->format('d.m.Y H:i') }}
@endif
</div>
@endforeach
