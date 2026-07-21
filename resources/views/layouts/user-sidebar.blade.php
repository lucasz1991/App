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
            <x-menu.sidebar-nav-group
                icon="message-square"
                :active="request()->routeIs('chat', 'messages')"
            >
                <x-slot:label>{{ __('app.communication') }}</x-slot:label>

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

            <x-menu.sidebar-nav-group
                icon="archive"
                :active="request()->routeIs('files', 'email-templates.*')"
            >
                <x-slot:label>{{ __('app.downloads_and_templates') }}</x-slot:label>

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
