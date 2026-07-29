{{--
    Einmalige Berechtigungs-Einrichtung.

    Wird im Master-Layout gemountet und meldet sich beim ersten Start selbst,
    solange noch etwas offen ist. Spaeter jederzeit erneut aufrufbar ueber:
        window.dispatchEvent(new CustomEvent('rt:permissions-open'))
--}}
@auth
@php
    // Gleiche Quelle wie die Push-Einstellungsseite (PushSettings::render):
    // Nur wenn der Server Push wirklich anbieten kann, bekommt der Dialog die
    // Daten fuer das automatische Geraete-Abo nach erteilter Berechtigung.
    $pushDiagnostics = \App\Support\Push\WebPushConfiguration::diagnostics();
    $pushClientConfig = ($pushDiagnostics['enabled'] && $pushDiagnostics['configured'])
        ? [
            'vapidPublicKey' => (string) config('webpush.vapid.public_key'),
            'accountBinding' => \App\Support\Push\WebPushConfiguration::accountBinding(auth()->id()),
            'subscribeUrl' => route('push.subscriptions.store'),
            'unsubscribeUrl' => route('push.subscriptions.destroy'),
        ]
        : null;
@endphp
<div
    x-data="permissionSetup({ userId: {{ auth()->id() }}, push: @js($pushClientConfig) })"
    x-on:keydown.escape.window="open && close()"
