<x-dialog-modal wire:model="showModal" maxWidth="4xl">
    <x-slot name="title">
        {{ __('app.teams_permissions') }}
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">
            <div class="text-sm text-gray-600 dark:text-slate-400">
                {{ __('app.teams_permissions_hint') }}
            </div>

            <div class="grid md:grid-cols-[220px_1fr] gap-4">
                <div class="border border-gray-200 rounded-lg p-2 max-h-[440px] overflow-y-auto bg-gray-50 dark:bg-slate-800/50 dark:border-slate-700">
                    @forelse($teams as $team)
                        <button
                            type="button"
                            wire:click="setTeam({{ $team->id }})"
                            class="w-full text-left px-3 py-2 rounded-md mb-1 text-sm transition {{ (int)$selectedTeamId === (int)$team->id ? 'bg-sky-600 text-white' : 'bg-white hover:bg-sky-50 text-gray-700 border border-gray-200 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 dark:border-slate-600' }}"
                        >
                            {{ $team->name }}
                        </button>
                    @empty
                        <div class="text-xs text-gray-500 p-2 dark:text-slate-400">{{ __('app.no_teams') }}</div>
                    @endforelse
                </div>

                <div class="border border-gray-200 rounded-lg p-3 bg-white dark:bg-slate-800 dark:border-slate-700">
                    @if($selectedTeamId)
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium text-gray-800 dark:text-slate-100">
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

                        <div class="font-semibold text-gray-800 mb-2 dark:text-slate-100">{{ __('app.permissions') }}</div>
                        <div class="border border-gray-100 rounded-lg p-3 mb-3  max-h-[440px] overflow-y-auto scroll-container dark:border-slate-700">
                            <div class="grid grid-cols-1 gap-3">
                                @foreach($permissionGroups as $groupLabel => $permissions)
                                    <div class="border border-gray-100 rounded-lg p-2 dark:border-slate-700">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 dark:text-slate-400">{{ $groupLabel }}</div>
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
                        <div class="text-sm text-gray-500 dark:text-slate-400">{{ __('app.select_team_first') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic wire:click="close" class="mr-2">
            <i class="far fa-times mr-2"></i>
            {{ __('app.close') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled">
            <i class="fal fa-save mr-2" wire:loading.remove wire:target="save"></i>
            <i class="fal fa-spinner fa-spin mr-2 text-blue-500" wire:loading wire:target="save"></i>
            {{ __('app.save') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
