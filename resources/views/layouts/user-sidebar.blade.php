<div class="metismenu pb-10 pt-2.5" id="sidebar-menu">
    <ul id="side-menu">
        <x-menu.sidebar-nav>
            <x-menu.sidebar-nav-link
                :href="route('dashboard')"
                icon="home"
                :active="request()->routeIs('dashboard')"
            >
                {{ __('app.dashboard') }}
            </x-menu.sidebar-nav-link>
        </x-menu.sidebar-nav>

        <x-menu.sidebar-nav :label="__('app.personal_data')">
            <x-menu.sidebar-nav-link
                :href="route('files')"
                icon="folder"
                :active="request()->routeIs('files')"
            >
                {{ __('app.my_files') }}
            </x-menu.sidebar-nav-link>

            <x-menu.sidebar-nav-link
                :href="route('messages')"
                icon="mail"
                :active="request()->routeIs('messages')"
            >
                {{ __('app.messages') }}
            </x-menu.sidebar-nav-link>

            <x-menu.sidebar-nav-link
                :href="route('profile.show')"
                icon="user"
                :active="request()->routeIs('profile.show')"
                :navigate="false"
            >
                {{ __('app.profile') }}
            </x-menu.sidebar-nav-link>
        </x-menu.sidebar-nav>
    </ul>
</div>
