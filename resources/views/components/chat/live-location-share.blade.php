@props([
    'chatId',
    'startUrl',
    'replyToMessageId' => null,
    'context' => 'chat',
])

@php
    $modalId = 'live-location-picker-'.$context.'-'.$chatId;
@endphp

<div
    x-data="railtimeLiveLocationShare({
        chatId: @js((int) $chatId),
        startUrl: @js($startUrl),
        replyToMessageId: @js($replyToMessageId ? (int) $replyToMessageId : null),
        messages: {
            unsupported: @js(__('app.chat_live_location_unsupported')),
            insecure: @js(__('app.chat_live_location_secure_context_required')),
            permissionDenied: @js(__('app.chat_live_location_permission_denied')),
            unavailable: @js(__('app.chat_live_location_unavailable')),
            startFailed: @js(__('app.chat_live_location_failed')),
            invalidDuration: @js(__('app.chat_live_location_invalid_duration')),
        },
    })"
    data-chat-action-ignore
    data-no-chat-swipe
    wire:key="live-location-share-{{ $context }}-{{ $chatId }}-{{ $replyToMessageId ?: 'none' }}"
    {{ $attributes }}
>
    <button
        type="button"
        x-on:click="openPicker()"
        x-bind:disabled="busy"
        class="rt-chat-composer-action flex h-10 w-10 shrink-0 items-center justify-center rounded-xl disabled:cursor-wait disabled:opacity-50"
        title="{{ __('app.chat_live_location_share') }}"
        aria-label="{{ __('app.chat_live_location_share') }}"
    >
        <i x-bind:class="busy ? 'fas fa-spinner fa-spin' : 'far fa-location-dot'" aria-hidden="true"></i>
    </button>

    <x-ui.state-modal
        :id="$modalId"
        state="open"
        :title="__('app.chat_live_location_choose_duration')"
        :description="__('app.chat_live_location_duration_hint')"
        icon="fad fa-location-dot"
        max-width="2xl"
        close-action="closePicker()"
    >
        <div class="space-y-4">
            <div class="grid gap-2 sm:grid-cols-5" role="group" aria-label="{{ __('app.chat_live_location_choose_duration') }}">
                <template x-for="minutes in durations" :key="minutes">
                    <button
                        type="button"
                        x-on:click="share(minutes)"
                        x-bind:disabled="busy || !$store.liveLocation.supported"
                        class="rt-live-location-duration group flex min-h-14 items-center justify-between gap-3 rounded-2xl px-4 py-3 text-left transition disabled:cursor-wait disabled:opacity-50 sm:min-h-[5.5rem] sm:flex-col sm:items-start sm:justify-center"
                    >
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" aria-hidden="true">
                            <i class="far fa-clock"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-base font-extrabold tabular-nums" x-text="minutes"></span>
                            <span class="block text-[10px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.minutes') }}</span>
                        </span>
                    </button>
                </template>
            </div>

            <div
                x-cloak
                x-show.important="!$store.liveLocation.supported"
                class="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25"
                style="display: none;"
                role="status"
            >
                <i class="far fa-triangle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
                <p x-text="$store.liveLocation.secureContext ? messages.unsupported : messages.insecure"></p>
            </div>

            <div class="flex items-start gap-3 rounded-2xl bg-rt-surface-muted p-4 text-xs leading-5 text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-surface text-rt-red ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-accent dark:ring-rt-dark-border/60">
                    <i class="far fa-shield-check" aria-hidden="true"></i>
                </span>
                <p>{{ __('app.chat_live_location_privacy_hint') }}</p>
            </div>

            <p
                x-cloak
                x-show="error"
                x-text="error"
                class="rounded-2xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-500/25"
                role="alert"
            ></p>
        </div>

        <x-slot:footer>
            <button
                type="button"
                x-on:click="closePicker()"
                class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold text-rt-muted ring-1 ring-inset ring-rt-border transition hover:text-rt-text dark:text-rt-dark-muted dark:ring-rt-dark-border dark:hover:text-rt-dark-text sm:flex-none"
            >
                {{ __('app.cancel') }}
            </button>
        </x-slot:footer>
    </x-ui.state-modal>
</div>
