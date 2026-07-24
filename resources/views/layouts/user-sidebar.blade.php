<div class="metismenu pb-10 pt-2.5" id="sidebar-menu">
    <ul id="side-menu">
        <x-menu.sidebar-nav :label="__('app.overview')">
            <x-menu.sidebar-nav-link
                :href="route('dashboard')"
                icon="home"
                :active="request()->routeIs('dashboard')"
            >
                {{ __('app.dashboard') }}
            </x-menu.sidebar-nav-link>
        </x-menu.sidebar-nav>

        @can('employees.view')
            <x-menu.sidebar-nav :label="__('app.company')">
                <x-menu.sidebar-nav-group
                    icon="briefcase"
                    :active="request()->routeIs('employees.*')"
                >
                    <x-slot:label>{{ __('app.organization') }}</x-slot:label>
                    <x-menu.sidebar-nav-link
                        :href="route('employees.index')"
                        icon="users"
                        :active="request()->routeIs('employees.*')"
                        class="!pl-12"
                    >
                        {{ __('app.employees') }}
                    </x-menu.sidebar-nav-link>
                </x-menu.sidebar-nav-group>
            </x-menu.sidebar-nav>
        @endcan

        @if (in_array(auth()->user()?->dashboardAudience(), ['employee', 'management', 'administration'], true))
            <x-menu.sidebar-nav :label="__('app.operations')">
                <x-menu.sidebar-nav-group
                    icon="layers"
                    :active="request()->routeIs('operations.wagon-list')"
                >
                    <x-slot:label>{{ __('app.operational_control') }}</x-slot:label>

                    <x-menu.sidebar-nav-link
                        :href="route('operations.wagon-list')"
                        icon="edit-3"
                        :active="request()->routeIs('operations.wagon-list')"
                        class="!pl-12"
                    >
                        {{ __('app.wagon_list') }}
                    </x-menu.sidebar-nav-link>
                </x-menu.sidebar-nav-group>
            </x-menu.sidebar-nav>
        @endif

        <x-menu.sidebar-nav :label="__('app.communication')">
            <x-menu.sidebar-nav-group
                icon="message-square"
                :active="request()->routeIs('chat', 'messages')"
            >
                <x-slot:label>{{ __('app.chat_and_messages') }}</x-slot:label>

                <x-menu.sidebar-nav-link
                    :href="route('chat')"
                    icon="message-circle"
                    :active="request()->routeIs('chat')"
                    class="!pl-12"
                >
                    {{ __('app.chat') }}
                </x-menu.sidebar-nav-link>

                <x-menu.sidebar-nav-link
                    :href="route('messages')"
                    icon="mail"
                    :active="request()->routeIs('messages')"
                    class="!pl-12"
                >
                    {{ __('app.messages') }}
                </x-menu.sidebar-nav-link>
            </x-menu.sidebar-nav-group>
        </x-menu.sidebar-nav>

        <x-menu.sidebar-nav :label="__('app.content_and_files')">
            <x-menu.sidebar-nav-group
                icon="archive"
                :active="request()->routeIs('files', 'email-templates.*')"
            >
                <x-slot:label>{{ __('app.files_and_templates') }}</x-slot:label>

                <x-menu.sidebar-nav-link
                    :href="route('files')"
                    icon="download-cloud"
                    :active="request()->routeIs('files')"
                    class="!pl-12"
                >
                    {{ __('app.download_center') }}
                </x-menu.sidebar-nav-link>

                <x-menu.sidebar-nav-link
                    :href="route('email-templates.index')"
                    icon="file-text"
                    :active="request()->routeIs('email-templates.*')"
                    class="!pl-12"
                >
                    {{ __('app.email_templates') }}
                </x-menu.sidebar-nav-link>
            </x-menu.sidebar-nav-group>
        </x-menu.sidebar-nav>

        <x-menu.sidebar-nav :label="__('app.my_area')">
            <x-menu.sidebar-nav-group
                icon="user"
                :active="request()->routeIs('profile.show', 'support')"
            >
                <x-slot:label>{{ __('app.profile_and_support') }}</x-slot:label>

                <x-menu.sidebar-nav-link
                    :href="route('profile.show')"
                    icon="user"
                    :active="request()->routeIs('profile.show')"
                    :navigate="false"
                    class="!pl-12"
                >
                    {{ __('app.profile') }}
                </x-menu.sidebar-nav-link>

                <x-menu.sidebar-nav-link
                    :href="route('support')"
                    icon="life-buoy"
                    :active="request()->routeIs('support')"
                    class="!pl-12"
                >
                    {{ __('app.it_support') }}
                </x-menu.sidebar-nav-link>
            </x-menu.sidebar-nav-group>
        </x-menu.sidebar-nav>
    </ul>
</div>
