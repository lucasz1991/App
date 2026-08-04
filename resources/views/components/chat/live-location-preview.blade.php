<div
    x-data="railtimeLiveLocationPreview({
        messages: {
            updatedNow: @js(__('app.chat_live_location_updated_now')),
            updatedMinutes: @js(__('app.chat_live_location_updated_minutes')),
            paused: @js(__('app.chat_live_location_paused')),
            live: @js(__('app.chat_live_location_live')),
            expired: @js(__('app.chat_live_location_expired')),
            stopped: @js(__('app.chat_live_location_stopped')),
            locationPending: @js(__('app.chat_live_location_unavailable')),
            locationUnavailable: @js(__('app.chat_live_location_unavailable')),
            mapFailed: @js(__('app.chat_live_location_map_failed')),
            stopFailed: @js(__('app.chat_live_location_stop_failed')),
        },
    })"
    data-chat-action-ignore
    data-no-chat-swipe
>
    <template x-teleport="body">
        <div
            x-cloak
            x-show.important="$store.liveLocation.resumePromptVisible"
            x-on:keydown.escape.window="if ($store.liveLocation.resumePromptVisible) { $store.liveLocation.dismissResumePrompt() }"
            x-transition:enter="transition duration-200 ease-out"
            x-transition:enter-start="translate-y-3 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-[175] mx-auto flex max-w-xl items-start gap-3 rounded-2xl bg-slate-950/95 p-3 pr-12 text-white shadow-2xl ring-1 ring-white/10 backdrop-blur-xl sm:p-4 sm:pr-14"
            style="display: none;"
            role="status"
            aria-live="polite"
            data-live-location-resume-banner
        >
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-rt-dark-accent">
                <i class="far fa-location-dot" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-xs leading-5 sm:text-sm">{{ __('app.chat_live_location_resume_hint') }}</p>
                <p
                    x-cloak
                    x-show.important="$store.liveLocation.permissionState === 'denied' || $store.liveLocation.resumeError"
                    x-text="$store.liveLocation.permissionState === 'denied' || $store.liveLocation.resumeErrorCode === 'permission_denied'
                        ? @js(__('app.chat_live_location_permission_denied'))
                        : ($store.liveLocation.resumeError || @js(__('app.chat_live_location_unavailable')))"
                    class="mt-1 text-[11px] font-semibold leading-4 text-red-200"
                    style="display: none;"
                    role="alert"
                ></p>
                <button
                    x-cloak
                    x-show.important="$store.liveLocation.canResume"
                    type="button"
                    x-on:click="$store.liveLocation.resume().catch(() => null)"
                    x-bind:disabled="$store.liveLocation.busy"
                    class="mt-2 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-white px-3 text-xs font-extrabold text-slate-950 transition hover:bg-slate-100 disabled:cursor-wait disabled:opacity-60 sm:px-4"
                    style="display: none;"
                >
                    <i x-bind:class="$store.liveLocation.busy === 'resume' ? 'fas fa-spinner fa-spin' : 'far fa-play'" aria-hidden="true"></i>
                    <span>{{ __('app.chat_live_location_resume') }}</span>
                </button>
            </div>
            <button
                type="button"
                x-on:click="$store.liveLocation.dismissResumePrompt()"
                class="absolute right-1.5 top-1.5 inline-flex h-11 w-11 items-center justify-center rounded-xl text-white/70 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-white/20 sm:right-2 sm:top-2"
                title="{{ __('app.chat_live_location_resume_dismiss') }}"
                aria-label="{{ __('app.chat_live_location_resume_dismiss') }}"
            >
                <i class="far fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </template>

    <x-ui.state-modal
        id="live-location-preview"
        state="open"
        :title="__('app.chat_live_location_preview_title')"
        :description="__('app.chat_live_location_preview_description')"
        icon="fad fa-map-location-dot"
        max-width="6xl"
        close-action="close()"
        :layer="230"
        body-class="min-h-0 min-w-0 flex-1 overflow-hidden p-0"
        class="h-[calc(100dvh-1rem)] sm:h-[min(52rem,calc(100dvh-3rem))]"
    >
        <section class="relative isolate h-full min-h-0 overflow-hidden bg-rt-surface-muted dark:bg-rt-dark-surface-muted" data-live-location-map-stage>
            <div x-ref="map" wire:ignore class="rt-live-location-preview-map absolute inset-0 z-0" aria-label="{{ __('app.chat_live_location_preview_title') }}"></div>
            <div class="pointer-events-none absolute inset-0 z-10 bg-gradient-to-b from-slate-950/15 via-transparent to-slate-950/35" aria-hidden="true"></div>

            <div class="pointer-events-none absolute right-3 top-3 z-20 flex flex-col items-end gap-2 sm:right-4 sm:top-4">
                <span class="inline-flex min-h-9 items-center gap-2 rounded-xl bg-white/95 px-3 text-xs font-extrabold text-slate-900 shadow-lg ring-1 ring-black/5 backdrop-blur-xl dark:bg-slate-950/90 dark:text-white dark:ring-white/10">
                    <span class="rt-live-location-pulse h-2.5 w-2.5 rounded-full" x-bind:class="{ 'is-active': isActive && isFresh, 'is-paused': isActive && !isFresh }" aria-hidden="true"></span>
                    <span x-text="activityLabel"></span>
                </span>
                <span
                    x-cloak
                    x-show.important="isActive"
                    class="inline-flex min-h-9 items-center rounded-xl bg-white/95 px-3 text-xs font-extrabold tabular-nums text-slate-900 shadow-lg ring-1 ring-black/5 backdrop-blur-xl dark:bg-slate-950/90 dark:text-white dark:ring-white/10"
                    style="display: none;"
                    x-text="remainingLabel"
                ></span>
            </div>

            <div class="pointer-events-none absolute inset-x-3 bottom-7 z-30 sm:left-1/2 sm:right-auto sm:w-[min(44rem,calc(100%-2rem))] sm:-translate-x-1/2" data-live-location-map-overlay>
                <div class="pointer-events-auto rounded-2xl bg-white/95 p-3 shadow-2xl ring-1 ring-black/10 backdrop-blur-2xl dark:bg-slate-950/90 dark:ring-white/10 sm:p-4">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-extrabold text-slate-950 dark:text-white" x-text="senderName || @js(__('app.unknown'))"></p>
                            <dl class="mt-1.5 flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                                <div class="inline-flex min-w-0 items-center gap-1.5">
                                    <dt class="sr-only">{{ __('app.chat_live_location_last_update') }}</dt>
                                    <i class="far fa-clock shrink-0" aria-hidden="true"></i>
                                    <dd class="truncate" x-text="lastUpdatedLabel"></dd>
                                </div>
                                <div class="inline-flex items-center gap-1.5">
                                    <dt class="sr-only">{{ __('app.chat_live_location_accuracy') }}</dt>
                                    <i class="far fa-location-crosshairs shrink-0" aria-hidden="true"></i>
                                    <dd class="tabular-nums" x-text="accuracy ? `± ${Math.round(accuracy)} m` : '—'"></dd>
                                </div>
                            </dl>
                        </div>

                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                x-on:click="recenter()"
                                class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-slate-950 px-3.5 text-xs font-extrabold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-950/20 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-100 sm:flex-none"
                            >
                                <i class="far fa-location-crosshairs" aria-hidden="true"></i>
                                <span>{{ __('app.chat_live_location_recenter') }}</span>
                            </button>

                            <button
                                x-cloak
                                x-show.important="canStop && isActive"
                                type="button"
                                x-on:click="stopSharing()"
                                x-bind:disabled="stopping"
                                class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-rt-red px-3.5 text-xs font-extrabold text-white transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/25 disabled:cursor-wait disabled:opacity-60 sm:flex-none"
                                style="display: none;"
                            >
                                <i x-bind:class="stopping ? 'fas fa-spinner fa-spin' : 'far fa-stop-circle'" aria-hidden="true"></i>
                                <span>{{ __('app.chat_live_location_stop') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <p
                x-cloak
                x-show.important="mapError || error"
                x-text="mapError || error"
                class="absolute inset-x-3 bottom-36 z-40 rounded-xl bg-red-950/90 px-4 py-3 text-sm font-semibold text-white shadow-lg backdrop-blur sm:left-1/2 sm:right-auto sm:w-[min(36rem,calc(100%-2rem))] sm:-translate-x-1/2"
                style="display: none;"
                role="alert"
            ></p>
        </section>
    </x-ui.state-modal>
</div>
