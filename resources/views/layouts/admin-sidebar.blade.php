<div class="metismenu pb-10 pt-2.5" id="sidebar-menu">
    <ul id="side-menu">
        <x-menu.sidebar-nav>
            <x-menu.sidebar-nav-link
                :href="route('admin.dashboard')"
                icon="home"
                :active="request()->routeIs('admin.dashboard', 'admin.index')"
            >
                {{ __('app.dashboard') }}
            </x-menu.sidebar-nav-link>
        </x-menu.sidebar-nav>

        <x-menu.sidebar-nav :label="__('app.system_administration')">
            @can('employees.view')
                <x-menu.sidebar-nav-link
                    :href="route('admin.employees')"
                    icon="users"
                    :active="request()->routeIs('admin.employees')"
                >
                    {{ __('app.employees') }}
                </x-menu.sidebar-nav-link>
            @endcan

            @can('files.manage')
                <x-menu.sidebar-nav-link
                    :href="route('admin.files')"
                    icon="folder"
                    :active="request()->routeIs('admin.files')"
                >
                    {{ __('app.file_management') }}
                </x-menu.sidebar-nav-link>
            @endcan

            @can('manage.messages')
                <x-menu.sidebar-nav-link
                    :href="route('admin.mail-management')"
                    icon="send"
                    :active="request()->routeIs('admin.mail-management')"
                >
                    {{ __('app.mail_management') }}
                </x-menu.sidebar-nav-link>
            @endcan

            <x-menu.sidebar-nav-link
                :href="route('admin.messages')"
                icon="mail"
                :active="request()->routeIs('admin.messages')"
            >
                {{ __('app.messages') }}
            </x-menu.sidebar-nav-link>

        </x-menu.sidebar-nav>
    </ul>
</div>
