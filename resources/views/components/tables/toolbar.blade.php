@props([])

{{--
    Gemeinsame Listen-Toolbar fuer Tabellen.
    - Slot "bulk": Massenaktionen (links). Optional.
    - Default-Slot: Suche, Filter und "pro Seite" (rechts).
    Alle Controls sind auf eine einheitliche Hoehe (h-11) und Optik abgestimmt
    und stapeln sich auf kleinen Screens sauber untereinander.
--}}
<div
    {{ $attributes->class('flex flex-col gap-3 lg:flex-row lg:items-center') }}
    data-tables-toolbar
>
    @isset($bulk)
        <div class="flex items-center gap-2">
            {{ $bulk }}
        </div>
    @endisset

    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center lg:ml-auto">
        {{ $slot }}
    </div>
</div>
