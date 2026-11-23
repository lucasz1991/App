@props([
    'options' => [],
    'counts' => [],
    'value' => null,
    'action' => null,
    'label' => 'Bearbeitungsstand',
    'disabled' => false,
    'variant' => 'group',
    'dropdownId' => null,
    // Nur Variante "group": FA-Icon je Option (Standard fa-filter).
    'icons' => [],
])

@php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) ? $action : null;
    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || $safeAction === null;
    $isDropdown = $variant === 'dropdown';
    $selectedLabel = $options[$value] ?? $label;
    $selectedCount = $counts[$value] ?? null;
@endphp

{{-- A status filter changes one list, rather than switching tab panels. --}}
<nav {{ $attributes->class(['rt-status-menu', 'rt-status-menu--dropdown' => $isDropdown]) }} aria-label="{{ $label }}"
    @if($safeAction) wire:loading.attr="aria-busy" wire:target="{{ $safeAction }}" @endif>
    @if($isDropdown)
        <x-ui.forms.select :id="$dropdownId" :value="$value" :action="$safeAction" :counts="$counts"
            variant="compact" icon="far fa-filter" :disabled="$isDisabled"
            :aria-label="$label.': '.$selectedLabel.($selectedCount !== null ? ' ('.$selectedCount.')' : '')"
            :title="$label.': '.$selectedLabel"
            wire:loading.attr="disabled" wire:target="{{ $safeAction }}">
            @foreach($options as $key => $optionLabel)
                <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $optionLabel }}</option>
            @endforeach
        </x-ui.forms.select>
    @else
        @php
            // Segmentwahl ueber den gemeinsamen Baustein; Zaehler stehen im Text.
            $toggleOptions = collect($options)->map(fn ($optionLabel, $key) => [
                'value' => (string) $key,
                'label' => $optionLabel.(isset($counts[$key]) ? ' ('.$counts[$key].')' : ''),
                'icon' => $icons[$key] ?? 'fa-filter',
            ])->values()->all();
        @endphp
        <x-ui.buttons.multi-toggle class="rt-status-menu__group" :options="$toggleOptions" :value="(string) $value"
            :action="$safeAction" :label="$label" :disabled="$isDisabled" :show-labels="true" />
    @endif
</nav>
