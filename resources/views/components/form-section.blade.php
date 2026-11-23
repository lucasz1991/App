@props(['submit'])

{{-- Jetstream-Formularabschnitt (Profil): Wrapper um die Standardkarte x-ui.surface.card. --}}
<x-ui.surface.card padding="" {{ $attributes->class('relative') }} data-autosave-scope>
    <x-slot:title>{{ $title }}</x-slot:title>
    <x-slot:description>{{ $description }}</x-slot:description>
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset

    <form wire:submit="{{ $submit }}" class="px-5 py-5 sm:px-6 sm:py-6">
        <div class="grid grid-cols-6 gap-5 sm:gap-6">
            {{ $form }}
        </div>
    </form>
</x-ui.surface.card>
