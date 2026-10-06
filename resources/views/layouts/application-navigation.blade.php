<div class="metismenu" id="sidebar-menu">
    <ul id="side-menu" x-data="rtSidebarNavigation">
        @foreach(\App\Support\Operations\ApplicationNavigation::sections(auth()->user()) as $section => $links)
            @if(count($links))
                <x-menu.sidebar-nav :label="$section">
                        @foreach(\App\Support\Operations\ApplicationNavigation::groups($links) as $group)
                            @php($label = $group['label'])
                            @if($label !== '')
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
                            @else
                    @foreach($group['links'] as $link)
                        <x-menu.sidebar-nav-link
                            :href="route($link['route'], $link['parameters'])"
                            :icon="$link['icon']"
                            :navigate="$link['navigate']"
                            :active="\App\Support\Operations\ApplicationNavigation::active($link)"
                        >{{ $link['title'] }}</x-menu.sidebar-nav-link>
                    @endforeach
                            @endif
                        @endforeach
                </x-menu.sidebar-nav>
            @endif
        @endforeach
    </ul>
</div>
