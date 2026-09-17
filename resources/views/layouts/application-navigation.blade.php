<div class="metismenu" id="sidebar-menu">
    <ul id="side-menu" x-data="rtSidebarNavigation">
        @foreach(\App\Support\Operations\ApplicationNavigation::sections(auth()->user()) as $section => $links)
            @if(count($links))
                <x-menu.sidebar-nav :label="$section">
                    @if($section === 'Management')
                        @foreach(\App\Support\Operations\ApplicationNavigation::managementGroups($links) as $label => $group)
                            <x-menu.sidebar-nav-group :icon="$group['icon']" :active="collect($group['links'])->contains(fn ($link) => \App\Support\Operations\ApplicationNavigation::active($link))">
                                <x-slot:label>{{ $label }}</x-slot:label>
                                @foreach($group['links'] as $link)
                                    <x-menu.sidebar-nav-link
                                        :href="route($link['route'], $link['parameters'])"
                                        :icon="$link['icon']"
                                        :navigate="$link['navigate']"
                                        :active="\App\Support\Operations\ApplicationNavigation::active($link)"
                                        class="!pl-8"
                                    >{{ $link['title'] }}</x-menu.sidebar-nav-link>
                                @endforeach
                            </x-menu.sidebar-nav-group>
                        @endforeach
                    @else
                    @foreach($links as $link)
                        <x-menu.sidebar-nav-link
                            :href="route($link['route'], $link['parameters'])"
                            :icon="$link['icon']"
                            :navigate="$link['navigate']"
                            :active="\App\Support\Operations\ApplicationNavigation::active($link)"
                        >{{ $link['title'] }}</x-menu.sidebar-nav-link>
                    @endforeach
                    @endif
                </x-menu.sidebar-nav>
            @endif
        @endforeach
    </ul>
</div>
