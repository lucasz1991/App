@props([
    'message',
    'own' => false,
    'canStop' => null,
])

@php
    $location = $message->liveLocation;
    $position = $location?->position() ?? [];
    $status = $location?->status() ?? 'stopped';
    $mayStop = ($canStop ?? $own) && $location?->isActive();
    $stopUrl = $mayStop ? route('chat.live-locations.destroy', ['liveLocation' => $location]) : null;
    $senderName = $message->sender?->name ?? __('app.unknown');
@endphp

@if ($location && isset($position['latitude'], $position['longitude']))
    <div
        x-data="railtimeLiveLocationCard({
            messages: {
                live: @js(__('app.chat_live_location_live')),
                expired: @js(__('app.chat_live_location_expired')),
                stopped: @js(__('app.chat_live_location_stopped')),
                updatedNow: @js(__('app.chat_live_location_updated_now')),
                updatedMinutes: @js(__('app.chat_live_location_updated_minutes')),
                locationPending: @js(__('app.chat_live_location_unavailable')),
                mapFailed: @js(__('app.chat_live_location_map_failed')),
                stopConfirm: @js(__('app.chat_live_location_stop_confirm')),
                stopFailed: @js(__('app.chat_live_location_stop_failed')),
            },
        })"
        data-live-location-share-id="{{ $location->uuid }}"
        data-live-location-message-id="{{ $message->id }}"
        data-live-location-chat-id="{{ $message->chat_id }}"
        data-live-location-latitude="{{ $position['latitude'] }}"
        data-live-location-longitude="{{ $position['longitude'] }}"
        data-live-location-accuracy="{{ $position['accuracy'] ?? '' }}"
        data-live-location-located-at="{{ $location->last_position_at?->toIso8601String() }}"
        data-live-location-expires-at="{{ $location->expires_at?->toIso8601String() }}"
        data-live-location-status="{{ $status }}"
        data-live-location-stop-url="{{ $stopUrl }}"
        data-live-location-can-stop="{{ $mayStop ? 'true' : 'false' }}"
        data-live-location-sender-name="{{ $senderName }}"
        data-chat-action-ignore
        data-no-chat-swipe
        class="rt-live-location-card {{ $own ? 'is-own' : 'is-other' }} min-w-[15rem] overflow-hidden rounded-[1rem]"
    >
        <div
            data-chat-action-ignore
            data-no-chat-swipe
            class="rt-live-location-map-button relative isolate h-40 w-full overflow-hidden"
        >
            <span x-ref="map" wire:ignore class="absolute inset-0 z-0" role="group" aria-label="{{ __('app.chat_live_location_open_map') }}"></span>
            <span class="pointer-events-none absolute inset-0 z-10 bg-gradient-to-t from-slate-950/45 via-transparent to-white/5"></span>
            <button
                type="button"
                x-on:click.stop="openPreview()"
                data-chat-action-ignore
                data-no-chat-swipe
                class="absolute right-2.5 top-2.5 z-20 flex h-10 w-10 items-center justify-center rounded-xl bg-white/90 text-slate-900 shadow-lg backdrop-blur transition hover:scale-105 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-white/70 dark:bg-slate-950/85 dark:text-white"
                title="{{ __('app.chat_live_location_open_map') }}"
                aria-label="{{ __('app.chat_live_location_open_map') }}"
            >
                <i class="far fa-expand" aria-hidden="true"></i>
            </button>
        </div>

        <div class="rt-live-location-copy space-y-2 px-3.5 py-3">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-sm font-extrabold">
                        <span class="rt-live-location-pulse h-2.5 w-2.5 shrink-0 rounded-full" x-bind:class="isActive ? 'is-active' : ''" aria-hidden="true"></span>
                        <span>{{ __('app.chat_live_location_title') }}</span>
                    </p>
                    <p class="mt-0.5 truncate text-[10px] font-semibold opacity-70">{{ $senderName }}</p>
                </div>
                <span
                    class="rt-live-location-status shrink-0 rounded-full px-2.5 py-1 text-[9px] font-extrabold uppercase tracking-[0.09em]"
                    x-bind:class="isActive ? 'is-active' : ''"
                    x-text="isActive ? remainingLabel : ((status === 'expired' || (status === 'active' && remainingSeconds === 0)) ? messages.expired : messages.stopped)"
                ></span>
            </div>

            <div class="flex items-center justify-between gap-3 text-[10px] font-semibold opacity-70">
                <span class="inline-flex min-w-0 items-center gap-1.5">
                    <i class="far fa-location-crosshairs shrink-0" aria-hidden="true"></i>
                    <span x-text="accuracy ? `± ${Math.round(accuracy)} m` : '—'"></span>
                </span>
                <span class="truncate" x-text="lastUpdatedLabel"></span>
            </div>

            <div class="flex gap-2">
                <button
                    type="button"
                    x-on:click.stop="openPreview()"
                    data-chat-action-ignore
                    data-no-chat-swipe
                    class="rt-live-location-secondary inline-flex min-h-10 min-w-0 flex-1 items-center justify-center gap-2 rounded-xl px-3 text-xs font-bold"
                >
                    <i class="far fa-map" aria-hidden="true"></i>
                    <span>{{ __('app.chat_live_location_open_map') }}</span>
                </button>

                <button
                    x-cloak
                    x-show="canStop && isActive"
                    type="button"
                    x-on:click.stop="stopSharing()"
                    x-bind:disabled="stopping"
                    data-chat-action-ignore
                    data-no-chat-swipe
                    class="rt-live-location-stop inline-flex min-h-10 min-w-10 items-center justify-center rounded-xl px-3 text-xs font-bold disabled:cursor-wait disabled:opacity-60"
                    title="{{ __('app.chat_live_location_stop') }}"
                    aria-label="{{ __('app.chat_live_location_stop') }}"
                >
                    <i x-bind:class="stopping ? 'fas fa-spinner fa-spin' : 'far fa-stop-circle'" aria-hidden="true"></i>
                </button>
            </div>

            <p
                x-cloak
                x-show="error"
                x-text="error"
                class="rounded-xl bg-red-950/80 px-3 py-2 text-[10px] font-semibold text-white"
                role="alert"
            ></p>
        </div>
    </div>
@else
    <div class="flex items-center gap-2 rounded-xl bg-black/10 px-3 py-2 text-xs font-semibold dark:bg-white/10">
        <i class="far fa-location-slash" aria-hidden="true"></i>
        <span>{{ __('app.chat_live_location_unavailable') }}</span>
    </div>
@endif
