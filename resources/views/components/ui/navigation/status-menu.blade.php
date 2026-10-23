@props([
    'options' => [],
    'counts' => [],
    'value' => null,
    'action' => null,
    'label' => 'Bearbeitungsstand',
    'disabled' => false,
    'variant' => 'group',
    'dropdownId' => null,
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
    <x-ui.buttons.btn-group.btn-group class="rt-status-menu__group" :label="$label">
    @foreach($options as $key => $optionLabel)
        <button type="button" class="rt-status-menu__item"
            data-status-value="{{ $key }}"
            aria-pressed="{{ (string) $value === (string) $key ? 'true' : 'false' }}"
            @disabled($isDisabled)
            @if(! $isDisabled)
                wire:click="{{ $safeAction }}({{ \Illuminate\Support\Js::from((string) $key) }})"
                wire:loading.attr="disabled" wire:target="{{ $safeAction }}"
            @endif>
            <span>{{ $optionLabel }}</span>
            @if(isset($counts[$key]))
                <span class="rt-status-menu__count">{{ $counts[$key] }}</span>
            @endif
        </button>
    @endforeach
    </x-ui.buttons.btn-group.btn-group>
    @endif
</nav>
