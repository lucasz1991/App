@extends('layouts.master')

@section('title', __('app.profile'))

@section('content')
    @php
        $profileTabs = \App\Livewire\Profile\ProfileTabContent::tabDefinitions();
        $requestedProfileTab = \App\Livewire\Profile\ProfileTabContent::resolveTab(request()->query('tab'));
    @endphp

    <x-ui.page
        class="employee-workspace employee-own-profile"
    >
        <div class="space-y-4 sm:space-y-5">
            <livewire:profile.profile-identity-card />

            <x-ui.accordion.tabs
                :tabs="$profileTabs"
                :collapse-at="'md'"
                :default="$requestedProfileTab"
                :force-default="request()->filled('tab')"
                persist-key="own-profile.tabs"
                :persist-aliases="\App\Livewire\Profile\ProfileTabContent::ALIASES"
            >
            @foreach (array_keys($profileTabs) as $profileTab)
                <x-ui.accordion.tab-panel :for="$profileTab" content-class="space-y-8">
                    <livewire:profile.profile-tab-content :tab="$profileTab" :key="'own-profile-tab-'.$profileTab" />
                </x-ui.accordion.tab-panel>
            @endforeach

            </x-ui.accordion.tabs>
        </div>
    </x-ui.page>
@endsection
