export function shiftDetailDrawer() {
    return {
        detailVisible: false,
        loading: false,
        error: '',
        requestedShiftId: null,
        requestVersion: 0,
        pendingShiftId: null,
        inFlight: false,
        requestPromise: null,

        init() {
            this.detailVisible = Boolean(this.$wire.detailOpen);
            this.$watch('detailVisible', (visible) => {
                if (!visible) this.closeShiftDetail();
            });
            this.$watch('$wire.detailOpen', (open) => {
                if (!open && !this.inFlight) this.closeShiftDetail();
            });
        },

        openShiftDetail(rawId) {
            const id = Number(rawId);
            if (!Number.isSafeInteger(id) || id <= 0) return;

            this.requestVersion += 1;
            this.requestedShiftId = id;
            this.pendingShiftId = id;
            this.error = '';
            this.loading = true;
            this.detailVisible = true;

            if (!this.inFlight) this.requestPromise = this.loadPendingDetails();
            return this.requestPromise;
        },

        closeShiftDetail() {
            if (this.detailVisible || this.loading || this.pendingShiftId || this.error) {
                this.requestVersion += 1;
            }
            this.detailVisible = false;
            this.pendingShiftId = null;
            this.loading = false;
            this.error = '';
            this.$wire.detailOpen = false;
        },

        async loadPendingDetails() {
            this.inFlight = true;
            try {
                // Serialize selections so a slower response cannot replace newer details.
                while (this.detailVisible && this.pendingShiftId !== null) {
                    const id = this.pendingShiftId;
                    const version = this.requestVersion;
                    this.pendingShiftId = null;
                    try {
                        await this.requestShiftDetails(id);
                        if (this.detailVisible && version === this.requestVersion) {
                            this.loading = false;
                        }
                    } catch {
                        if (this.detailVisible && version === this.requestVersion) {
                            this.loading = false;
                            this.error = 'Die Schichtdetails konnten nicht geladen werden. Bitte versuche es erneut.';
                        }
                    }
                }
            } finally {
                this.inFlight = false;
                if (!this.detailVisible) this.$wire.detailOpen = false;
            }
        },

        requestShiftDetails(id) {
            const cleanups = [];
            return new Promise((resolve, reject) => {
                // Livewire 3 resolves method promises on success only; its commit hook reports failures.
                if (typeof this.$wire.$hook === 'function') {
                    cleanups.push(this.$wire.$hook('commit', ({ commit, fail }) => {
                        if (commit.calls.some((call) => call.method === 'openDetails' && Number(call.params[0]) === id)) {
                            fail(() => reject(new Error('Shift detail request failed')));
                        }
                    }));
                    cleanups.push(this.$wire.$hook('request', ({ payload, fail }) => {
                        const components = JSON.parse(payload).components;
                        if (components.length === 1 && JSON.parse(components[0].snapshot).memo.id === this.$wire.$id
                            && components[0].calls.length > 0
                            && components[0].calls.every((call) => call.method === 'openDetails' && Number(call.params[0]) === id)) {
                            fail(({ status, preventDefault }) => {
                                if (status !== 419) preventDefault();
                            });
                        }
                    }));
                }
                Promise.resolve(this.$wire.openDetails(id)).then(resolve, reject);
            }).finally(() => cleanups.forEach((cleanup) => cleanup()));
        },
    };
}
