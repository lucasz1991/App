@extends('layouts.master')

@section('title', __('app.profile'))

@section('content')
    @php
        $user = auth()->user();
        $hasSecuritySettings = Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords())
            || Laravel\Fortify\Features::canManageTwoFactorAuthentication();

        $profileTabs = [
            'personal' => ['label' => __('app.personal_data'), 'icon' => 'fad fa-user-edit'],
        ];

        if ($hasSecuritySettings) {
            $profileTabs['security'] = ['label' => __('app.security'), 'icon' => 'fad fa-shield-alt'];
        }

        $profileTabs['sessions'] = ['label' => __('app.sessions'), 'icon' => 'fad fa-laptop'];
    @endphp

    <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
        <div class="page-content min-h-screen">
            <div class="container-fluid px-[0.625rem] md:px-5">
                <div class="mx-auto max-w-7xl py-8 lg:py-10">
                    <x-ui.page-header
                        :title="__('app.profile')"
                        :description="__('app.profile_page_description')"
                        :eyebrow="__('app.manage_account')"
                    />

                    <div class="mt-6 flex flex-col gap-4 border-b border-rt-border pb-6 dark:border-rt-dark-border sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-4">
                            <img
                                src="{{ $user->profile_photo_url }}"
                                alt="{{ $user->name }}"
                                class="h-16 w-16 shrink-0 rounded-2xl object-cover shadow-sm ring-1 ring-rt-border dark:ring-rt-dark-border"
                            >
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-semibold text-rt-text dark:text-rt-dark-text">
                                    {{ $user->name }}
                                </h2>
                                <p class="truncate text-sm text-rt-muted dark:text-rt-dark-muted">
                                    {{ $user->email }}
                                </p>
                            </div>
                        </div>

                        @if ($user->email_verified_at)
                            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                                <i class="far fa-check-circle" aria-hidden="true"></i>
                                {{ __('app.email_verified') }}
                            </span>
                        @else
                            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                                <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                                {{ __('app.email_not_verified') }}
                            </span>
                        @endif
                    </div>

                    <x-ui.accordion.tabs
                        :tabs="$profileTabs"
                        :collapse-at="'md'"
                        default="personal"
                        persist-key="own-profile.tabs"
                        class="mt-6"
                    >
                        <x-ui.accordion.tab-panel for="personal" panel-class="space-y-8">
                            @if (Laravel\Fortify\Features::canUpdateProfileInformation())
                                @livewire('profile.update-profile-information-form')
                            @endif

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

                        <x-ui.accordion.tab-panel for="sessions" panel-class="space-y-8">
                            @livewire('profile.logout-other-browser-sessions-form')
                        </x-ui.accordion.tab-panel>
                    </x-ui.accordion.tabs>
                </div>
            </div>
        </div>
    </div>
@endsection
