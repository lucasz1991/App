@extends('layouts.master')

@section('title', __('app.profile'))

@section('content')
    @php
        $hasSecuritySettings = Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords())
            || Laravel\Fortify\Features::canManageTwoFactorAuthentication();

        $profileTabs = [
            'personal' => ['label' => __('app.personal_data'), 'icon' => 'fad fa-user-edit'],
        ];

        if ($hasSecuritySettings) {
            $profileTabs['security'] = ['label' => __('app.security'), 'icon' => 'fad fa-shield-alt'];
        }

        $profileTabs['app'] = ['label' => __('app.settings'), 'icon' => 'fad fa-cog'];

        $profileTabs['sessions'] = ['label' => __('app.sessions'), 'icon' => 'fad fa-laptop'];
    @endphp

    <x-ui.page
        :title="__('app.profile')"
    >
        <div class="space-y-4 sm:space-y-5">
            <livewire:profile.profile-identity-card />

            <x-ui.accordion.tabs
                :tabs="$profileTabs"
                :collapse-at="'md'"
                :default="request('tab', 'personal')"
                :force-default="request()->filled('tab')"
                persist-key="own-profile.tabs"
            >
            <x-ui.accordion.tab-panel for="personal" panel-class="space-y-8">
                @livewire('profile.update-contact-information-form')
            </x-ui.accordion.tab-panel>

            @if ($hasSecuritySettings)
                <x-ui.accordion.tab-panel for="security" panel-class="space-y-8">
                    @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                        @livewire('profile.update-password-form')
                    @endif

                    @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                        @livewire('profile.two-factor-authentication-form')
                    @endif
                </x-ui.accordion.tab-panel>
            @endif

            <x-ui.accordion.tab-panel for="app" panel-class="space-y-8">
                {{-- Sprache, Darstellung, Toene an/aus --}}
                <x-ui.display-settings />

                {{-- Persoenliche Ton-Zuordnung je Ereignis --}}
                <livewire:settings.sound-preferences />

                <livewire:settings.push-settings />
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel for="sessions" panel-class="space-y-8">
                @livewire('profile.logout-other-browser-sessions-form')
            </x-ui.accordion.tab-panel>

            </x-ui.accordion.tabs>
        </div>
    </x-ui.page>
@endsection
