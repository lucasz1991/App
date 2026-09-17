@props(['value', 'label' => null])
<span class="ops-badge" data-state="{{ $value }}">{{ $label ?? \App\Support\Operations\OperationsNavigation::status($value) }}</span>
