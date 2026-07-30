<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center justify-between gap-3">
            <h2 class="min-w-0 truncate text-xl font-semibold leading-tight tracking-tight text-rt-text dark:text-rt-dark-text">
                {{ __('Team Settings') }}
            </h2>
            <x-ui.page-info-button :title="__('Team Settings')" route-name="teams.show" />
        </div>
    </x-slot>

    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            @livewire('teams.update-team-name-form', ['team' => $team])

            @livewire('teams.team-member-manager', ['team' => $team])

            @if (Gate::check('delete', $team) && ! $team->personal_team)
                <x-section-border />

                <div class="mt-10 sm:mt-0">
                    @livewire('teams.delete-team-form', ['team' => $team])
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
