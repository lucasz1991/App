<div
    x-data="{ requested: @js($ready) }"
    x-effect="
        if (!requested && openTab === @js($tab)) {
            requested = true;
            $wire.load();
        }
    "
    x-on:rt-tab:preload.window="
        if (!requested && $event.detail?.id === @js($tab)) {
            requested = true;
            $wire.load();
        }
    "
    data-profile-tab-loader="{{ $tab }}"
    data-ready="{{ $ready ? 'true' : 'false' }}"
>
    @if ($ready)
        @switch($tab)
            @case('personal')
                <div
                    x-data="{
                        openAccordionSection: 'contact',
                        toggleAccordionSection(section) {
                            this.openAccordionSection = this.openAccordionSection === section ? null : section;
                        },
                    }"
                    class="flex flex-col gap-3"
                    data-profile-accordion="personal"
                    wire:ignore.self
                >
                    <x-ui.accordion.section
                        section="contact"
                        :label="__('app.contact_information')"
                        :description="__('app.contact_information_description')"
                        icon="fad fa-address-card"
                        id-prefix="profile-personal"
                    >
                        <livewire:profile.update-contact-information-form />
                    </x-ui.accordion.section>
                </div>
                @break

            @case('security')
                @php
                    $firstSecuritySection = Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords())
                        ? 'password'
                        : (Laravel\Fortify\Features::canManageTwoFactorAuthentication() ? 'two-factor' : 'sessions');
                @endphp
                <div
                    x-data="{
                        openAccordionSection: @js($firstSecuritySection),
                        toggleAccordionSection(section) {
                            this.openAccordionSection = this.openAccordionSection === section ? null : section;
                        },
                    }"
                    class="flex flex-col gap-3"
                    data-profile-accordion="security"
                    wire:ignore.self
                >
                    @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                        <x-ui.accordion.section
                            section="password"
                            :label="__('app.password')"
                            :description="__('app.profile_password_description')"
                            icon="fad fa-key"
                            id-prefix="profile-security"
                        >
                            <livewire:profile.update-password-form />
                        </x-ui.accordion.section>
                    @endif

                    @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                        <x-ui.accordion.section
                            section="two-factor"
                            :label="__('app.two_factor_authentication')"
                            :description="__('app.profile_two_factor_description')"
                            icon="fad fa-shield-check"
                            id-prefix="profile-security"
                        >
                            <livewire:profile.two-factor-authentication-form />
                        </x-ui.accordion.section>
                    @endif

                    <x-ui.accordion.section
                        section="sessions"
                        :label="__('app.sessions')"
                        :description="__('app.profile_sessions_description')"
                        icon="fad fa-laptop"
                        id-prefix="profile-security"
                    >
                        <livewire:profile.logout-other-browser-sessions-form />
                    </x-ui.accordion.section>
                </div>
                @break

            @case('app')
                <div
                    x-data="{
                        openAccordionSection: 'display',
                        toggleAccordionSection(section) {
                            this.openAccordionSection = this.openAccordionSection === section ? null : section;
                        },
                    }"
                    class="flex flex-col gap-3"
                    data-profile-accordion="app"
                    wire:ignore.self
                >
                    <x-ui.accordion.section
                        section="display"
                        :label="__('app.display_settings')"
                        :description="__('app.appearance_hint')"
                        icon="fad fa-palette"
                        id-prefix="profile-app"
                    >
                        <x-ui.display-settings />
                    </x-ui.accordion.section>
                    <x-ui.accordion.section
                        section="sounds"
                        :label="__('app.sound_settings')"
                        :description="__('app.sound_preferences_hint')"
                        icon="fad fa-volume"
                        id-prefix="profile-app"
                    >
                        <livewire:settings.sound-preferences />
                    </x-ui.accordion.section>
                    <x-ui.accordion.section
                        section="push"
                        :label="__('app.push_settings_title')"
                        :description="__('app.push_settings_description')"
                        icon="fad fa-bell"
                        id-prefix="profile-app"
                    >
                        <livewire:settings.push-settings />
                    </x-ui.accordion.section>
                </div>
                @break
        @endswitch
    @else
        <x-ui.lazy-placeholder />
    @endif
</div>
