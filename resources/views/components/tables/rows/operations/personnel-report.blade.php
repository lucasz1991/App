@foreach($columnsMeta as $column)
<div class="min-w-0 px-2 py-2 tabular-nums {{ $hideClass($column['hideOn']) }}">{{ data_get($item,$column['key']) ?? 'Prüfung erforderlich' }}</div>
@endforeach
