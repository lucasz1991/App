<div class="metismenu" id="sidebar-menu">
    <ul id="side-menu" x-data="rtSidebarNavigation">
        @foreach(\App\Support\Operations\ApplicationNavigation::sections(auth()->user()) as $section => $links)
            @if(count($links))
                <x-menu.sidebar-nav :label="$section">
                    @foreach($links as $link)
                        <x-menu.sidebar-nav-link
                            :href="route($link['route'], $link['parameters'])"
                            :icon="$link['icon']"
                            :navigate="$link['navigate']"
                            :active="\App\Support\Operations\ApplicationNavigation::active($link)"
                        >{{ $link['title'] }}</x-menu.sidebar-nav-link>
                    @endforeach
                </x-menu.sidebar-nav>
            @endif
        @endforeach
    </ul>
</div>
