@props([
    'title' => null,
    'eyebrow' => null,
    'description' => null,
    'count' => null,
])

{{--
    Einheitlicher Seitenrahmen fuer ALLE Admin- und Nutzerseiten.
    Sorgt fuer identisches Padding, denselben Seitenkopf (Eyebrow + Titel +
    Beschreibung) und einen konsistenten Aktionsbereich oben rechts.

    Verwendung:
        <x-ui.page :title="__('app.employees')" eyebrow="Administration">
            <x-slot:actions> ...Buttons... </x-slot:actions>
            ...Seiteninhalt...
        </x-ui.page>
--}}
<div {{ $attributes->class('space-y-6 px-2 py-6 lg:py-8') }}>
    @if (! is_null($title) || isset($actions))
        <x-ui.page-header :title="$title" :eyebrow="$eyebrow" :description="$description" :count="$count">
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset
        </x-ui.page-header>
    @endif

    {{ $slot }}
</div>
