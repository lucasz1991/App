@extends('layouts.master')

@section('title', __('app.profile'))

@section('content')
    @php
        $profileTabs = [
            'personal' => ['label' => __('app.personal_data'), 'icon' => 'fad fa-user-edit'],
            'security' => ['label' => __('app.security'), 'icon' => 'fad fa-shield-alt'],
            'app' => ['label' => __('app.settings'), 'icon' => 'fad fa-cog'],
        ];
        $requestedProfileTab = request('tab', 'personal') === 'sessions'
            ? 'security'
            : request('tab', 'personal');
    @endphp

    <x-ui.page
        :title="__('app.profile')"
    >
        <div class="space-y-4 sm:space-y-5">
            <livewire:profile.profile-identity-card />

            <x-ui.accordion.tabs
                :tabs="$profileTabs"
                :collapse-at="'md'"
                :default="$requestedProfileTab"
                :force-default="request()->filled('tab')"
                persist-key="own-profile.tabs"
                :persist-aliases="['sessions' => 'security']"
            >
            <x-ui.accordion.tab-panel for="personal" content-class="space-y-8">
                <livewire:profile.profile-tab-content tab="personal" />
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel for="security" content-class="space-y-8">
                <livewire:profile.profile-tab-content tab="security" />
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel for="app" content-class="space-y-8">
                <livewire:profile.profile-tab-content tab="app" />
            </x-ui.accordion.tab-panel>

            </x-ui.accordion.tabs>
        </div>
    </x-ui.page>
@endsection
