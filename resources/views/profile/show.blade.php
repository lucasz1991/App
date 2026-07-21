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

    <x-ui.page
        :title="__('app.profile')"
        :description="__('app.profile_page_description')"
        :eyebrow="__('app.manage_account')"
    >
        {{-- Identitaets-Hero im Double-Bezel --}}
        <div
            class="rounded-2xl bg-rt-surface-muted p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
            data-anim="fade-up"
        >
            <div class="flex flex-col gap-4 rounded-[calc(1rem-2px)] bg-rt-surface p-5 dark:bg-rt-dark-surface sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <img
                        src="{{ $user->profile_photo_url }}"
                        alt="{{ $user->name }}"
                        class="h-16 w-16 shrink-0 rounded-2xl object-cover shadow-rt-sm ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60"
                    >
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                            {{ $user->name }}
                        </h2>
                        <p class="truncate text-sm text-rt-muted dark:text-rt-dark-muted">
                            {{ $user->email }}
                        </p>
                    </div>
                </div>

                @if ($user->email_verified_at)
                    <span class="inline-flex w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-rt-xs ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                        <i class="far fa-check-circle" aria-hidden="true"></i>
                        {{ __('app.email_verified') }}
                    </span>
                @else
                    <span class="inline-flex w-fit items-center gap-2 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 shadow-rt-xs ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                        <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                        {{ __('app.email_not_verified') }}
                    </span>
                @endif
            </div>
        </div>

        <x-ui.accordion.tabs
            :tabs="$profileTabs"
            :collapse-at="'md'"
            :default="request('tab', 'personal')"
            :force-default="request()->filled('tab')"
            persist-key="own-profile.tabs"
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
    </x-ui.page>
@endsection
