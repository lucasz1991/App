@props(['label'])

<div class="flex justify-between text-sm text-gray-700 border-b border-dotted py-1">
    <div class="font-medium text-gray-600">{{ $label }}:</div>
    <div class="text-right">{{ $slot }}</div>
</div>
