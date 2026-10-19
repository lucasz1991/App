@props(['label', 'model', 'type' => 'text', 'wide' => false, 'scope' => null])
<x-operations.field :label="$label" :model="$model" :type="$type" :wide="$wide" :scope="$scope" {{ $attributes }}>
    {{ $slot }}
</x-operations.field>
