<x-dialog-modal wire:model="showModal" maxWidth="4xl">
    <x-slot name="title">
        {{ __('app.teams_permissions') }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">
            <div class="text-sm text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.teams_permissions_hint') }}
            </div>

            <div class="grid md:grid-cols-[220px_1fr] gap-4">
                <div class="rounded-xl bg-rt-surface-muted p-2 max-h-[440px] overflow-y-auto ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                    @forelse($teams as $team)
                        <button
                            type="button"
                            wire:click="setTeam({{ $team->id }})"
                            class="w-full text-left px-3 py-2 rounded-lg mb-1 text-sm transition-all duration-300 ease-rt-spring active:scale-[0.98] {{ (int)$selectedTeamId === (int)$team->id ? 'bg-rt-red text-white font-semibold shadow-rt-xs' : 'bg-rt-surface hover:bg-rt-nav-hover hover:text-rt-red text-rt-text shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:hover:bg-rt-dark-nav-hover dark:text-rt-dark-text dark:ring-rt-dark-border/60 dark:hover:text-rt-red' }}"
                        >
                            {{ $team->name }}
                        </button>
                    @empty
                        <div class="text-xs text-rt-muted p-2 dark:text-rt-dark-muted">{{ __('app.no_teams') }}</div>
                    @endforelse
                </div>

                <div class="rounded-xl bg-rt-surface p-3 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    @if($selectedTeamId)
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium text-rt-text dark:text-rt-dark-text">
                                {{ $teams->firstWhere('id', $selectedTeamId)?->name ?? __('app.team_not_found') }}
                            </div>
                            <div>
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

                        <div class="font-semibold text-rt-text mb-2 dark:text-rt-dark-text">{{ __('app.permissions') }}</div>
                        <div class="rounded-lg ring-1 ring-rt-border/60 p-3 mb-3 max-h-[440px] overflow-y-auto scroll-container dark:ring-rt-dark-border/60">
                            <div class="grid grid-cols-1 gap-3">
                                @foreach($permissionGroups as $groupLabel => $permissions)
                                    <div class="rounded-lg bg-rt-surface-muted p-2 ring-1 ring-rt-border/50 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/50">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-rt-muted mb-2 dark:text-rt-dark-muted">{{ $groupLabel }}</div>
                                        <div class="space-y-1">
                                            @foreach($permissions as $permissionItem)
                                                @php
                                                    $permission = $permissionItem['key'];
                                                    $permissionLabel = $permissionItem['label'] ?? $permission;
                                                    $permissionKey = $this->permissionKey($permission);
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
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic wire:click="close" class="mr-2">
            <i class="far fa-times"></i>
            {{ __('app.close') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled">
            <i class="fal fa-save" wire:loading.remove wire:target="save"></i>
            <i class="fal fa-spinner fa-spin text-rt-red" wire:loading wire:target="save"></i>
            {{ __('app.save') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
