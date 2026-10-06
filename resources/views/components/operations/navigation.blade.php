@props(['modules' => [], 'current' => null])
@php($navigation = \App\Support\Operations\ApplicationNavigation::sections(auth()->user()))
<div @class(['ops-module-navigation', 'ops-module-navigation--mobile-only' => (bool) $current])>
    @if(!$current)
    <nav class="ops-tabs ops-module-desktop" aria-label="Arbeitsbereiche">
        @foreach($navigation as $segment => $links)
            @foreach($links as $item)
                @if($item['route'] === 'operations.page')<a href="{{ route($item['route'], $item['parameters']) }}" wire:navigate>{{ $item['title'] }}</a>@endif
            @endforeach
        @endforeach
    </nav>
    @endif
    <div class="ops-module-mobile" x-data>
        <x-ui.forms.select aria-label="Arbeitsbereich wechseln" change="if ($event.target.value) window.location.assign($event.target.value)">
            @if(!$current)<option value="">Arbeitsbereich öffnen</option>@endif
            @foreach($navigation as $segment => $links)
                <optgroup label="{{ $segment ?: 'Start' }}">
                    @foreach($links as $item)<option value="{{ route($item['route'], $item['parameters']) }}" @selected(\App\Support\Operations\ApplicationNavigation::active($item))>{{ $item['group'] ? $item['group'].' · ' : '' }}{{ $item['title'] }}</option>@endforeach
                </optgroup>
            @endforeach
        </x-ui.forms.select>
    </div>
</div>
