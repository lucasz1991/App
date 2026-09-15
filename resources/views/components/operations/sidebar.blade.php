@if(\App\Support\Operations\OperationsAccess::ready())
    @php($opsModules = \App\Support\Operations\OperationsNavigation::forUser(auth()->user()))
    @if(count($opsModules) || \App\Support\Operations\OperationsAccess::isEmployee(auth()->user()))
        <x-menu.sidebar-nav label="Arbeitsplatz">
            @if(\App\Support\Operations\OperationsAccess::isEmployee(auth()->user()))<x-menu.sidebar-nav-link :href="route('operations.mine')" icon="clock" :active="request()->routeIs('operations.mine')">Mein Arbeitstag</x-menu.sidebar-nav-link>@endif
            @if(count($opsModules))
                <x-menu.sidebar-nav-group icon="calendar" :active="request()->routeIs('operations.workspace')">
                    <x-slot:label>Betrieb</x-slot:label>
                    @foreach($opsModules as $slug=>$item)
                        <x-menu.sidebar-nav-link :href="route('operations.workspace',$slug)" icon="chevron-right" :active="request()->routeIs('operations.workspace') && request()->route('module') === $slug" class="!pl-8">{{ $item['title'] }}</x-menu.sidebar-nav-link>
                    @endforeach
                </x-menu.sidebar-nav-group>
            @endif
        </x-menu.sidebar-nav>
    @endif
@endif
