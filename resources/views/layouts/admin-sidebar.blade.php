<div class="metismenu pb-10 pt-2.5" id="sidebar-menu">
    <ul id="side-menu">
        <x-menu.sidebar-nav :label="__('app.overview')">
            <x-menu.sidebar-nav-link
                :href="route('admin.dashboard')"
                icon="home"
                :active="request()->routeIs('admin.dashboard', 'admin.index')"
            >
                {{ __('app.dashboard') }}
            </x-menu.sidebar-nav-link>
        </x-menu.sidebar-nav>

        @can('settings.manage')
            <x-menu.sidebar-nav :label="__('app.company')">
                <x-menu.sidebar-nav-link
                    :href="route('admin.settings')"
                    icon="settings"
                    :active="request()->routeIs('admin.settings')"
                >
                    {{ __('app.settings') }}
                </x-menu.sidebar-nav-link>
            </x-menu.sidebar-nav>
        @endcan

        @can('employees.view')
            <x-menu.sidebar-nav :label="__('app.management')">
                <x-menu.sidebar-nav-link
                    :href="route('admin.employees')"
                    icon="users"
                    :active="request()->routeIs('admin.employees', 'employees.show')"
                >
                    {{ __('app.employees') }}
                </x-menu.sidebar-nav-link>
            </x-menu.sidebar-nav>
        @endcan

        @if (auth()->user()?->role === 'admin')
            <x-menu.sidebar-nav :label="__('app.operations')">
                <x-menu.sidebar-nav-group
                    icon="layers"
                    :active="request()->routeIs('admin.operations.preview', 'admin.operations.wagon-list')"
                >
                    <x-slot:label>{{ __('app.operational_control') }}</x-slot:label>

                    <x-menu.sidebar-nav-link
                        :href="route('admin.operations.wagon-list')"
                        icon="edit-3"
                        :active="request()->routeIs('admin.operations.wagon-list')"
                        class="!pl-12"
                    >
                        {{ __('app.wagon_list') }}
                    </x-menu.sidebar-nav-link>

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
            </x-menu.sidebar-nav>
        @endif

        <x-menu.sidebar-nav :label="__('app.content_and_files')">
            <x-menu.sidebar-nav-group
                icon="folder"
                :active="request()->routeIs('admin.files', 'admin.managed-documents', 'admin.mail-management', 'email-templates.*')"
            >
                <x-slot:label>{{ __('app.files_and_templates') }}</x-slot:label>

                @can('files.manage')
                    <x-menu.sidebar-nav-link
                        :href="route('admin.files')"
                        icon="folder"
                        :active="request()->routeIs('admin.files')"
                        class="!pl-12"
                    >
                        {{ __('app.download_files') }}
                    </x-menu.sidebar-nav-link>

                    <x-menu.sidebar-nav-link
                        :href="route('admin.managed-documents')"
                        icon="tool"
                        :active="request()->routeIs('admin.managed-documents')"
                        class="!pl-12"
                    >
                        {{ __('app.managed_documents') }}
                    </x-menu.sidebar-nav-link>
                @endcan

                <x-menu.sidebar-nav-link
                    :href="route('email-templates.index')"
                    icon="file-text"
                    :active="request()->routeIs('email-templates.*')"
                    class="!pl-12"
                >
                    {{ __('app.email_templates') }}
                </x-menu.sidebar-nav-link>

                @can('manage.messages')
                    <x-menu.sidebar-nav-link
                        :href="route('admin.mail-management')"
                        icon="send"
                        :active="request()->routeIs('admin.mail-management')"
                        class="!pl-12"
                    >
                        {{ __('app.mail_management') }}
                    </x-menu.sidebar-nav-link>
                @endcan
            </x-menu.sidebar-nav-group>
        </x-menu.sidebar-nav>

        <x-menu.sidebar-nav :label="__('app.communication')">
            <x-menu.sidebar-nav-group
                icon="message-square"
                :active="request()->routeIs('chat', 'admin.messages')"
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
                    :href="route('admin.messages')"
                    icon="mail"
                    :active="request()->routeIs('admin.messages')"
                    class="!pl-12"
                >
                    {{ __('app.messages') }}
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
