@props(['value', 'model', 'dropdownId', 'options' => ['all' => 'Alle', 'intake' => 'Eingänge', 'communication' => 'Kundennachrichten', 'planning' => 'Planung', 'automation' => 'Personalanfragen']])

<x-ui.floating.toolbar label="Aktivitäten filtern">
    <span class="rt-floating-toolbar__label">{{ $options[$value] ?? 'Alle' }}</span>
    <x-slot:actions>
        <x-ui.dropdown.anchor-dropdown align="right" width="64" :offset="6" :dropdown-id="$dropdownId" layer-group="assistant-activity-controls" content-label="Aktivitäten filtern">
            <x-slot:trigger>
                <button type="button" class="rt-floating-toolbar__button" aria-haspopup="menu" aria-label="Aktivitäten filtern: {{ $options[$value] ?? 'Alle' }}" title="Aktivitäten filtern"><i class="far fa-sliders-h" aria-hidden="true"></i></button>
            </x-slot:trigger>
            <x-slot:content>
                @foreach($options as $key => $label)
                    <x-ui.dropdown.dropdown-link role="menuitemradio" aria-checked="{{ $value === $key ? 'true' : 'false' }}" wire:click="$set('{{ $model }}', '{{ $key }}')" x-on:click="close()">
                        <span>{{ $label }}</span>
                        @if($value === $key)<i class="far fa-check ml-auto" aria-hidden="true"></i>@endif
                    </x-ui.dropdown.dropdown-link>
                @endforeach
            </x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
    </x-slot:actions>
</x-ui.floating.toolbar>
