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
        <x-ui.dropdown.anchor-dropdown :dropdown-id="$dropdownId" align="right" width="56"
            :content-label="$label" content-classes="rt-status-menu__options">
            <x-slot:trigger>
                <x-ui.buttons.button-basic type="button" class="rt-status-menu__trigger"
                    :disabled="$isDisabled ? true : null"
                    :aria-label="$label.': '.$selectedLabel.($selectedCount !== null ? ' ('.$selectedCount.')' : '')"
                    :title="$label.': '.$selectedLabel"
                    x-on:keydown.arrow-down.prevent.stop="if (!open) toggle(); $nextTick(() => $refs.panel.querySelector('[aria-checked=true]:not(:disabled)')?.focus())"
                    x-on:keydown.arrow-up.prevent.stop="if (!open) toggle(); $nextTick(() => $refs.panel.querySelector('[aria-checked=true]:not(:disabled)')?.focus())">
                    <i class="far fa-filter" aria-hidden="true"></i>
                    <span class="rt-status-menu__selection">{{ $selectedLabel }}</span>
                    @if($selectedCount !== null)<span class="rt-status-menu__count">{{ $selectedCount }}</span>@endif
                    <i class="far fa-chevron-down rt-status-menu__chevron" aria-hidden="true"></i>
                </x-ui.buttons.button-basic>
            </x-slot:trigger>
            <x-slot:content>
                @foreach($options as $key => $optionLabel)
                    <button type="button" class="rt-status-menu__option" role="menuitemradio"
                        data-status-value="{{ $key }}"
                        aria-checked="{{ (string) $value === (string) $key ? 'true' : 'false' }}"
                        x-on:keydown.arrow-down.prevent="$focus.wrap().within($el.parentElement).next()"
                        x-on:keydown.arrow-up.prevent="$focus.wrap().within($el.parentElement).previous()"
                        x-on:keydown.home.prevent="$focus.within($el.parentElement).first()"
                        x-on:keydown.end.prevent="$focus.within($el.parentElement).last()"
                        @disabled($isDisabled)
                        @if(! $isDisabled)
                            wire:click="{{ $safeAction }}({{ \Illuminate\Support\Js::from((string) $key) }})"
                            wire:loading.attr="disabled" wire:target="{{ $safeAction }}"
                            x-on:click="close(true)"
                        @endif>
                        <i class="far fa-check rt-status-menu__check" aria-hidden="true"></i>
                        <span>{{ $optionLabel }}</span>
                        @if(isset($counts[$key]))<span class="rt-status-menu__count">{{ $counts[$key] }}</span>@endif
                    </button>
                @endforeach
            </x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
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
