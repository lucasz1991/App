<div
    x-data="railtimePushSettings(@js($clientConfig))"
    class="space-y-4"
    data-testid="push-settings"
>
    <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <div class="flex flex-col gap-4 border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:flex-row sm:items-start sm:justify-between sm:p-6">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                    <i class="far fa-mobile-alt text-lg" aria-hidden="true"></i>
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

            <span
                x-cloak
                class="inline-flex w-fit items-center gap-2 rounded-full bg-rt-surface-muted px-3 py-1.5 text-xs font-semibold text-rt-muted ring-1 ring-inset ring-rt-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border"
            >
                <span class="h-2 w-2 rounded-full bg-rt-accent" aria-hidden="true"></span>
                <span x-text="statusTitle()"></span>
            </span>
        </div>

        <div class="space-y-5 p-4 sm:p-6">
            <div class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="statusTitle()"></p>
                <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" x-text="statusDescription()"></p>

                <p
                    x-cloak
                    x-show="currentDeviceSubscribed"
                    class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                >
                    <i class="far fa-check-circle" aria-hidden="true"></i>
                    {{ __('app.push_current_device_active') }}
                </p>
                <p
                    x-cloak
                    x-show="!currentDeviceSubscribed && Number(server.subscription_count || 0) > 0"
                    class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted"
                >
                    <i class="far fa-mobile-alt" aria-hidden="true"></i>
                    {{ __('app.push_other_device_active') }}
                </p>
            </div>

            @if (! $pushDiagnostics['ready'])
                <div
                    class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-amber-950 dark:border-amber-900/70 dark:bg-amber-950/35 dark:text-amber-100"
                    role="status"
                    data-testid="push-settings-diagnostics"
                >
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/70 dark:text-amber-200">
                            <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">{{ __('app.help_push_not_ready_title') }}</h3>
                            <ul class="mt-1.5 space-y-1 text-xs leading-5 text-amber-900/85 dark:text-amber-100/80">
                                @foreach ($pushDiagnostics['issues'] as $issue)
                                    <li class="flex gap-2">
                                        <span aria-hidden="true">•</span>
                                        <span>{{ __('app.help_push_issue_'.$issue) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            <div
                x-cloak
                x-show="showIosInstallHelp"
                class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sky-950 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100"
            >
                <h3 class="flex items-center gap-2 text-sm font-semibold">
                    <i class="fab fa-apple" aria-hidden="true"></i>
                    {{ __('app.push_ios_install_title') }}
                </h3>
                <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm leading-6">
                    <li>{{ __('app.push_ios_install_step_share') }}</li>
                    <li>{{ __('app.push_ios_install_step_home') }}</li>
                    <li>{{ __('app.push_ios_install_step_open') }}</li>
                </ol>
            </div>

            <div
                x-cloak
                x-show="!install.installed && !showIosInstallHelp && !canPromptInstall"
                class="rounded-xl border border-rt-border bg-rt-surface-muted p-4 text-sm leading-6 text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted"
            >
                <span class="font-semibold text-rt-text dark:text-rt-dark-text">
                    {{ __('app.push_install_manually_title') }}
                </span>
                {{ __('app.push_install_manually_description') }}
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    x-cloak
                    x-show="canPromptInstall"
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
                    x-show="canSubscribe"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-rt-accent px-4 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-accent focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                    :disabled="busy !== null"
                    @click="subscribe()"
                >
                    <i class="far fa-bell" aria-hidden="true"></i>
                    <span x-show="busy !== 'subscribe'">{{ __('app.push_enable') }}</span>
                    <span x-cloak x-show="busy === 'subscribe'">{{ __('app.push_enabling') }}</span>
                </button>

                <button
                    x-cloak
                    x-show="canUnsubscribe"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-4 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition hover:bg-rt-nav-hover focus:outline-none focus:ring-2 focus:ring-rt-accent focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-nav-hover"
                    :disabled="busy !== null"
                    @click="unsubscribe()"
                >
                    <i class="far fa-bell-slash" aria-hidden="true"></i>
                    {{ __('app.push_disable_device') }}
                </button>

                <button
                    x-cloak
                    x-show="canTest"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-4 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition hover:bg-rt-nav-hover focus:outline-none focus:ring-2 focus:ring-rt-accent focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-nav-hover"
                    :disabled="busy !== null"
                    @click="sendTest()"
                >
                    <i class="far fa-paper-plane" aria-hidden="true"></i>
                    {{ __('app.push_send_test') }}
                </button>
            </div>

            <div aria-live="polite" class="space-y-2">
                <p
                    x-cloak
                    x-show="error"
                    class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
                    x-text="error"
                ></p>
                <p
                    x-cloak
                    x-show="success"
                    class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                    x-text="success"
                ></p>
            </div>
        </div>
    </section>

    <section
        x-cloak
        x-show="showPreferences"
        class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-6"
    >
        <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
            {{ __('app.push_preferences_title') }}
        </h2>
        <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            {{ __('app.push_preferences_description') }}
        </p>

        <div class="mt-4 divide-y divide-rt-border/60 rounded-xl border border-rt-border/60 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/60">
            @foreach ($preferences as $category => $preference)
                <label class="flex cursor-pointer items-center justify-between gap-4 p-4">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                            {{ $preference['label'] }}
                        </span>
                        <span class="mt-0.5 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.push_category_description_'.$category) }}
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        class="h-5 w-5 shrink-0 rounded border-rt-border text-rt-accent focus:ring-rt-accent disabled:cursor-wait disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-control"
                        :checked="Boolean(preferences[@js($category)])"
                        :disabled="busy !== null"
                        @change="savePreference(@js($category), $event.target.checked)"
                    >
                </label>
            @endforeach
        </div>
    </section>

    <p class="flex items-start gap-2 px-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
        <i class="far fa-lock mt-0.5 shrink-0" aria-hidden="true"></i>
        <span>{{ __('app.push_privacy_hint') }}</span>
    </p>
</div>
