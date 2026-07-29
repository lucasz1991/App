@props([
    'teams',
    'selectedTeamId',
    'permissionGroups',
    'embedded' => false,
])

<div class="space-y-4" data-team-rbac-manager>
    <p class="text-sm text-rt-muted dark:text-rt-dark-muted">
        {{ __('app.teams_permissions_hint') }}
    </p>

    <div class="grid gap-4 md:grid-cols-[220px_minmax(0,1fr)]">
        <div class="max-h-[440px] overflow-y-auto rounded-xl bg-rt-surface-muted p-2 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
            @forelse($teams as $team)
                <button
                    type="button"
                    wire:click="setTeam({{ $team->id }})"
                    class="mb-1 w-full rounded-lg px-3 py-2 text-left text-sm transition-all duration-200 ease-rt-spring active:scale-[0.98] {{ (int) $selectedTeamId === (int) $team->id ? 'bg-rt-red font-semibold text-white shadow-rt-xs' : 'bg-rt-surface text-rt-text shadow-rt-xs ring-1 ring-rt-border/60 hover:bg-rt-nav-hover hover:text-rt-red dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-red' }}"
                >
                    {{ $team->name }}
                </button>
            @empty
                <div class="p-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_teams') }}</div>
            @endforelse
        </div>

        <div class="min-w-0 rounded-xl bg-rt-surface p-3 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            @if($selectedTeamId)
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="text-sm font-medium text-rt-text dark:text-rt-dark-text">
                        {{ $teams->firstWhere('id', $selectedTeamId)?->name ?? __('app.team_not_found') }}
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.buttons.button-basic wire:click="setSelectedTeamToTrue" :size="'sm'" :title="__('app.activate_all_hint')">
                            <i class="far fa-check-circle mr-2"></i>
                            {{ __('app.activate_all') }}
                        </x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic wire:click="setSelectedTeamToFalse" :size="'sm'" :title="__('app.deactivate_all_hint')">
                            <i class="far fa-remove mr-2"></i>
                            {{ __('app.deactivate_all') }}
                        </x-ui.buttons.button-basic>
                    </div>
                </div>

                <div class="mb-2 font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.permissions') }}</div>
                <div class="scroll-container mb-3 max-h-[440px] overflow-y-auto rounded-lg p-3 ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                    <div class="grid grid-cols-1 gap-3">
                        @foreach($permissionGroups as $groupLabel => $permissions)
                            <div class="rounded-lg bg-rt-surface-muted p-2 ring-1 ring-rt-border/50 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/50">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ $groupLabel }}</div>
                                <div class="space-y-1">
                                    @foreach($permissions as $permissionItem)
                                        @php
                                            $permission = $permissionItem['key'];
                                            $permissionLabel = $permissionItem['label'] ?? $permission;
                                            $permissionKey = str_replace('.', '__dot__', $permission);
                                        @endphp
                                        <x-ui.forms.toggle-button
                                            :id="'perm-'.$selectedTeamId.'-'.str_replace('.', '-', $permission)"
                                            :model="'matrix.'.$selectedTeamId.'.'.$permissionKey"
                                            :label="$permissionLabel"
                                        />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.select_team_first') }}</div>
            @endif
        </div>
    </div>

    @if($embedded)
        <div class="flex justify-end border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60">
            <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled">
                <i class="fal fa-save" wire:loading.remove wire:target="save"></i>
                <i class="fal fa-spinner fa-spin text-rt-red" wire:loading wire:target="save"></i>
                {{ __('app.save') }}
            </x-ui.buttons.button-basic>
        </div>
    @endif
</div>
