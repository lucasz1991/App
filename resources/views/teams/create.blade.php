<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center justify-between gap-3">
            <h2 class="min-w-0 truncate text-xl font-semibold leading-tight tracking-tight text-rt-text dark:text-rt-dark-text">
                {{ __('Create Team') }}
            </h2>
            <x-ui.page-info-button :title="__('Create Team')" route-name="teams.create" />
        </div>
    </x-slot>

    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            @livewire('teams.create-team-form')
        </div>
    </div>
</x-app-layout>
