@props([
    'section',
    'label',
    'description',
    'icon' => 'fad fa-sliders-h',
])

<x-ui.accordion.section
    :section="$section"
    :label="$label"
    :description="$description"
    :icon="$icon"
    id-prefix="admin-settings"
    {{ $attributes }}
>
    {{ $slot }}
</x-ui.accordion.section>
