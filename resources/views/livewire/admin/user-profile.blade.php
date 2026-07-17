<div>
    <div class="mb-10 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
            <x-back-button :href="route('admin.employees')" />

            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <x-ui.buttons.button-basic :size="'sm'" :mode="'basic'">
                        <i class="far fa-ellipsis-v mr-2"></i>
                        {{ __('app.options') }}
                    </x-ui.buttons.button-basic>
                </x-slot>

                <x-slot name="content">
                    @if ($user->status)
                        <x-dropdown-link wire:click.prevent="deactivateUser()" :can="'users.edit'" class="hover:bg-yellow-100 dark:hover:bg-yellow-500/10">
                            <i class="far fa-times-circle mr-2"></i>
                            {{ __('app.deactivate') }}
                        </x-dropdown-link>
                    @else
                        <x-dropdown-link wire:click.prevent="activateUser()" :can="'users.edit'" class="hover:bg-green-100 dark:hover:bg-green-500/10">
                            <i class="far fa-check-circle mr-2"></i>
                            {{ __('app.activate') }}
                        </x-dropdown-link>
                    @endif

                    <x-dropdown-link
                        wire:click.prevent="deleteUser()"
                        wire:confirm="{{ __('app.delete_user_confirm') }}"
                        :can="'users.edit'"
                        class="text-red-700 hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-500/10"
                    >
                        <i class="far fa-trash-alt mr-2"></i>
                        {{ __('app.delete_user') }}
                    </x-dropdown-link>
                </x-slot>
            </x-dropdown>
        </div>

        <div class="relative">
            <div class="h-24 bg-gradient-to-r from-sky-100 via-blue-50 to-indigo-100 dark:from-sky-500/20 dark:via-slate-800 dark:to-indigo-500/20"></div>

            <div class="absolute left-3 top-3 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold text-white {{ $user->isActive() ? 'bg-emerald-500/90' : 'bg-rose-500/90' }}">
                <span class="h-1.5 w-1.5 rounded-full bg-white"></span>
                <span>{{ $user->isActive() ? ucfirst(__('app.active')) : ucfirst(__('app.inactive')) }}</span>
            </div>

            <div class="absolute right-3 top-3 rounded-full bg-black/30 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm">
                {{ __('app.registered') }}: {{ $user->created_at->format('d.m.Y') }}
            </div>

            <div class="absolute left-1/2 top-full -translate-x-1/2 -translate-y-1/2">
                <div class="h-28 w-28 overflow-hidden rounded-2xl border-4 border-white bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800 md:h-32 md:w-32">
                    <img
                        class="h-full w-full object-cover object-center"
                        src="{{ $user->profile_photo_url }}"
                        alt="{{ $user->name }}"
                    >
                </div>
            </div>
        </div>

        <div class="px-4 pb-5 pt-16 md:px-6 md:pt-20">
            <div class="text-center">
                <h2 class="text-xl font-semibold text-slate-700 dark:text-white">{{ $user->name }}</h2>
                <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</div>

                @php
                    $roleLabel = match ($user->role) {
                        'admin' => __('app.role_admin'),
                        'staff' => __('app.role_staff'),
                        default => __('app.role_user'),
                    };
                    $roleClasses = match ($user->role) {
                        'admin' => 'bg-purple-50 text-purple-700 ring-purple-200 dark:bg-purple-500/10 dark:text-purple-300 dark:ring-purple-500/30',
                        'staff' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
                        default => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
                    };
                @endphp
                <div class="mt-3 inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $roleClasses }}">
                    <i class="far fa-user-tag mr-2"></i>
                    {{ $roleLabel }}
                </div>

                @php
                    $lastActivityAt = $user->lastActivityAt();
                    $isUserOnline = $user->isOnline();
                @endphp
                <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                    @if ($isUserOnline)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                            {{ __('app.online') }}
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                        <i class="far fa-clock"></i>
                        {{ __('app.last_online') }}:
                        <span class="font-semibold text-slate-700 dark:text-slate-300">
                            {{ $lastActivityAt ? $lastActivityAt->format('d.m.Y H:i') : __('app.never') }}
                        </span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <x-ui.accordion.tabs
        :tabs="[
            'userDetails' => ['label' => __('app.details'), 'icon' => 'fad fa-id-card'],
            'userNotes' => ['label' => __('app.notes'), 'icon' => 'fad fa-sticky-note'],
            'userFiles' => ['label' => __('app.files'), 'icon' => 'fad fa-folder-open'],
            'userMessages' => ['label' => __('app.messages'), 'icon' => 'fad fa-envelope'],
        ]"
        :collapse-at="'md'"
        default="userDetails"
        persist-key="admin.user.{{ $user->id }}.tabs"
        class="mt-6"
    >
        {{-- TAB: Details --}}
        <x-ui.accordion.tab-panel for="userDetails" panelClass="space-y-4 bg-white p-4 rounded-b-lg rounded-se-lg border border-blue-300 z-10 dark:bg-slate-800 dark:border-slate-700">
            <div class="w-full">
                <section>
                    <div class="mb-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800 dark:text-white">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                    <i class="far fa-user text-sm"></i>
                                </span>
                                <span>{{ __('app.user_details') }}</span>
                            </h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                    {{ __('app.user_id') }}: {{ $user->id }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4">
                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <article class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm dark:border-slate-700 dark:from-slate-800 dark:to-slate-800">
                                <div class="text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.username') }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $user->name }}</div>

                                <div class="mt-3 text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.birth_date') }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">
                                    {{ $profile?->birth_date ? $profile->birth_date->format('d.m.Y') : '-' }}
                                </div>

                                @if ($profile?->personnel_nr)
                                    <div class="mt-2 inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700 dark:bg-slate-700 dark:text-slate-300">
                                        {{ __('app.personnel_nr') }}: {{ $profile->personnel_nr }}
                                    </div>
                                @endif
                            </article>

                            <article class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm dark:border-slate-700 dark:from-slate-800 dark:to-slate-800">
                                <div class="text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.registered_at') }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $user->created_at->format('d.m.Y') }}</div>

                                <div class="mt-3 text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.email') }}</div>
                                <div class="mt-1 flex items-center gap-2">
                                    <div class="break-all text-sm text-slate-700 dark:text-slate-300" title="{{ $user->email }}">{{ $user->email }}</div>
                                    @if ($user->email_verified_at)
                                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-[11px] text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                              title="{{ __('app.email_verified') }}: {{ $user->email_verified_at->format('d.m.Y H:i') }}">
                                            <i class="far fa-check-circle"></i>
                                        </span>
                                    @else
                                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-rose-200 bg-rose-50 text-[11px] text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300"
                                              title="{{ __('app.email_not_verified') }}">
                                            <i class="far fa-exclamation-circle"></i>
                                        </span>
                                    @endif
                                </div>
                            </article>

                            <article class="rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm dark:border-slate-700 dark:from-slate-800 dark:to-slate-800">
                                <div class="text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.address') }}</div>
                                @if ($profile && ($profile->street || $profile->postal_code || $profile->city || $profile->country))
                                    <div class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $profile->street ?: '-' }}</div>
                                    <div class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ trim(($profile->postal_code ?? '') . ' ' . ($profile->city ?? '')) ?: '-' }}</div>
                                    @if ($profile->country)
                                        <div class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ $profile->country }}</div>
                                    @endif
                                @else
                                    <div class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('app.no_address') }}</div>
                                @endif

                                <div class="mt-3 text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.phone') }}</div>
                                <div class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ $profile?->phone ?: '-' }}</div>

                                <div class="mt-3 text-[11px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('app.mobile') }}</div>
                                <div class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ $profile?->mobile ?: '-' }}</div>
                            </article>
                        </div>
                    </div>
                </section>
            </div>
        </x-ui.accordion.tab-panel>

        {{-- TAB: Bemerkungen --}}
        <x-ui.accordion.tab-panel for="userNotes" panelClass="space-y-4 bg-white p-4 rounded-b-lg rounded-se-lg border border-blue-300 z-10 dark:bg-slate-800 dark:border-slate-700">
            <livewire:admin.user-profile.user-notes :user-id="$user->id" :key="'user-notes-'.$user->id" />
        </x-ui.accordion.tab-panel>

        {{-- TAB: Dateien --}}
        <x-ui.accordion.tab-panel for="userFiles" panelClass="space-y-4 bg-white p-4 rounded-b-lg rounded-se-lg border border-blue-300 z-10 dark:bg-slate-800 dark:border-slate-700">
            <livewire:tools.file-pools.manage-file-pools
                :model-type="\App\Models\User::class"
                :model-id="$user->id"
                :read-only="false"
                :key="'user-files-'.$user->id"
            />
        </x-ui.accordion.tab-panel>

        {{-- TAB: Nachrichten --}}
        <x-ui.accordion.tab-panel for="userMessages" panelClass="space-y-4 bg-white p-4 rounded-b-lg rounded-se-lg border border-blue-300 z-10 dark:bg-slate-800 dark:border-slate-700">
            @if (class_exists(\App\Livewire\Admin\UserProfile\UserMessages::class))
                <livewire:admin.user-profile.user-messages :user-id="$user->id" :key="'user-messages-'.$user->id" />
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <i class="fad fa-info-circle mr-2"></i>
                    {{ __('app.module_not_available') }}
                </div>
            @endif
        </x-ui.accordion.tab-panel>
    </x-ui.accordion.tabs>
</div>
