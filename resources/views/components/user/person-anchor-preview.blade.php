@props([
    'user',
    'profileUrl' => null,
    'canMessage' => false,
    'canChat' => false,
    'canCall' => false,
    'chatAction' => null,
    'callAction' => null,
    'selected' => false,
])

@php
    $position = $user->profile?->position ?: __('app.position_not_set');
    $team = $user->currentTeam?->name ?: '—';
    $safeChatAction = is_string($chatAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $chatAction)
        ? $chatAction
        : null;
    $safeCallAction = is_string($callAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callAction)
        ? $callAction
        : null;
@endphp

<x-ui.dropdown.anchor-dropdown
    align="left"
    width="80"
    :offset="10"
    content-role="dialog"
    content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    data-person-anchor-preview
>
    <x-slot:trigger>
        @isset($trigger)
            {{ $trigger }}
        @else
            <button
                type="button"
                class="group min-w-0 rounded-lg px-1 py-0.5 text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent"
                aria-label="{{ __('app.open_person_preview') }}: {{ $user->name }}"
                title="{{ __('app.open_person_preview') }}"
                data-table-row-ignore
            >
                <x-user.public-info
                    :user="$user"
                    :size="10"
                    :show-email="true"
                    :selected="$selected"
                />
            </button>
        @endisset
    </x-slot:trigger>

    <x-slot:content>
        <article class="w-80 max-w-[calc(100vw-1.5rem)] overflow-hidden" aria-label="{{ __('app.person_preview') }}">
            <header class="relative overflow-hidden border-b border-rt-border/70 px-4 py-4 dark:border-rt-dark-border/70">
                <span class="pointer-events-none absolute inset-x-0 top-0 h-20 bg-gradient-to-br from-rt-red/10 via-rt-red/[0.03] to-transparent dark:from-rt-dark-accent/15" aria-hidden="true"></span>
                <div class="relative flex min-w-0 items-center gap-3">
                    <img
                        src="{{ $user->profile_photo_url }}"
                        alt=""
                        class="h-12 w-12 shrink-0 rounded-xl object-cover shadow-rt-xs ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"
                    >
                    <div class="min-w-0">
                        <h3 class="truncate text-base font-bold tracking-tight text-rt-text dark:text-rt-dark-text">
                            {{ $user->name }}
                        </h3>
                        <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $position }}</p>
                    </div>
                </div>
            </header>

            @if (($canChat && $safeChatAction) || ($canCall && $safeCallAction))
                <div class="grid grid-cols-3 gap-1.5 border-b border-rt-border/70 p-2 dark:border-rt-dark-border/70" aria-label="{{ __('app.communication_actions') }}">
                    @if ($canChat && $safeChatAction)
                        <button
                            type="button"
                            x-on:click.prevent='$wire.call(@js($safeChatAction), {{ $user->id }})'
                            class="group flex min-w-0 flex-col items-center gap-1.5 rounded-lg px-1.5 py-2 text-center text-rt-muted outline-none transition-all duration-200 hover:bg-rt-red/10 hover:text-rt-red active:scale-[0.97] focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.chat') }}"
                            data-table-row-ignore
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rt-surface-muted text-sm ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-comment-dots" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.chat') }}</span>
                        </button>
                    @endif

                    @if ($canCall && $safeCallAction)
                        <button
                            type="button"
                            x-on:click.prevent='$wire.call(@js($safeCallAction), {{ $user->id }}, "voice")'
                            class="group flex min-w-0 flex-col items-center gap-1.5 rounded-lg px-1.5 py-2 text-center text-rt-muted outline-none transition-all duration-200 hover:bg-rt-red/10 hover:text-rt-red active:scale-[0.97] focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.voice_call') }}"
                            data-table-row-ignore
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rt-surface-muted text-sm ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-phone-alt" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.voice_call') }}</span>
                        </button>

                        <button
                            type="button"
                            x-on:click.prevent='$wire.call(@js($safeCallAction), {{ $user->id }}, "video")'
                            class="group flex min-w-0 flex-col items-center gap-1.5 rounded-lg px-1.5 py-2 text-center text-rt-muted outline-none transition-all duration-200 hover:bg-rt-red/10 hover:text-rt-red active:scale-[0.97] focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                            aria-label="{{ __('app.video_call') }}"
                            data-table-row-ignore
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rt-surface-muted text-sm ring-1 ring-rt-border/70 transition-colors group-hover:bg-rt-surface dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70 dark:group-hover:bg-rt-dark-surface">
                                <i class="far fa-video" aria-hidden="true"></i>
                            </span>
                            <span class="w-full truncate text-[10px] font-bold">{{ __('app.video_call') }}</span>
                        </button>
                    @endif
                </div>
            @endif

            <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 px-4 py-3 text-xs">
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.email') }}</dt>
                <dd class="truncate text-right text-rt-text dark:text-rt-dark-text">{{ $user->email }}</dd>
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.team') }}</dt>
                <dd class="truncate text-right text-rt-text dark:text-rt-dark-text">{{ $team }}</dd>
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.status') }}</dt>
                <dd class="flex items-center justify-end gap-1.5 font-semibold {{ $user->isActive() ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $user->isActive() ? 'bg-emerald-500' : 'bg-red-500' }}" aria-hidden="true"></span>
                    {{ $user->isActive() ? __('app.active') : __('app.inactive') }}
                </dd>
            </dl>

            <div class="space-y-0.5 border-t border-rt-border/70 p-1.5 dark:border-rt-dark-border/70">
                @if ($profileUrl)
                    <x-dropdown-link :href="$profileUrl" :can="'users.profiles.view'">
                        <i class="far fa-id-card" aria-hidden="true"></i>
                        {{ __('app.view_profile') }}
                    </x-dropdown-link>
                @endif

                @if ($canMessage)
                    <x-dropdown-link wire:click.prevent="openMessage({{ $user->id }})" :can="'users.messages.create'">
                        <i class="far fa-paper-plane" aria-hidden="true"></i>
                        {{ __('app.compose_message') }}
                    </x-dropdown-link>
                @endif

                <x-dropdown-link href="mailto:{{ $user->email }}" data-no-navigate>
                    <i class="far fa-envelope" aria-hidden="true"></i>
                    {{ __('app.send_email') }}
                </x-dropdown-link>
            </div>
        </article>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
