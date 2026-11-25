@props([
    'title',
    'description' => null,
    'id' => null,
    'icon' => null,
    'variant' => 'default',
])

{{-- Wrapper: Dashboard-Abschnittskopf = x-ui.section-header (variant default|minimal). --}}
<x-ui.section-header
    :title="$title"
    :description="$description"
    :id="$id"
    :icon="$icon"
    :variant="$variant === 'minimal' ? 'minimal' : 'default'"
    {{ $attributes }}
>
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-ui.section-header>
