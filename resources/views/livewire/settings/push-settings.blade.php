<div
    x-data="railtimePushSettings(@js($clientConfig))"
    data-testid="push-settings"
>
    <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <div class="flex items-start gap-3 border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                <i class="far fa-bell text-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ __('app.push_settings_title') }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.push_settings_description') }}
                </p>
            </div>
        </div>

        <div class="space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-4 rounded-xl bg-rt-surface-muted px-4 py-3.5 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="h-2.5 w-2.5 shrink-0 rounded-full"
                        :class="currentDeviceSubscribed ? 'bg-emerald-500' : 'bg-slate-400 dark:bg-slate-500'"
                        aria-hidden="true"
                    ></span>
                    <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="statusTitle()"></p>
                </div>

                <span
                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="currentDeviceSubscribed
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                        : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'"
                    x-text="currentDeviceSubscribed ? @js(__('app.active')) : @js(__('app.inactive'))"
                ></span>
            </div>

            <p
                x-cloak
                x-show.important="showIosInstallHelp"
                class="rounded-xl bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-900 ring-1 ring-inset ring-sky-200 dark:bg-sky-500/10 dark:text-sky-100 dark:ring-sky-500/30"
            >
                {{ __('app.push_ios_install_compact') }}
            </p>

            <p
                x-cloak
                x-show.important="!install.installed && !showIosInstallHelp && !canPromptInstall"
                class="rounded-xl bg-rt-surface-muted px-4 py-3 text-sm leading-6 text-rt-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60"
            >
                {{ __('app.push_install_manually_description') }}
            </p>

            <div class="flex flex-wrap gap-2">
                <button
                    x-cloak
                    x-show.important="canPromptInstall"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-rt-ink px-4 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-rt-accent focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:bg-white dark:text-rt-ink dark:hover:bg-slate-200"
                    :disabled="busy !== null"
                    @click="promptInstall()"
                >
                    <i class="far fa-download" aria-hidden="true"></i>
                    {{ __('app.push_install_app') }}
                </button>

                <button
                    x-cloak
                    x-show.important="canSubscribe"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-rt-accent px-4 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-accent focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                    :disabled="busy !== null"
                    @click="subscribe()"
                >
                    <i class="far fa-bell" aria-hidden="true"></i>
                    <span x-show.important="busy !== 'subscribe'">{{ __('app.push_enable') }}</span>
                    <span x-cloak x-show.important="busy === 'subscribe'">{{ __('app.push_enabling') }}</span>
                </button>

                <button
                    x-cloak
                    x-show.important="canUnsubscribe"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-4 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition hover:bg-rt-nav-hover focus:outline-none focus:ring-2 focus:ring-rt-accent focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-nav-hover"
                    :disabled="busy !== null"
                    @click="unsubscribe()"
                >
                    <i class="far fa-bell-slash" aria-hidden="true"></i>
                    {{ __('app.push_disable_device') }}
                </button>
            </div>

            <div aria-live="polite" class="space-y-2">
                <p
                    x-cloak
                    x-show.important="error"
                    class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                    x-text="error"
                ></p>
                <p
                    x-cloak
                    x-show.important="success"
                    class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                    x-text="success"
                ></p>
            </div>
        </div>
    </section>
</div>
