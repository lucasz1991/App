@foreach($columnsMeta as $column)
<x-tables.cell :column="$column" class="break-words text-sm">
    {{ data_get($item, $column['key']) }}
</x-tables.cell>
@endforeach
