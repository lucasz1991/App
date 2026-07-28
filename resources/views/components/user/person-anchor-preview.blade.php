@props([
    'user',
    'profileUrl' => null,
    'canMessage' => false,
    'canChat' => false,
    'canCall' => false,
    'chatAction' => null,
    'callAction' => null,
    'selected' => false,
    'triggerClasses' => 'inline-flex',
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
    $iconActionClass = 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-rt-border/70 bg-rt-surface-muted text-sm text-rt-muted shadow-rt-xs outline-none transition duration-200 ease-rt-spring hover:-translate-y-px hover:border-rt-red/30 hover:bg-rt-red/10 hover:text-rt-red active:translate-y-0 active:scale-[0.97] focus-visible:ring-2 focus-visible:ring-rt-red/40 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/35 dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent';
@endphp

<x-ui.dropdown.anchor-dropdown
    align="left"
    width="80"
    :offset="10"
    content-role="dialog"
    content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    :trigger-classes="$triggerClasses"
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
        <article class="w-80 max-w-[calc(100vw-1.5rem)] overflow-hidden" aria-label="{{ __('app.person_preview') }}: {{ $user->name }}">
            <div class="flex min-h-14 items-center justify-center gap-1.5 border-b border-rt-border/70 px-3 py-2.5 dark:border-rt-dark-border/70" aria-label="{{ __('app.communication_actions') }}">
                @if ($canChat && $safeChatAction)
                    <button
                        type="button"
                        x-on:click.prevent='$wire.call(@js($safeChatAction), {{ $user->id }})'
                        class="{{ $iconActionClass }}"
                        aria-label="{{ __('app.chat') }}"
                        title="{{ __('app.chat') }}"
                        data-table-row-ignore
                    >
                        <i class="far fa-comment-dots" aria-hidden="true"></i>
                    </button>
                @endif

                @if ($canCall && $safeCallAction)
                    <button
                        type="button"
                        x-on:click.prevent='$wire.call(@js($safeCallAction), {{ $user->id }}, "voice")'
                        class="{{ $iconActionClass }}"
                        aria-label="{{ __('app.voice_call') }}"
                        title="{{ __('app.voice_call') }}"
                        data-table-row-ignore
                    >
                        <i class="far fa-phone-alt" aria-hidden="true"></i>
                    </button>

                    <button
                        type="button"
                        x-on:click.prevent='$wire.call(@js($safeCallAction), {{ $user->id }}, "video")'
                        class="{{ $iconActionClass }}"
                        aria-label="{{ __('app.video_call') }}"
                        title="{{ __('app.video_call') }}"
                        data-table-row-ignore
                    >
                        <i class="far fa-video" aria-hidden="true"></i>
                    </button>
                @endif

                <a
                    href="mailto:{{ $user->email }}"
                    class="{{ $iconActionClass }}"
                    aria-label="{{ __('app.send_email') }}"
                    title="{{ __('app.send_email') }}"
                    data-no-navigate
                    data-table-row-ignore
                >
                    <i class="far fa-envelope" aria-hidden="true"></i>
                </a>

                @if ($profileUrl)
                    @can('users.profiles.view')
                        <a
                            href="{{ $profileUrl }}"
                            wire:navigate
                            class="{{ $iconActionClass }}"
                            aria-label="{{ __('app.view_profile') }}"
                            title="{{ __('app.view_profile') }}"
                            data-table-row-ignore
                        >
                            <i class="far fa-id-card" aria-hidden="true"></i>
                        </a>
                    @endcan
                @endif
            </div>

            <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 px-4 py-3 text-xs">
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.email') }}</dt>
                <dd class="truncate text-right text-rt-text dark:text-rt-dark-text">{{ $user->email }}</dd>
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.team') }}</dt>
                <dd class="truncate text-right text-rt-text dark:text-rt-dark-text">{{ $team }}</dd>
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.position') }}</dt>
                <dd class="truncate text-right text-rt-text dark:text-rt-dark-text">{{ $position }}</dd>
                <dt class="font-semibold text-rt-soft dark:text-rt-dark-soft">{{ __('app.status') }}</dt>
                <dd class="flex items-center justify-end gap-1.5 font-semibold {{ $user->isActive() ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $user->isActive() ? 'bg-emerald-500' : 'bg-red-500' }}" aria-hidden="true"></span>
                    {{ $user->isActive() ? __('app.active') : __('app.inactive') }}
                </dd>
            </dl>
        </article>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