>
    <template x-teleport="body">
        <div
            x-cloak
            x-show.important="open"
            class="fixed inset-0 z-[210] flex items-end justify-center p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-labelledby="rt-permissions-title"
        >
            <div
                x-show="open"
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                {{-- Nur zuklappen: Ein Klick daneben darf den Dialog nicht
                     dauerhaft unterdruecken – das entscheidet allein der
                     Knopf im Fuss (dismiss). --}}
                class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm"
                x-on:click="close()"
            ></div>

            <div
                x-show="open"
                x-transition:enter="transition duration-250 ease-rt-spring"
                x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95"
                x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
                class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-rt-surface shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
            >
                <header class="flex items-start gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-shield-check fa-lg" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 id="rt-permissions-title" class="text-base font-bold text-rt-text dark:text-rt-dark-text">
                            {{ __('app.permissions_title') }}
                        </h2>
                        <p class="mt-0.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.permissions_intro') }}
                        </p>
                    </div>
                </header>

                <div class="space-y-2.5 px-5 py-5">
                    {{-- Mikrofon: Voraussetzung fuer jede Art von Anruf --}}
                    <div class="flex items-center gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-surface text-rt-muted dark:bg-rt-dark-surface dark:text-rt-dark-muted">
                            <i class="far fa-microphone" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 text-sm font-bold text-rt-text dark:text-rt-dark-text">
                                {{ __('app.permissions_microphone') }}
                                <span class="rounded-full bg-rt-accent-soft px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">{{ __('app.permissions_required') }}</span>
                            </p>
                            <p class="mt-0.5 text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">{{ __('app.permissions_microphone_hint') }}</p>
                        </div>
                        <template x-if="states.microphone === 'granted'">
                            <span class="shrink-0 rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">
                                <i class="far fa-check" aria-hidden="true"></i> {{ __('app.permissions_granted') }}
                            </span>
                        </template>
                        <template x-if="states.microphone === 'denied'">
                            <span class="shrink-0 rounded-full bg-rose-500/15 px-2.5 py-1 text-[11px] font-bold text-rose-600 dark:text-rose-400">
                                {{ __('app.permissions_denied') }}
                            </span>
                        </template>
                        <template x-if="states.microphone !== 'granted' && states.microphone !== 'denied'">
                            <button
                                type="button"
                                x-on:click="requestMicrophone()"
                                :disabled="busy !== null"
                                class="shrink-0 rounded-full bg-rt-accent px-3.5 py-1.5 text-[11px] font-bold text-white transition-colors hover:bg-rt-red-dark disabled:opacity-50"
                            >
                                <span x-show="busy !== 'microphone'">{{ __('app.permissions_allow') }}</span>
                                <span x-show="busy === 'microphone'" x-cloak>…</span>
                            </button>
                        </template>
                    </div>

                    {{-- Kamera: ausdruecklich optional, Sprachanrufe gehen ohne --}}
                    <div class="flex items-center gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-surface text-rt-muted dark:bg-rt-dark-surface dark:text-rt-dark-muted">
                            <i class="far fa-video" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 text-sm font-bold text-rt-text dark:text-rt-dark-text">
                                {{ __('app.permissions_camera') }}
                                <span class="rounded-full bg-rt-surface px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:ring-rt-dark-border/60">{{ __('app.permissions_optional') }}</span>
                            </p>
                            <p class="mt-0.5 text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">{{ __('app.permissions_camera_hint') }}</p>
                        </div>
                        <template x-if="states.camera === 'granted'">
                            <span class="shrink-0 rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">
                                <i class="far fa-check" aria-hidden="true"></i> {{ __('app.permissions_granted') }}
                            </span>
                        </template>
                        <template x-if="states.camera === 'denied'">
                            <span class="shrink-0 rounded-full bg-rose-500/15 px-2.5 py-1 text-[11px] font-bold text-rose-600 dark:text-rose-400">
                                {{ __('app.permissions_denied') }}
                            </span>
                        </template>
                        <template x-if="states.camera !== 'granted' && states.camera !== 'denied'">
                            <button
                                type="button"
                                x-on:click="requestCamera()"
                                :disabled="busy !== null"
                                class="shrink-0 rounded-full bg-rt-surface px-3.5 py-1.5 text-[11px] font-bold text-rt-text ring-1 ring-rt-border/60 transition-colors hover:bg-rt-accent-soft hover:text-rt-accent dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60"
                            >
                                <span x-show="busy !== 'camera'">{{ __('app.permissions_allow') }}</span>
                                <span x-show="busy === 'camera'" x-cloak>…</span>
                            </button>
                        </template>
                    </div>

                    {{-- Benachrichtigungen --}}
                    <div class="flex items-center gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-surface text-rt-muted dark:bg-rt-dark-surface dark:text-rt-dark-muted">
                            <i class="far fa-bell" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.permissions_notifications') }}</p>
                            <p class="mt-0.5 text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">{{ __('app.permissions_notifications_hint') }}</p>
                        </div>
                        <template x-if="states.notifications === 'granted'">
                            <span class="shrink-0 rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">
                                <i class="far fa-check" aria-hidden="true"></i> {{ __('app.permissions_granted') }}
                            </span>
                        </template>
                        <template x-if="states.notifications === 'denied'">
                            <span class="shrink-0 rounded-full bg-rose-500/15 px-2.5 py-1 text-[11px] font-bold text-rose-600 dark:text-rose-400">
                                {{ __('app.permissions_denied') }}
                            </span>
                        </template>
                        <template x-if="states.notifications !== 'granted' && states.notifications !== 'denied'">
                            <button
                                type="button"
                                x-on:click="requestNotifications()"
                                :disabled="busy === 'notifications'"
                                class="shrink-0 rounded-full bg-rt-accent px-3.5 py-1.5 text-[11px] font-bold text-white transition-colors hover:bg-rt-red-dark disabled:opacity-50"
                            >
                                <span x-show="busy !== 'notifications'">{{ __('app.permissions_allow') }}</span>
                                <span x-show="busy === 'notifications'" x-cloak>…</span>
                            </button>
                        </template>
                    </div>

                    {{-- Dateien: bewusst nur Information, keine Freigabe noetig --}}
                    <div class="flex items-center gap-3 rounded-xl px-3.5 py-2.5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-rt-muted dark:text-rt-dark-muted">
                            <i class="far fa-folder-open" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-rt-muted dark:text-rt-dark-muted">{{ __('app.permissions_files') }}</p>
                            <p class="mt-0.5 text-[11px] leading-4 text-rt-muted dark:text-rt-dark-muted">{{ __('app.permissions_files_hint') }}</p>
                        </div>
                    </div>

                    {{-- Firefox verlangt das Haekchen, sonst fragt er jedes Mal erneut.
                         Immer zeigen, solange der Dialog offen ist: Direkt nach dem
                         Erteilen steht der Status kurz auf "Erteilt" (der Stream wird
                         noch gehalten) – genau dann muss der Nutzer vom Haekchen
                         erfahren, sonst wundert er sich beim naechsten Start erneut. --}}
                    <template x-if="needsRememberHint">
                        <div class="flex items-start gap-2.5 rounded-xl border-l-4 border-amber-500 bg-amber-50 px-3.5 py-3 text-[11px] leading-5 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                            <i class="fad fa-lightbulb mt-0.5" aria-hidden="true"></i>
                            <span>{{ __('app.permissions_firefox_hint') }}</span>
                        </div>
                    </template>

                    {{-- Abgelehnt? Dann hilft nur die Browsereinstellung. --}}
                    <template x-if="states.microphone === 'denied' || states.camera === 'denied' || states.notifications === 'denied'">
                        <div class="flex items-start gap-2.5 rounded-xl border-l-4 border-rose-500 bg-rose-50 px-3.5 py-3 text-[11px] leading-5 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
                            <i class="fad fa-circle-exclamation mt-0.5" aria-hidden="true"></i>
                            <span>{{ __('app.permissions_denied_hint') }}</span>
                        </div>
                    </template>
                </div>

                <footer class="flex items-center justify-between gap-3 border-t border-rt-border/60 bg-rt-surface-muted px-5 py-3.5 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted">
                    <button
                        type="button"
                        x-on:click="refresh()"
                        class="text-[11px] font-semibold text-rt-muted underline-offset-2 hover:underline dark:text-rt-dark-muted"
                    >
                        {{ __('app.permissions_recheck') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="dismiss()"
                        class="rounded-full bg-rt-surface px-4 py-2 text-xs font-bold text-rt-text ring-1 ring-rt-border/60 transition-colors hover:bg-rt-surface-muted dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60"
                        {{-- "Fertig", sobald nichts ERFORDERLICHES mehr offen ist:
                             Die Kamera ist ausdruecklich optional, wer sie bewusst
                             auslaesst, ist trotzdem fertig eingerichtet. --}}
                        x-text="hasOpenItems ? @js(__('app.permissions_later')) : @js(__('app.permissions_done'))"
                    ></button>
                </footer>
            </div>
        </div>
    </template>
</div>
@endauth
