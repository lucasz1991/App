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
                <livewire:profile.update-contact-information-form />
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
                            icon="fad fa-shield-check"
                            id-prefix="profile-security"
                        >
                            <livewire:profile.two-factor-authentication-form />
                        </x-ui.accordion.section>
                    @endif

                    <x-ui.accordion.section
                        section="sessions"
                        :label="__('app.sessions')"
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
                        icon="fad fa-palette"
                        id-prefix="profile-app"
                    >
                        <x-ui.display-settings :embedded="true" />
                    </x-ui.accordion.section>
                    <x-ui.accordion.section
                        section="sounds"
                        :label="__('app.sound_settings')"
                        icon="fad fa-volume"
                        id-prefix="profile-app"
                    >
                        <livewire:settings.sound-preferences />
                    </x-ui.accordion.section>
                    <x-ui.accordion.section
                        section="push"
                        label="Push und Standort"
                        icon="fad fa-bell"
                        id-prefix="profile-app"
                    >
                        <livewire:settings.session-permissions :automatic="false" />
                    </x-ui.accordion.section>
                </div>
                @break
        @endswitch
    @else
        <x-ui.lazy-placeholder />
    @endif
</div>
