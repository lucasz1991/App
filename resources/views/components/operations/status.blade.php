@props(['value'])
<span class="ops-badge" data-state="{{ $value }}">{{ \App\Support\Operations\OperationsNavigation::status($value) }}</span>
