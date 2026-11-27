{{-- Reiterleiste der Vorgangslisten (ServiceFulfilment, TimeAdministration); nur ohne Einbettung sichtbar. --}}
@if(!$embedded)<nav class="flex flex-wrap gap-2" aria-label="Leistungen und Zeitwirtschaft">
        @foreach($this->tabs() as $key => $label)
            <x-ui.buttons.button-basic type="button" :mode="$tab === $key ? 'primary' : 'secondary'" wire:click="setTab('{{ $key }}')" :aria-current="$tab === $key ? 'page' : null">{{ $label }}</x-ui.buttons.button-basic>
        @endforeach
    </nav>@endif
