@props(['modules', 'current' => null])
<div>
    @if(!$current)
    <nav class="ops-tabs ops-module-desktop" aria-label="Arbeitsbereiche">
        @foreach($modules as $slug=>$item)<a href="{{ route('operations.workspace',$slug) }}" wire:navigate @if($slug === $current) aria-current="page" @endif>{{ $item['title'] }}</a>@endforeach
    </nav>
    @endif
    <div class="ops-module-mobile" x-data>
        <x-ui.forms.select aria-label="Arbeitsbereich wechseln" change="if ($event.target.value) window.location.assign($event.target.value)">
            @if(!$current)<option value="">Arbeitsbereich öffnen</option>@endif
            @foreach($modules as $slug=>$item)<option value="{{ route('operations.workspace',$slug) }}" @selected($slug === $current)>{{ $item['title'] }}</option>@endforeach
        </x-ui.forms.select>
    </div>
</div>
