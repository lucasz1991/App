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
                paused: @js(__('app.chat_live_location_paused')),
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
        class="rt-live-location-card {{ $own ? 'is-own' : 'is-other' }} min-w-0 max-w-full overflow-hidden rounded-[1rem]"
    >
        <div
            data-chat-action-ignore
            data-no-chat-swipe
            x-on:click="if (!$event.target.closest('a')) { openPreview() }"
            class="rt-live-location-map-button relative isolate h-36 w-full cursor-pointer overflow-hidden"
            data-live-location-mini-map
        >
            <span x-ref="map" wire:ignore class="absolute inset-0 z-0" role="group" aria-label="{{ __('app.chat_live_location_open_map') }}"></span>
            <span class="pointer-events-none absolute inset-0 z-10 bg-gradient-to-b from-slate-950/10 via-transparent to-slate-950/65" aria-hidden="true"></span>

            <span class="pointer-events-none absolute left-2 top-2 z-30 inline-flex min-h-8 max-w-[calc(100%-7rem)] items-center gap-1.5 rounded-lg bg-white/95 px-2.5 text-[9px] font-extrabold uppercase tracking-[0.08em] text-slate-900 shadow-md ring-1 ring-black/5 backdrop-blur-xl dark:bg-slate-950/90 dark:text-white dark:ring-white/10">
                <span class="rt-live-location-pulse h-2 w-2 shrink-0 rounded-full" x-bind:class="{ 'is-active': isActive && isFresh, 'is-paused': isActive && !isFresh }" aria-hidden="true"></span>
                <span class="truncate" x-text="isActive ? `${activityLabel} · ${remainingLabel}` : activityLabel"></span>
            </span>

            <div class="absolute right-2 top-2 z-40 flex gap-1.5">
                <button
                    type="button"
                    x-on:click.stop="openPreview()"
                    data-chat-action-ignore
                    data-no-chat-swipe
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/95 text-slate-900 shadow-lg ring-1 ring-black/5 backdrop-blur-xl transition hover:scale-[1.03] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-white/75 dark:bg-slate-950/90 dark:text-white dark:ring-white/10"
                    title="{{ __('app.chat_live_location_open_map') }}"
                    aria-label="{{ __('app.chat_live_location_open_map') }}"
                >
                    <i class="far fa-expand" aria-hidden="true"></i>
                </button>

                <button
                    x-cloak
                    x-show.important="canStop && isActive"
                    type="button"
                    x-on:click.stop="stopSharing()"
                    x-bind:disabled="stopping"
                    data-chat-action-ignore
                    data-no-chat-swipe
                    class="rt-live-location-stop inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/95 text-rt-red shadow-lg ring-1 ring-black/5 backdrop-blur-xl transition hover:scale-[1.03] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-white/75 disabled:cursor-wait disabled:opacity-60 dark:bg-slate-950/90 dark:text-rt-dark-accent dark:ring-white/10"
                    style="display: none;"
                    title="{{ __('app.chat_live_location_stop') }}"
                    aria-label="{{ __('app.chat_live_location_stop') }}"
                >
                    <i x-bind:class="stopping ? 'fas fa-spinner fa-spin' : 'far fa-stop-circle'" aria-hidden="true"></i>
                </button>
            </div>

            <div class="pointer-events-none absolute inset-x-2 bottom-5 z-30 min-w-0 rounded-xl bg-slate-950/75 px-2.5 py-2 text-white shadow-lg ring-1 ring-white/10 backdrop-blur-xl" data-live-location-mini-overlay>
                <p class="truncate text-[11px] font-extrabold">{{ $senderName }}</p>
                <div class="mt-1 flex min-w-0 items-center justify-between gap-2 text-[9px] font-semibold text-white/80">
                    <span class="inline-flex min-w-0 items-center gap-1">
                        <i class="far fa-clock shrink-0" aria-hidden="true"></i>
                        <span class="truncate" x-text="lastUpdatedLabel"></span>
                    </span>
                    <span class="inline-flex shrink-0 items-center gap-1 tabular-nums">
                        <i class="far fa-location-crosshairs" aria-hidden="true"></i>
                        <span x-text="accuracy ? `± ${Math.round(accuracy)} m` : '—'"></span>
                    </span>
                </div>
            </div>

            <p
                x-cloak
                x-show.important="mapError || error"
                x-text="mapError || error"
                class="absolute inset-x-2 bottom-5 z-50 rounded-xl bg-red-950/90 px-3 py-2 text-[10px] font-semibold text-white shadow-lg backdrop-blur"
                style="display: none;"
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
