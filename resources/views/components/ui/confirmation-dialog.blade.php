<div
    x-data="{
        open: false,
        busy: false,
        request: null,
        opener: null,
        title: '',
        message: '',
        variant: 'default',
        confirmLabel: '',
        cancelLabel: '',
        errorMessage: '',

        init() {
            if (!window.Dropzone) {
                return;
            }

            window.Dropzone.confirm = (question, accepted, rejected) => {
                window.dispatchEvent(new CustomEvent('rt-confirm', {
                    detail: {
                        title: @js(__('app.delete')),
                        message: String(question || ''),
                        variant: 'destructive',
                        confirmLabel: @js(__('app.continue')),
                        cancelLabel: @js(__('app.cancel')),
                        action: () => accepted?.(),
                        cancel: () => rejected?.(),
                    },
                }));
            };
        },

        ask(event) {
            const detail = event.detail ?? {};

            if (typeof detail.action !== 'function') {
                return;
            }

            this.request = detail;
            this.opener = event.target instanceof HTMLElement
                ? event.target.closest('button, a, [role=button], [tabindex]')
                : null;
            this.title = detail.title || @js(__('app.continue'));
            this.message = detail.message || '';
            this.variant = detail.variant === 'destructive' ? 'destructive' : 'default';
            this.confirmLabel = detail.confirmLabel || @js(__('app.continue'));
            this.cancelLabel = detail.cancelLabel || @js(__('app.cancel'));
            this.errorMessage = '';
            this.busy = false;
            this.open = true;

            this.$nextTick(() => {
                const initialControl = this.variant === 'destructive'
                    ? this.$refs.cancelButton
                    : this.$refs.confirmButton;

                initialControl?.focus();
            });
        },

        close(restoreFocus = true, cancelled = true) {
            if (this.busy) {
                return;
            }

            const opener = this.opener;
            const cancelAction = this.request?.cancel;

            this.open = false;
            this.request = null;
            this.errorMessage = '';
            this.opener = null;

            if (cancelled && typeof cancelAction === 'function') {
                try {
                    cancelAction();
                } catch (error) {
                    console.error('RailTime confirmation cancellation failed.', error);
                }
            }

            if (restoreFocus) {
                this.$nextTick(() => {
                    if (opener instanceof HTMLElement && document.contains(opener)) {
                        opener.focus();
                    }
                });
            }
        },

        async execute() {
            if (this.busy || typeof this.request?.action !== 'function') {
                return;
            }

            this.busy = true;
            this.errorMessage = '';

            try {
                await this.request.action();
                this.busy = false;
                this.close(true, false);
            } catch (error) {
                console.error('RailTime confirmation action failed.', error);
                this.busy = false;
                this.errorMessage = @js(__('app.error'));
                this.$nextTick(() => this.$refs.confirmButton?.focus());
            }
        },
    }"
    x-on:rt-confirm.window="ask($event)"
    x-on:keydown.escape.window="if (open && !busy) close()"
    x-show="open"
    x-cloak
    class="rt-ui-modal rt-modal-center-shell fixed inset-0 z-[340] overflow-y-auto px-4 py-6 sm:px-0"
    style="display: none;"
    data-rt-confirmation-dialog
>
    <div
        x-show="open"
        x-on:click="close()"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-slate-950/60 backdrop-blur-md"
        aria-hidden="true"
    ></div>

    <div
        x-show="open"
        x-trap.inert.noscroll="open"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-2 sm:scale-[0.985]"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-3 sm:translate-y-1 sm:scale-[0.99]"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="rt-confirmation-dialog-title"
        aria-describedby="rt-confirmation-dialog-message"
        x-bind:aria-busy="busy.toString()"
        class="rt-ui-surface rt-ui-modal-panel relative my-auto max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60"
    >
        <div class="px-5 pb-5 pt-6 sm:px-6 sm:pb-6">
            <div class="flex items-start gap-4">
                <span
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1"
                    x-bind:class="variant === 'destructive'
                        ? 'bg-red-100 text-red-600 ring-red-200/70 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20'
                        : 'bg-rt-accent-soft text-rt-accent ring-rt-accent/15 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/20'"
                    aria-hidden="true"
                >
                    <svg
                        x-show="variant === 'destructive'"
                        class="h-6 w-6"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>

                    <svg
                        x-show="variant !== 'destructive'"
                        class="h-6 w-6"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <h2
                        id="rt-confirmation-dialog-title"
                        class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text"
                        x-text="title"
                    ></h2>

                    <p
                        id="rt-confirmation-dialog-message"
                        class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted"
                        x-text="message"
                    ></p>

                    <p
                        x-show="errorMessage"
                        x-text="errorMessage"
                        class="mt-3 text-sm font-semibold text-red-600 dark:text-red-300"
                        role="status"
                        aria-live="polite"
                    ></p>
                </div>
            </div>
        </div>

        <div class="flex flex-row justify-end gap-2 border-t border-rt-border bg-rt-surface-muted/70 px-5 py-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/55 sm:px-6">
            <button
                type="button"
                x-ref="cancelButton"
                x-on:click="close()"
                x-bind:disabled="busy"
                class="rt-ui-button rt-ui-button-secondary inline-flex min-h-10 items-center justify-center rounded-lg border border-rt-border bg-rt-surface px-4 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition hover:bg-rt-surface-muted focus:outline-none focus:ring-2 focus:ring-rt-accent/30 disabled:cursor-wait disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                x-text="cancelLabel"
            ></button>

            <button
                type="button"
                x-ref="confirmButton"
                x-on:click="execute()"
                x-bind:disabled="busy"
                x-bind:class="variant === 'destructive'
                    ? 'border-red-600 bg-red-600 hover:bg-red-700 focus:ring-red-400/40'
                    : 'border-rt-red bg-rt-red hover:bg-rt-red-dark focus:ring-rt-red/40'"
                class="rt-ui-button inline-flex min-h-10 min-w-28 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70 dark:focus:ring-offset-slate-900"
            >
                <svg
                    x-show="busy"
                    class="h-4 w-4 animate-spin"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                    <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"></path>
                </svg>
                <span x-text="confirmLabel"></span>
            </button>
        </div>
    </div>
</div>
