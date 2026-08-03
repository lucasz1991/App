<div
    x-data="railtimeLiveLocationPreview({
        messages: {
            updatedNow: @js(__('app.chat_live_location_updated_now')),
            updatedMinutes: @js(__('app.chat_live_location_updated_minutes')),
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
        class="h-[calc(100dvh-1rem)] sm:h-[min(52rem,calc(100dvh-3rem))]"
    >
        <div class="grid h-full min-h-[28rem] gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <section class="relative isolate min-h-[20rem] overflow-hidden rounded-2xl bg-rt-surface-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70">
                <div x-ref="map" wire:ignore class="rt-live-location-preview-map absolute inset-0 z-0" aria-label="{{ __('app.chat_live_location_preview_title') }}"></div>

                <div class="pointer-events-none absolute inset-x-3 top-3 z-20 flex flex-wrap items-start justify-between gap-2">
                    <span
                        class="inline-flex min-h-9 items-center gap-2 rounded-xl bg-white/90 px-3 text-xs font-extrabold text-slate-900 shadow-lg backdrop-blur dark:bg-slate-950/85 dark:text-white"
                    >
                        <span class="rt-live-location-pulse h-2.5 w-2.5 rounded-full" x-bind:class="isActive ? 'is-active' : ''" aria-hidden="true"></span>
                        <span x-text="isActive ? @js(__('app.chat_live_location_live')) : ((status === 'expired' || (status === 'active' && remainingSeconds === 0)) ? @js(__('app.chat_live_location_expired')) : @js(__('app.chat_live_location_stopped')))"></span>
                    </span>
                    <span
                        x-show.important="isActive"
                        class="inline-flex min-h-9 items-center rounded-xl bg-white/90 px-3 text-xs font-extrabold tabular-nums text-slate-900 shadow-lg backdrop-blur dark:bg-slate-950/85 dark:text-white"
                        x-text="remainingLabel"
                    ></span>
                </div>

                <p
                    x-cloak
                    x-show="mapError"
                    x-text="mapError"
                    class="absolute inset-x-4 bottom-4 z-20 rounded-xl bg-red-950/85 px-4 py-3 text-sm font-semibold text-white shadow-lg backdrop-blur"
                    role="alert"
                ></p>
            </section>

            <aside class="flex min-w-0 flex-col gap-3">
                <div class="rounded-2xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/70">
                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.chat_live_location_title') }}</p>
                    <p class="mt-1.5 truncate text-base font-extrabold text-rt-text dark:text-rt-dark-text" x-text="senderName || @js(__('app.unknown'))"></p>
                </div>

                <dl class="grid gap-px overflow-hidden rounded-2xl bg-rt-border/70 ring-1 ring-rt-border/70 dark:bg-rt-dark-border/70 dark:ring-rt-dark-border/70">
                    <div class="bg-rt-surface px-4 py-3.5 dark:bg-rt-dark-surface">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.chat_live_location_last_update') }}</dt>
                        <dd class="mt-1.5 text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="lastUpdatedLabel"></dd>
                    </div>
                    <div class="bg-rt-surface px-4 py-3.5 dark:bg-rt-dark-surface">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.chat_live_location_accuracy') }}</dt>
                        <dd class="mt-1.5 text-sm font-semibold tabular-nums text-rt-text dark:text-rt-dark-text" x-text="accuracy ? `± ${Math.round(accuracy)} m` : '—'"></dd>
                    </div>
                </dl>

                <div class="mt-auto space-y-2">
                    <button
                        type="button"
                        x-on:click="recenter()"
                        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-surface px-4 text-sm font-bold text-rt-text ring-1 ring-inset ring-rt-border transition hover:text-rt-red dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border dark:hover:text-rt-dark-accent"
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
                        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-bold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:cursor-wait disabled:opacity-60"
                    >
                        <i x-bind:class="stopping ? 'fas fa-spinner fa-spin' : 'far fa-stop-circle'" aria-hidden="true"></i>
                        <span>{{ __('app.chat_live_location_stop') }}</span>
                    </button>
                </div>

                <p class="flex items-start gap-2 rounded-xl bg-rt-surface-muted px-3 py-3 text-[11px] leading-4 text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                    <i class="far fa-mobile-screen-button mt-0.5 shrink-0" aria-hidden="true"></i>
                    <span>{{ __('app.chat_live_location_foreground_hint') }}</span>
                </p>

                <p x-cloak x-show="error" x-text="error" class="rounded-xl bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-800 ring-1 ring-red-600/20 dark:bg-red-500/10 dark:text-red-200" role="alert"></p>
            </aside>
        </div>
    </x-ui.state-modal>
</div>
