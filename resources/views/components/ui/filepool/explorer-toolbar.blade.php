@props([
    'layout' => 'grid',
    'fileType' => '',
    'fileTypes' => [],
    'scope' => null,
    'scopeLinks' => [],
    'includeAllScopes' => false,
    'showUp' => true,
    'canGoUp' => false,
])

@php
    $scopes = $includeAllScopes ? ['all', 'required', 'personal', 'shared'] : ['required', 'personal', 'shared'];
    $layouts = ['grid' => 'fa-th-large', 'list' => 'fa-list', 'details' => 'fa-table'];
    $scopeUrls = $layout === 'grid' ? $scopeLinks : array_map(
        fn (string $url): string => $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['layout' => $layout]),
        $scopeLinks,
    );
    if ($scopeUrls !== []) {
        $scopes = array_values(array_intersect($scopes, array_keys($scopeUrls)));
    }
@endphp

<div {{ $attributes->class('rt-explorer-toolbar-shell') }}>
    <div class="rt-explorer-toolbar" role="group" aria-label="{{ __('file_explorer.toolbar') }}" data-explorer-toolbar>
        @if($showUp)
            <button type="button" class="rt-explorer-control rt-explorer-up" wire:click="goUp"
                @disabled(! $canGoUp) aria-label="{{ __('file_explorer.up') }}" title="{{ __('file_explorer.up') }}" data-explorer-up>
                <i class="far fa-arrow-up" aria-hidden="true"></i>
            </button>
        @endif

        @if($scope !== null && $scopes !== [])
            <div class="rt-explorer-segments rt-explorer-scopes" role="group" aria-label="{{ __('file_explorer.scope_label') }}">
                @foreach($scopes as $item)
                    @if(isset($scopeUrls[$item]))
                        <a href="{{ $scopeUrls[$item] }}" class="rt-explorer-segment" data-explorer-scope="{{ $item }}"
                            @if($scope === $item) aria-current="page" @endif>
                            {{ __("file_explorer.scopes.$item") }}
                        </a>
                    @else
                        <button type="button" class="rt-explorer-segment" data-explorer-scope="{{ $item }}"
                            wire:click="setExplorerScope('{{ $item }}')" aria-pressed="{{ $scope === $item ? 'true' : 'false' }}">
                            {{ __("file_explorer.scopes.$item") }}
                        </button>
                    @endif
                @endforeach
            </div>
            <label class="rt-explorer-control rt-explorer-picker rt-explorer-scope-picker" data-scope="{{ $scope }}">
                <span class="rt-explorer-scope-value" aria-hidden="true">{{ __("file_explorer.scopes.$scope") }}</span>
                <select aria-label="{{ __('file_explorer.scope_label') }}" data-explorer-scope-picker
                    @if($scopeUrls !== []) x-data x-on:change="window.location.assign($event.target.value)"
                    @else wire:change="setExplorerScope($event.target.value)" @endif>
                    @foreach($scopes as $item)
                        <option value="{{ $scopeUrls[$item] ?? $item }}" @selected($scope === $item)>
                            {{ __("file_explorer.scopes.$item") }}
                        </option>
                    @endforeach
                </select>
                <i class="far fa-chevron-down rt-explorer-picker-chevron" aria-hidden="true"></i>
            </label>
        @endif

        <label class="rt-explorer-control rt-explorer-search">
            <i class="far fa-search" aria-hidden="true"></i>
            <input type="search" wire:model.live.debounce.250ms="search" autocomplete="off"
                placeholder="{{ __('file_explorer.search_placeholder') }}" aria-label="{{ __('file_explorer.search') }}" data-explorer-search>
        </label>

        <label class="rt-explorer-control rt-explorer-picker rt-explorer-type-picker" @if($fileType !== '') data-active @endif>
            <i class="far fa-filter" aria-hidden="true"></i>
            <select wire:model.live="fileType" aria-label="{{ __('file_explorer.type_filter') }}" data-explorer-type>
                <option value="">{{ __('file_explorer.all_types') }}</option>
                @foreach($fileTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <i class="far fa-chevron-down rt-explorer-picker-chevron" aria-hidden="true"></i>
        </label>

        <div class="rt-explorer-segments rt-explorer-layouts" role="group" aria-label="{{ __('file_explorer.layout_label') }}">
            @foreach($layouts as $value => $icon)
                <button type="button" class="rt-explorer-segment" wire:click="setExplorerLayout('{{ $value }}')"
                    aria-pressed="{{ $layout === $value ? 'true' : 'false' }}" data-explorer-layout="{{ $value }}">
                    <i class="far {{ $icon }}" aria-hidden="true"></i>
                    {{ __("file_explorer.layouts.$value") }}
                </button>
            @endforeach
        </div>
        <label class="rt-explorer-control rt-explorer-picker rt-explorer-layout-picker">
            <i class="far {{ $layouts[$layout] ?? $layouts['grid'] }}" aria-hidden="true"></i>
            <select wire:change="setExplorerLayout($event.target.value)" aria-label="{{ __('file_explorer.layout_label') }}" data-explorer-layout-picker>
                @foreach($layouts as $value => $icon)
                    <option value="{{ $value }}" @selected($layout === $value)>{{ __("file_explorer.layouts.$value") }}</option>
                @endforeach
            </select>
            <i class="far fa-chevron-down rt-explorer-picker-chevron" aria-hidden="true"></i>
        </label>

        @isset($actions)
            <div class="rt-explorer-toolbar-actions">{{ $actions }}</div>
        @endisset
    </div>
</div>
