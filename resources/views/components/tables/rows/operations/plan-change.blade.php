@foreach($columnsMeta as $column)
<div class="min-w-0 break-words px-2 py-2 text-sm">
    <span class="mr-1 text-xs text-rt-muted md:hidden">{{ $column['label'] }}:</span>{{ data_get($item, $column['key']) }}
</div>
@endforeach
