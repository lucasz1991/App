export function workClockActivities(labels) {
    return {
        labels, search: '', correcting: false, openedEntry: null, openedRevision: null,
        get quickKinds() {
            const source = this.active?.suggested_kinds || [];
            return source.filter(kind => this.labels[kind] && kind !== this.active?.kind).slice(0, 3);
        },
        get recentKinds() {
            return (this.active?.recent_kinds || []).filter(kind => this.labels[kind]
                && kind !== this.active?.kind && !this.quickKinds.includes(kind)).slice(0, 3);
        },
        get groups() {
            const kinds = Object.keys(this.labels);
            const query = this.search.trim().toLocaleLowerCase('de');
            if (query) {
                return [{ title: 'Suchergebnisse', kinds: kinds.filter(kind => this.labels[kind].toLocaleLowerCase('de').includes(query)) }];
            }
            const suggested = this.quickKinds;
            const recent = this.recentKinds;
            return [
                { title: 'Passend zum Dienst', kinds: suggested },
                { title: 'Zuletzt verwendet', kinds: recent },
                { title: 'Weitere Tätigkeiten', kinds: kinds.filter(kind => !suggested.includes(kind) && !recent.includes(kind)) },
            ].filter(group => group.kinds.length);
        },
        get activityTime() {
            if (!this.active?.activity_since) return '';
            return new Date(this.active.activity_since).toLocaleTimeString('de-DE', {
                hour: '2-digit', minute: '2-digit', timeZone: this.active.timezone,
            });
        },
        openActivities(correction = false) {
            if (this.active?.status !== 'running') return;
            this.search = '';
            this.correcting = correction;
            this.openedEntry = this.active.capture_id;
            this.openedRevision = this.active.revision;
            this.$refs.activityDialog.showModal();
            this.$nextTick(() => {
                if (matchMedia('(min-width: 700px)').matches) this.$refs.activitySearch.focus();
            });
        },
        async chooseActivity(kind) {
            if (!this.labels[kind] || this.busy || !this.ready || this.conflicts || this.active?.status !== 'running') return;
            const dialog = this.$refs.activityDialog;
            const trigger = dialog.open && this.correcting ? this.$refs.activityCorrection : this.$refs.activityPicker;
            if (dialog.open && (this.openedEntry !== this.active.capture_id || this.openedRevision !== this.active.revision)) {
                dialog.close();
                this.error = 'Die Arbeitsuhr hat sich geändert. Bitte die Tätigkeit erneut auswählen.';
                return;
            }
            if (kind !== this.active.kind) {
                await this.capture({ action: 'activity', kind, entry_capture_id: this.active.capture_id, revision: this.active.revision,
                    ...(dialog.open && this.correcting ? { correct_activity: true } : {}) });
            }
            if (dialog.open) dialog.close();
            this.$nextTick(() => {
                const page = dialog.ownerDocument;
                if (page.activeElement !== page.body || this.active?.status !== 'running') return;
                const target = trigger && !trigger.disabled && trigger.getClientRects().length ? trigger : this.$refs.activityCurrent;
                target.focus({ preventScroll: true });
            });
        },
        moveFocus(direction) {
            const options = [...this.$refs.activityDialog.querySelectorAll('[data-activity-choice]:not(:disabled)')];
            const position = options.indexOf(document.activeElement) + direction;
            if (position < 0) this.$refs.activitySearch.focus();
            else options[Math.min(position, options.length - 1)]?.focus();
        },
    };
}
