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
                <div class="space-y-8">
                    @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                        <livewire:profile.update-password-form />
                    @endif

                    @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                        <livewire:profile.two-factor-authentication-form />
                    @endif
                </div>
                @break

            @case('app')
                <div class="space-y-8">
                    <x-ui.display-settings />
                    <livewire:settings.sound-preferences />
                    <livewire:settings.push-settings />
                </div>
                @break

            @case('sessions')
                <livewire:profile.logout-other-browser-sessions-form />
                @break
        @endswitch
    @else
        <x-ui.lazy-placeholder />
    @endif
</div>
