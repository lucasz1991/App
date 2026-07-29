<div
    x-data="{
        visible: false,
        call: null,
        secondsLeft: 0,
        countdownTimer: null,
        ringTimer: null,
        channel: null,

        init() {
            // Cross-Tab-Koordination: Annehmen/Ablehnen in einem Tab beendet
            // das Klingeln ueberall; den Ton spielt nur der sichtbare Tab.
            if ('BroadcastChannel' in window) {
                this.channel = new BroadcastChannel('railtime-call-ring');
                this.channel.addEventListener('message', (event) => {
                    if (event.data?.type === 'call-ring-stop'
                        && (! this.call || event.data.invitationId === this.call.invitationId)) {
                        // notify=false: sonst antwortet dieser Tab mit einer
                        // eigenen Stopp-Nachricht und die Tabs schaukeln sich auf.
                        this.hide(false);
                    }
                });
            }
        },

        onInvited(detail) {
            if (! detail?.invitationId || this.visible) {
                return;
            }

            this.call = detail;
            this.visible = true;

            const expires = detail.expiresAt ? new Date(detail.expiresAt).getTime() : Date.now() + 180000;
            this.tickCountdown(expires);
            this.countdownTimer = setInterval(() => this.tickCountdown(expires), 500);

            this.playRing();
            this.ringTimer = setInterval(() => this.playRing(), 2600);
        },

        onMissed(detail) {
            if (this.call && detail?.invitationId === this.call.invitationId) {
                this.hide(false);
            }
        },

        // Der Server konnte die Einladung nicht annehmen (Anruf schon beendet,
        // abgelaufen oder abgelehnt). Ohne dieses Signal bliebe die Karte nach
        // accept() stehen und onInvited() wuerde jeden weiteren Anruf verwerfen.
        onGone(detail) {
            if (! this.call || detail?.invitationId === this.call.invitationId) {
                this.hide(false);
            }
        },

        tickCountdown(expires) {
            this.secondsLeft = Math.max(0, Math.round((expires - Date.now()) / 1000));

            if (this.secondsLeft <= 0) {
                this.hide();
            }
        },

        playRing() {
            if (! document.hidden) {
                window.RTSound?.play('call');
            }
        },

        accept() {
            const id = this.call?.invitationId;
            this.stopRinging();
            $wire.accept(id);
        },

        decline() {
            const id = this.call?.invitationId;
            // hide() meldet den Stopp jetzt selbst an die anderen Tabs –
            // vorher blieb das Geschwister-Tab nach dem Ablehnen weiterklingeln.
            this.hide();
            $wire.decline(id);
        },

        stopRinging(notify = true) {
            clearInterval(this.countdownTimer);
            clearInterval(this.ringTimer);

            if (notify && this.call) {
                this.channel?.postMessage({ type: 'call-ring-stop', invitationId: this.call.invitationId });
            }
        },

        hide(notify = true) {
            this.stopRinging(notify);
            this.visible = false;
            this.call = null;
        },
    }"
    x-on:rt:call-invited.window="onInvited($event.detail)"
    x-on:rt:call-missed.window="onMissed($event.detail)"
    x-on:rt:call-gone.window="onGone($event.detail)"
>
    <template x-teleport="body">
        <div
            x-cloak
            x-show.important="visible"
            x-transition:enter="transition duration-300 ease-out"
            x-transition:enter-start="translate-y-4 opacity-0 sm:-translate-y-4"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition duration-200 ease-in"
            x-transition:leave-end="translate-y-4 opacity-0 sm:-translate-y-4"
            class="fixed inset-x-0 bottom-4 z-[95] flex justify-center px-4 sm:inset-x-auto sm:bottom-auto sm:right-6 sm:top-6"
            role="alertdialog"
            aria-modal="false"
            aria-label="{{ __('app.calls_incoming_title') }}"
        >
            <div class="w-full max-w-sm overflow-hidden rounded-[1.35rem] bg-rt-surface shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="flex items-center gap-4 p-5">
                    <span class="relative inline-flex shrink-0">
                        <span class="absolute inset-0 animate-ping rounded-full bg-emerald-500/30"></span>
                        <template x-if="call?.callerAvatar">
                            <img
                                :src="call.callerAvatar"
                                :alt="call?.callerName"
                                class="relative h-14 w-14 rounded-full object-cover ring-2 ring-emerald-500/60"
                            >
                        </template>
                        <template x-if="! call?.callerAvatar">
                            <span class="relative inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/15 text-lg font-extrabold text-emerald-600 ring-2 ring-emerald-500/60 dark:text-emerald-400"
                                x-text="(call?.callerName || '?').trim().charAt(0).toUpperCase()"
                            ></span>
                        </template>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-emerald-600 dark:text-emerald-400">
                            <i class="far fa-video mr-1" aria-hidden="true"></i>
                            {{ __('app.calls_incoming_title') }}
                        </p>
                        <p class="mt-0.5 truncate text-base font-extrabold tracking-[-0.025em] text-rt-text dark:text-rt-dark-text" x-text="call?.callerName"></p>
                        <p class="mt-0.5 text-[11px] font-medium text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.calls_ringing') }}
                            <span class="tabular-nums" x-text="'(' + secondsLeft + 's)'"></span>
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 px-5 pb-5">
                    <button
                        type="button"
                        x-on:click="decline()"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-rt-surface-muted text-sm font-bold text-rt-text ring-1 ring-rt-border/60 transition-colors hover:bg-rt-red/10 hover:text-rt-red dark:bg-rt-dark-surface-muted dark:text-rt-dark-text dark:ring-rt-dark-border/60"
                    >
                        <i class="far fa-phone-slash" aria-hidden="true"></i>
                        {{ __('app.calls_decline') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="accept()"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-500 text-sm font-bold text-white shadow-sm transition-colors hover:bg-emerald-600"
                    >
                        <i class="far fa-phone" aria-hidden="true"></i>
                        {{ __('app.calls_accept') }}
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
