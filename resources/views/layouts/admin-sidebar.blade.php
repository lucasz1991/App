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

        @canany(['settings.manage', 'employees.view', 'files.manage', 'manage.messages'])
        <x-menu.sidebar-nav :label="__('app.administration')">
            @can('settings.manage')
                <x-menu.sidebar-nav-link
                    :href="route('admin.settings')"
                    icon="settings"
                    :active="request()->routeIs('admin.settings')"
                >
                    {{ __('app.settings') }}
                </x-menu.sidebar-nav-link>
            @endcan

            @if (auth()->user()?->isAdmin())
                <x-menu.sidebar-nav-group
                    icon="layers"
                    :active="request()->routeIs('admin.operations.preview')"
                >
                    <x-slot:label>{{ __('app.operations_preview') }}</x-slot:label>

                    @foreach (app(\App\Support\Operations\OperationalPreviewCatalog::class)->dashboard() as $previewModule)
                        <x-menu.sidebar-nav-link
                            :href="route('admin.operations.preview', ['module' => $previewModule['slug']])"
                            :icon="$previewModule['icon']"
                            :active="request()->routeIs('admin.operations.preview') && request()->route('module') === $previewModule['slug']"
                            class="!pl-12"
                        >
                            {{ $previewModule['title'] }}
                        </x-menu.sidebar-nav-link>
                    @endforeach
                </x-menu.sidebar-nav-group>
            @endif

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
        </x-menu.sidebar-nav>
        @endcanany

        {{-- Kein Download-Center: Admins/Verwaltung stellen Dateien bereit,
             empfangen selbst aber keine — der Bereich bleibt den Mitarbeitern
             (Nutzer-Sidebar) vorbehalten. --}}
        <x-menu.sidebar-nav :label="__('app.personal_data')">
            <x-menu.sidebar-nav-group
                icon="message-square"
                :active="request()->routeIs('chat', 'admin.messages')"
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
                    :href="route('admin.messages')"
                    icon="mail"
                    :active="request()->routeIs('admin.messages')"
                    class="!pl-12"
                >
                    {{ __('app.messages') }}
                </x-menu.sidebar-nav-link>
            </x-menu.sidebar-nav-group>

            <x-menu.sidebar-nav-link
                :href="route('email-templates.index')"
                icon="file-text"
                :active="request()->routeIs('email-templates.*')"
            >
                {{ __('app.email_templates') }}
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
