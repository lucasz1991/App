<div class="metismenu" id="sidebar-menu">
    <ul id="side-menu" x-data="rtSidebarNavigation">
        @php
            $workforceHeadingShown = false;
        @endphp
        @foreach($rtSidebarSections ?? \App\Support\Operations\ApplicationNavigation::sidebarSections(auth()->user()) as $section => $links)
            @if(count($links))
                @php
                    $collapsible = in_array($section, ['Disposition', 'Personal'], true);
                    $depth = $collapsible ? 1 : 0;
                @endphp
                @if($collapsible && ! $workforceHeadingShown)
                    <x-menu.sidebar-nav label="Betrieb & Personal" />
                    @php
                        $workforceHeadingShown = true;
                    @endphp
                @endif
                <x-menu.sidebar-nav
                    :label="$section"
                    :collapsible="$collapsible"
                    :icon="$section === 'Personal' ? 'users' : 'layers'"
                    :active="collect($links)->contains(fn ($link) => \App\Support\Operations\ApplicationNavigation::active($link))"
                >
                        @foreach(\App\Support\Operations\ApplicationNavigation::groups($links) as $group)
                            @php($label = $group['label'])
                            @if($label !== '')
                            <x-menu.sidebar-nav-group :icon="$group['icon']" :depth="$depth" :active="collect($group['links'])->contains(fn ($link) => \App\Support\Operations\ApplicationNavigation::active($link))">
                                <x-slot:label>{{ $label }}</x-slot:label>
                                @foreach($group['links'] as $link)
                                    <x-menu.sidebar-nav-link
                                        :href="route($link['route'], $link['parameters'])"
                                        :icon="$link['icon']"
                                        :navigate="$link['navigate']"
                                        :active="\App\Support\Operations\ApplicationNavigation::active($link)"
                                        :depth="$depth + 1"
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
                            :depth="$depth"
                        >{{ $link['title'] }}</x-menu.sidebar-nav-link>
                    @endforeach
                            @endif
                        @endforeach
                </x-menu.sidebar-nav>
            @endif
        @endforeach
    </ul>
</div>
