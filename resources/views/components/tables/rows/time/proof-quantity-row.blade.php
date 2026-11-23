@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @if($column['key']==='activity')<span class="font-semibold">{{ $item->activity }}</span>
        @elseif($column['key']==='quantity')<span class="tabular-nums">{{ number_format((float)$item->quantity,2,',','.') }}</span>
        @else<span class="ops-muted">{{ $item->unit }}</span>@endif
    </div>
@endforeach
