function sameOriginUrl(windowObject, value) {
    try {
        const url = new URL(String(value ?? ''), windowObject.location.href);

        return url.origin === windowObject.location.origin ? url : null;
    } catch (_) {
        return null;
    }
}

export function createRailTimeNavigationCoordinator(windowObject, documentObject) {
    const coordinator = {
        controllers: new Set(),
        navigationPromise: null,
        resumeTicket: null,
        stableUrl: String(windowObject.location?.href ?? ''),

        register(controller) {
            if (controller && typeof controller.hasPendingWork === 'function' && typeof controller.flush === 'function') {
                this.controllers.add(controller);
            }

            return () => this.unregister(controller);
        },

        unregister(controller) {
            this.controllers.delete(controller);
        },

        pendingControllers() {
            return Array.from(this.controllers)
                .filter((controller) => {
                    try {
                        return controller.hasPendingWork();
                    } catch (_) {
                        return true;
                    }
                });
        },

        hasPendingWork() {
            return this.pendingControllers().length > 0;
        },

        flushAll() {
            return Promise.all(
                this.pendingControllers().map((controller) => Promise.resolve()
                    .then(() => controller.flush())
                    .catch((error) => {
                        try {
                            controller.onFlushError?.(error);
                        } catch (_) {
                            // Presentation errors must not hide the failed flush.
                        }

                        throw error;
                    })),
            );
        },

        eligibleLink(event) {
            const link = event.target?.closest?.('a[href]');
            if (
                !link
                || link.target === '_blank'
                || link.hasAttribute('download')
                || event.metaKey
                || event.ctrlKey
                || event.shiftKey
                || event.altKey
            ) {
                return null;
            }

            return sameOriginUrl(windowObject, link.href) ? link : null;
        },

        destinationFromNavigateEvent(event) {
            const destination = event.detail?.url
                ?? event.detail?.destination?.url
                ?? event.detail?.to
                ?? null;

            return destination ? String(destination) : null;
        },

        reportFlushFailure(error, historyNavigation = false) {
            try {
                windowObject.dispatchEvent?.(new CustomEvent('railtime:navigation-flush-failed', {
                    detail: {
                        history: historyNavigation,
                        stable_url: this.stableUrl,
                    },
                }));
            } catch (_) {
                // Older browsers can continue without the optional notification.
            }
        },

        issueResumeTicket(url) {
            const destination = sameOriginUrl(windowObject, url);
            if (!destination) return null;

            const ticket = { url: destination.href, timer: null };
            this.resumeTicket = ticket;
            ticket.timer = windowObject.setTimeout?.(() => {
                if (this.resumeTicket === ticket) this.resumeTicket = null;
            }, 1_000);

            return ticket;
        },

        consumeResumeTicket(url) {
            if (!this.resumeTicket) return false;

            const destination = sameOriginUrl(windowObject, url);
            if (!destination || destination.href !== this.resumeTicket.url) return false;

            windowObject.clearTimeout?.(this.resumeTicket.timer);
            this.resumeTicket = null;

            return true;
        },

        resumeNavigation(url, useLivewire = true, historyNavigation = false) {
            if (historyNavigation) {
                windowObject.location.reload();

                return;
            }

            if (useLivewire && typeof windowObject.Livewire?.navigate === 'function') {
                const ticket = this.issueResumeTicket(url);
                try {
                    windowObject.Livewire.navigate(url);

                    return;
                } catch (_) {
                    if (this.resumeTicket === ticket) {
                        windowObject.clearTimeout?.(ticket?.timer);
                        this.resumeTicket = null;
                    }
                    // A synchronous Livewire failure falls back to a normal visit.
                }
            }

            windowObject.location.assign(url);
        },

        navigate(url, useLivewire = true, historyNavigation = false) {
            if (this.navigationPromise) return this.navigationPromise;

            const destination = sameOriginUrl(windowObject, url);
            if (!destination) return Promise.reject(new Error('Unsafe navigation destination.'));

            let flushCompleted = false;
            this.navigationPromise = this.flushAll()
                .then(() => {
                    flushCompleted = true;
                    this.resumeNavigation(destination.href, useLivewire, historyNavigation);

                    return true;
                })
                .catch((error) => {
                    if (!flushCompleted) this.reportFlushFailure(error, historyNavigation);

                    throw error;
                })
                .finally(() => {
                    this.navigationPromise = null;
                });

            return this.navigationPromise;
        },

        handleClick(event) {
            const link = this.eligibleLink(event);
            if (!link) return;

            if (this.navigationPromise) {
                event.preventDefault();

                return;
            }
            if (event.defaultPrevented || !this.hasPendingWork()) return;

            event.preventDefault();
            void this.navigate(link.href, link.hasAttribute('wire:navigate')).catch(() => {});
        },

        handleLivewireNavigate(event) {
            const destination = this.destinationFromNavigateEvent(event);
            if (destination && this.consumeResumeTicket(destination)) return;

            if (this.navigationPromise) {
                event.preventDefault();

                return;
            }
            if (event.defaultPrevented || !this.hasPendingWork() || !destination) return;

            event.preventDefault();
            void this.navigate(destination, true, Boolean(event.detail?.history)).catch(() => {});
        },

        handleBeforeUnload(event) {
            if (!this.hasPendingWork()) return undefined;

            event.preventDefault();
            event.returnValue = '';
            void this.flushAll().catch(() => {});

            return '';
        },

        markNavigationComplete() {
            this.stableUrl = String(windowObject.location?.href ?? this.stableUrl);
        },
    };

    coordinator.clickListener = (event) => coordinator.handleClick(event);
    coordinator.navigateListener = (event) => coordinator.handleLivewireNavigate(event);
    coordinator.beforeUnloadListener = (event) => coordinator.handleBeforeUnload(event);
    coordinator.navigatedListener = () => coordinator.markNavigationComplete();

    documentObject.addEventListener('click', coordinator.clickListener, true);
    documentObject.addEventListener('livewire:navigate', coordinator.navigateListener, true);
    documentObject.addEventListener('livewire:navigated', coordinator.navigatedListener);
    windowObject.addEventListener('beforeunload', coordinator.beforeUnloadListener);
    windowObject.addEventListener('pageshow', coordinator.navigatedListener);

    return coordinator;
}

export function ensureRailTimeNavigationCoordinator(
    windowObject = typeof window !== 'undefined' ? window : null,
    documentObject = typeof document !== 'undefined' ? document : null,
) {
    if (!windowObject || !documentObject) return null;

    const existing = windowObject.RailTimeNavigationCoordinator;
    if (existing) return existing;

    const coordinator = createRailTimeNavigationCoordinator(windowObject, documentObject);
    windowObject.RailTimeNavigationCoordinator = coordinator;
    // Compatibility for the existing autosave component and extensions.
    windowObject.RailTimeAutosaveCoordinator = coordinator;

    return coordinator;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    window.ensureRailTimeNavigationCoordinator = () => ensureRailTimeNavigationCoordinator(window, document);
    ensureRailTimeNavigationCoordinator(window, document);
}
