@props([
    'teams',
    'selectedTeamId',
    'permissionGroups',
    'embedded' => false,
])

@php
    $selectedTeam = $teams->firstWhere('id', $selectedTeamId);
    $permissionCount = collect($permissionGroups)->sum(
        static fn (array $permissions): int => count($permissions)
    );
@endphp

<div
    class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
    data-team-rbac-manager
>
    @unless($embedded)
        <div class="border-b border-rt-border/60 px-4 py-3 dark:border-rt-dark-border/60 sm:px-5">
            <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.teams_permissions_hint') }}
            </p>
        </div>
    @endunless

    <div class="grid min-w-0 lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
        <aside
            class="min-w-0 border-b border-rt-border/60 bg-rt-surface-muted/55 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted/45 lg:border-b-0 lg:border-r rtl:lg:border-l rtl:lg:border-r-0"
            aria-label="{{ __('app.team') }}"
            data-team-rbac-team-list
        >
            <div class="flex items-center justify-between gap-3 px-4 pb-2 pt-4 sm:px-5">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="far fa-users" aria-hidden="true"></i>
                    </span>
                    <p class="truncate text-xs font-semibold uppercase tracking-[0.14em] text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.team') }}
                    </p>
                </div>
                <span
                    class="inline-flex min-w-7 items-center justify-center rounded-full bg-rt-surface px-2 py-1 text-xs font-semibold tabular-nums text-rt-text ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/70"
                    aria-label="{{ $teams->count() }} {{ __('app.team') }}"
                >
                    {{ $teams->count() }}
                </span>
            </div>

            <div
                class="scroll-container grid max-h-60 gap-1.5 overflow-y-auto p-2 sm:grid-cols-2 sm:p-3 lg:max-h-[36rem] lg:grid-cols-1"
                role="group"
                aria-label="{{ __('app.team') }}"
            >
                @forelse($teams as $team)
                    @php($isSelectedTeam = (int) $selectedTeamId === (int) $team->id)
                    <button
                        type="button"
                        wire:key="team-rbac-team-{{ $team->id }}"
                        wire:click="setTeam({{ $team->id }})"
                        aria-pressed="{{ $isSelectedTeam ? 'true' : 'false' }}"
                        data-team-rbac-team="{{ $team->id }}"
                        class="group flex min-h-12 w-full min-w-0 items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-all duration-200 ease-rt-spring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/35 active:scale-[0.99] {{ $isSelectedTeam ? 'border-rt-red/25 bg-rt-accent-soft/75 text-rt-text shadow-rt-xs dark:border-rt-dark-accent/30 dark:bg-rt-dark-accent-soft/70 dark:text-rt-dark-text' : 'border-transparent bg-transparent text-rt-text hover:border-rt-border/70 hover:bg-rt-surface dark:text-rt-dark-text dark:hover:border-rt-dark-border/70 dark:hover:bg-rt-dark-surface' }}"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $isSelectedTeam ? 'bg-rt-surface text-rt-red shadow-rt-xs dark:bg-rt-dark-surface dark:text-rt-dark-accent' : 'bg-rt-surface text-rt-muted ring-1 ring-rt-border/60 group-hover:text-rt-accent dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60 dark:group-hover:text-rt-dark-accent' }}">
                            <i class="far fa-users" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold">
                            {{ $team->name }}
                        </span>
                        @if($isSelectedTeam)
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rt-red text-white" aria-hidden="true">
                                <i class="far fa-check text-[0.65rem]"></i>
                            </span>
                        @else
                            <i class="far fa-chevron-right shrink-0 text-[0.65rem] text-rt-soft transition-transform group-hover:translate-x-0.5 group-hover:text-rt-accent dark:text-rt-dark-soft dark:group-hover:text-rt-dark-accent rtl:rotate-180" aria-hidden="true"></i>
                        @endif
                    </button>
                @empty
                    <div class="flex min-h-32 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-rt-border/80 px-4 py-6 text-center dark:border-rt-dark-border/80 sm:col-span-2 lg:col-span-1">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-rt-surface text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                            <i class="far fa-users-slash" aria-hidden="true"></i>
                        </span>
                        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_teams') }}</p>
                    </div>
                @endforelse
            </div>
        </aside>

        <section class="min-w-0" aria-label="{{ __('app.permissions') }}" data-team-rbac-permissions>
            @if($selectedTeamId && $selectedTeam)
                <header class="border-b border-rt-border/60 px-4 py-4 dark:border-rt-dark-border/60 sm:px-5 sm:py-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="fad fa-user-shield" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-muted dark:text-rt-dark-muted">
                                    {{ __('app.team') }}
                                </p>
                                <div class="mt-0.5 flex min-w-0 flex-wrap items-center gap-2">
                                    <h3 class="truncate text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                                        {{ $selectedTeam->name }}
                                    </h3>
                                    <span class="rounded-full bg-rt-surface-muted px-2 py-1 text-xs font-medium text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                                        {{ $permissionCount }} {{ __('app.permissions') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:flex xl:shrink-0" aria-label="{{ __('app.permissions') }}">
                            <x-ui.buttons.button-basic
                                wire:click="setSelectedTeamToTrue"
                                size="sm"
                                :title="__('app.activate_all_hint')"
                                class="min-h-11 w-full xl:w-auto"
                            >
                                <i class="far fa-check-double text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                                {{ __('app.activate_all') }}
                            </x-ui.buttons.button-basic>
                            <x-ui.buttons.button-basic
                                wire:click="setSelectedTeamToFalse"
                                mode="link"
                                size="sm"
                                :title="__('app.deactivate_all_hint')"
                                class="min-h-11 w-full xl:w-auto"
                            >
                                <i class="far fa-times-circle" aria-hidden="true"></i>
                                {{ __('app.deactivate_all') }}
                            </x-ui.buttons.button-basic>
                        </div>
                    </div>
                </header>

                <div class="p-3 sm:p-5">
                    <div class="mb-3 px-1">
                        <h4 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                            {{ __('app.permissions') }}
                        </h4>
                    </div>

                    <div class="columns-1 gap-3 xl:columns-2" data-team-rbac-permission-groups>
                        @foreach($permissionGroups as $groupLabel => $permissions)
                            <section
                                class="mb-3 break-inside-avoid overflow-hidden rounded-xl bg-rt-surface-muted/55 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60"
                                data-team-rbac-group
                            >
                                <div class="flex min-h-11 items-center justify-between gap-3 border-b border-rt-border/50 px-3.5 py-2.5 dark:border-rt-dark-border/50">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <span class="h-2 w-2 shrink-0 rounded-full bg-rt-red" aria-hidden="true"></span>
                                        <h5 class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                            {{ $groupLabel }}
                                        </h5>
                                    </div>
                                    <span
                                        class="inline-flex min-w-6 items-center justify-center rounded-full bg-rt-surface px-1.5 py-0.5 text-[0.7rem] font-semibold tabular-nums text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60"
                                        aria-label="{{ count($permissions) }} {{ __('app.permissions') }}"
                                    >
                                        {{ count($permissions) }}
                                    </span>
                                </div>

                                <div class="divide-y divide-rt-border/40 px-1.5 py-1 dark:divide-rt-dark-border/40">
                                    @foreach($permissions as $permissionItem)
                                        @php
                                            $permission = $permissionItem['key'];
                                            $permissionLabel = $permissionItem['label'] ?? $permission;
                                            $permissionKey = str_replace('.', '__dot__', $permission);
                                        @endphp
                                        <div wire:key="team-rbac-permission-{{ $selectedTeamId }}-{{ $permissionKey }}">
                                            <x-ui.forms.toggle-button
                                                :id="'perm-'.$selectedTeamId.'-'.str_replace('.', '-', $permission)"
                                                :model="'matrix.'.$selectedTeamId.'.'.$permissionKey"
                                                :label="$permissionLabel"
                                                class="w-full flex-row-reverse justify-between rounded-lg px-2.5 py-1.5 text-left transition-colors hover:bg-rt-surface dark:hover:bg-rt-dark-surface"
                                            />
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="flex min-h-72 flex-col items-center justify-center px-5 py-10 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-surface-muted text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                        <i class="fad fa-user-shield fa-lg" aria-hidden="true"></i>
                    </span>
                    <p class="mt-4 max-w-sm text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.select_team_first') }}
                    </p>
                </div>
            @endif
        </section>
    </div>

    @if($embedded)
        <div class="flex flex-col gap-3 border-t border-rt-border/60 bg-rt-surface-muted/35 px-4 py-3 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted/30 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="flex min-w-0 items-center gap-2 text-sm text-rt-muted dark:text-rt-dark-muted">
                <i class="far fa-shield-check shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                <span class="truncate">
                    {{ __('app.team') }}: {{ $selectedTeam?->name ?? __('app.team_not_found') }}
                </span>
            </div>
            <x-ui.buttons.button-basic
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                mode="primary"
                class="min-h-11 w-full sm:w-auto"
            >
                <i class="far fa-save" wire:loading.remove wire:target="save" aria-hidden="true"></i>
                <i class="far fa-spinner fa-spin" wire:loading wire:target="save" aria-hidden="true"></i>
                {{ __('app.save') }}
            </x-ui.buttons.button-basic>
        </div>
    @endif
</div>
