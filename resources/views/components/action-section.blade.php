{{-- Jetstream-Abschnitt (Profil): Wrapper um die Standardkarte x-ui.surface.card. --}}
<x-ui.surface.card {{ $attributes }}>
    <x-slot:title>{{ $title }}</x-slot:title>
    <x-slot:description>{{ $description }}</x-slot:description>

    {{ $content }}
</x-ui.surface.card>
