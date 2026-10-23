@props(['value', 'model', 'dropdownId', 'options' => ['all' => 'Alle', 'intake' => 'Eingänge', 'communication' => 'Kundennachrichten', 'planning' => 'Planung', 'automation' => 'Personalanfragen']])

<x-ui.floating.toolbar label="Aktivitäten filtern">
    <span class="rt-floating-toolbar__label">{{ $options[$value] ?? 'Alle' }}</span>
    <x-slot:actions>
        <x-ui.forms.select :id="$dropdownId" wire:model.live="{{ $model }}" variant="icon" icon="far fa-sliders-h"
            aria-label="Aktivitäten filtern: {{ $options[$value] ?? 'Alle' }}" title="Aktivitäten filtern">
            @foreach($options as $key => $label)
                <option value="{{ $key }}" @selected($value === $key)>{{ $label }}</option>
            @endforeach
        </x-ui.forms.select>
    </x-slot:actions>
</x-ui.floating.toolbar>
