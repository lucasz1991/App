@props([
    'event' => 'saved',
    'target' => null,
    'dirtyTarget' => null,
    // Rueckwaertskompatibel: Der fruehere globale Statuspunkt konnte
    // absolut positioniert werden. Der Controller ist jetzt bewusst headless.
    'floating' => true,
])

@php
    $dirtyTargets = collect(explode(',', (string) $dirtyTarget))
        ->map(fn ($item) => trim($item))
        ->filter()
        ->values()
        ->all();
    $saveMethod = trim(explode(',', (string) $target)[0] ?? '');
@endphp

<span
    x-data="{
        scope: null,
        targets: {{ \Illuminate\Support\Js::from($dirtyTargets) }},
        saveMethod: @js($saveMethod),
        entries: {},
        revision: 0,
        saving: false,
        pendingSave: null,
        activeSnapshot: [],
        savedAllDuringFlush: false,
        savedFieldsDuringFlush: [],
        timer: null,
        hideTimers: {},
        announcement: '',
        coordinator: null,

        init() {
            this.scope = this.$el.closest('[data-autosave-scope], form, section');
            this.coordinator = this.ensureCoordinator();
            this.coordinator.register(this);
            this.$nextTick(() => this.refreshVisuals());
        },

        destroy() {
            window.clearTimeout(this.timer);
            Object.values(this.hideTimers).forEach(timer => window.clearTimeout(timer));
            this.coordinator?.unregister(this);
        },

        ensureCoordinator() {
            if (window.RailTimeAutosaveCoordinator) {
                return window.RailTimeAutosaveCoordinator;
            }

            const coordinator = {
                controllers: new Set(),
                navigationPromise: null,
                bypassNavigation: false,

                register(controller) {
                    this.controllers.add(controller);
                },

                unregister(controller) {
                    this.controllers.delete(controller);
                },

                pendingControllers() {
                    return Array.from(this.controllers)
                        .filter(controller => controller.hasPendingWork());
                },

                hasPendingWork() {
                    return this.pendingControllers().length > 0;
                },

                flushAll() {
                    return Promise.all(
                        this.pendingControllers().map(controller => controller.flush())
                    );
                },

                eligibleLink(event) {
                    const link = event.target?.closest?.('a[href]');
                    if (!link
                        || link.target === '_blank'
                        || link.hasAttribute('download')
                        || event.metaKey
                        || event.ctrlKey
                        || event.shiftKey
                        || event.altKey
                    ) {
                        return null;
                    }

                    const url = new URL(link.href, window.location.href);
                    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin) {
                        return null;
                    }

                    return link;
                },

                destinationFromNavigateEvent(event) {
                    const destination = event.detail?.url
                        ?? event.detail?.destination?.url
                        ?? event.detail?.to
                        ?? null;

                    return destination ? String(destination) : null;
                },

                navigate(url, useLivewire = true, historyNavigation = false) {
                    if (this.navigationPromise) return this.navigationPromise;

                    this.navigationPromise = this.flushAll()
                        .then(() => {
                            this.bypassNavigation = true;

                            if (historyNavigation) {
                                // Bei Back/Forward ist die URL im History-Stack
                                // bereits gewechselt. Ein Reload setzt genau
                                // dieses Ziel fort, ohne einen Duplikat-Eintrag
                                // durch Livewire.navigate() zu erzeugen.
                                window.location.reload();
                            } else if (useLivewire && window.Livewire?.navigate) {
                                window.Livewire.navigate(url);
                            } else {
                                window.location.assign(url);
                            }

                            window.queueMicrotask(() => {
                                this.bypassNavigation = false;
                            });
                        })
                        .finally(() => {
                            this.navigationPromise = null;
                        });

                    return this.navigationPromise;
                },

                handleClick(event) {
                    if (this.bypassNavigation || !this.hasPendingWork()) return;

                    const link = this.eligibleLink(event);
                    if (!link) return;

                    event.preventDefault();
                    this.navigate(link.href, link.hasAttribute('wire:navigate')).catch(() => {});
                },

                handleLivewireNavigate(event) {
                    if (this.bypassNavigation || !this.hasPendingWork()) return;

                    event.preventDefault();

                    if (this.navigationPromise) return;

                    const destination = this.destinationFromNavigateEvent(event);
                    if (destination) {
                        this.navigate(destination, true, Boolean(event.detail?.history)).catch(() => {});
                    }
                },

                handleBeforeUnload(event) {
                    if (!this.hasPendingWork()) return;

                    // beforeunload kann keine asynchrone Speicherung abwarten.
                    // Der Browser-Dialog blockiert deshalb den harten Reload/Back,
                    // waehrend der Save best-effort bereits angestossen wird.
                    event.preventDefault();
                    event.returnValue = '';
                    this.flushAll().catch(() => {});

                    return '';
                },
            };

            coordinator.clickListener = event => coordinator.handleClick(event);
            coordinator.navigateListener = event => coordinator.handleLivewireNavigate(event);
            coordinator.beforeUnloadListener = event => coordinator.handleBeforeUnload(event);

            document.addEventListener('click', coordinator.clickListener, true);
            document.addEventListener('livewire:navigate', coordinator.navigateListener, true);
            window.addEventListener('beforeunload', coordinator.beforeUnloadListener);
            window.RailTimeAutosaveCoordinator = coordinator;

            return coordinator;
        },

        fieldOwner(field) {
            return field?.closest?.('[data-autosave-model]') ?? null;
        },

        fieldModel(field) {
            const owner = this.fieldOwner(field);
            const explicit = field?.getAttribute?.('data-autosave-model')
                || owner?.getAttribute?.('data-autosave-model');

            if (explicit) return explicit;

            return [
                'wire:model',
                'wire:model.live',
                'wire:model.blur',
                'wire:model.lazy',
            ].map(attribute => field?.getAttribute?.(attribute)).find(Boolean) ?? null;
        },

        fieldId(field, model) {
            const owner = this.fieldOwner(field);

            return field?.getAttribute?.('data-autosave-field-id')
                || owner?.getAttribute?.('data-autosave-field-id')
                || field?.id
                || model;
        },

        matchesModel(model) {
            return Boolean(model) && this.targets.some(target =>
                model === target || model.startsWith(`${target}.`)
            );
        },

        entryFromTarget(target) {
            if (!target || !this.scope?.contains(target)) return null;

            const owner = this.fieldOwner(target);
            const instant = (target.getAttribute?.('data-autosave-instant')
                || owner?.getAttribute?.('data-autosave-instant')) === 'true';
            const model = this.fieldModel(target);

            if (!model || (!instant && !this.matchesModel(model))) return null;

            const fieldId = String(this.fieldId(target, model));

            return {
                key: `${model}::${fieldId}`,
                model,
                fieldId,
                instant,
            };
        },

        recordFor(candidate) {
            if (!this.entries[candidate.key]) {
                this.entries[candidate.key] = {
                    key: candidate.key,
                    model: candidate.model,
                    fieldId: candidate.fieldId,
                    revision: 0,
                    state: 'idle',
                };
            }

            return this.entries[candidate.key];
        },

        visualTargets(entry) {
            if (!this.scope) return [];

            const matches = Array.from(this.scope.querySelectorAll('[data-autosave-field]'))
                .filter(element =>
                    element.getAttribute('data-autosave-model') === entry.model
                    && element.getAttribute('data-autosave-field-id') === entry.fieldId
                );

            // Gemeinsame Inputs koennen in einem stabilen Inline-Wrapper liegen.
            // Dann zeichnet nur der aeusserste passende Wrapper den Ring.
            return matches.filter(element =>
                !matches.some(candidate => candidate !== element && candidate.contains(element))
            );
        },

        applyVisualState(entry) {
            this.visualTargets(entry).forEach(element => {
                element.setAttribute('data-autosave-state', entry.state);

                if (entry.state === 'saving') {
                    element.setAttribute('aria-busy', 'true');
                } else {
                    element.removeAttribute('aria-busy');
                }
            });
        },

        refreshVisuals() {
            Object.values(this.entries).forEach(entry => this.applyVisualState(entry));
        },

        clearHideTimer(key) {
            window.clearTimeout(this.hideTimers[key]);
            delete this.hideTimers[key];
        },

        setState(entry, state) {
            entry.state = state;
            this.applyVisualState(entry);
        },

        markDirtyTarget(target) {
            const candidate = this.entryFromTarget(target);
            if (!candidate) return null;

            const entry = this.recordFor(candidate);
            this.clearHideTimer(entry.key);
            this.revision += 1;
            entry.revision = this.revision;
            this.setState(entry, 'dirty');
            this.announcement = @js(__('app.unsaved_changes'));

            if (!candidate.instant) this.schedule();

            return entry;
        },

        markDirty(event) {
            this.markDirtyTarget(event.target);
        },

        markInstantAction(event) {
            const action = event.target?.closest?.('[data-autosave-action-model]');
            if (!action || !this.scope?.contains(action)) return false;

            this.markDirtyTarget(action);

            return true;
        },

        dirtyEntries() {
            return Object.values(this.entries).filter(entry => entry.state === 'dirty');
        },

        hasDirtyEntries() {
            return this.dirtyEntries().length > 0;
        },

        hasPendingWork() {
            return this.hasDirtyEntries() || this.saving;
        },

        schedule() {
            if (!this.hasDirtyEntries() || !this.saveMethod) return;

            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.flush().catch(() => {}), 3000);
        },

        continueInteraction(event) {
            const candidate = this.entryFromTarget(event.target);
            if (!candidate || !this.entries[candidate.key] || this.entries[candidate.key].state !== 'dirty') return;

            this.schedule();
        },

        shouldFlushForTarget(target) {
            if (!this.hasDirtyEntries()) return false;
            if (!this.scope?.contains(target)) return true;

            const candidate = this.entryFromTarget(target);
            if (!candidate) return true;

            return this.dirtyEntries().some(entry => entry.key !== candidate.key);
        },

        handlePointerDown(event) {
            if (this.markInstantAction(event)) return;

            if (this.shouldFlushForTarget(event.target)) {
                this.flush().catch(() => {});
            } else {
                this.continueInteraction(event);
            }
        },

        handleFocusIn(event) {
            if (this.shouldFlushForTarget(event.target)) {
                this.flush().catch(() => {});
            } else {
                this.continueInteraction(event);
            }
        },

        handleFocusOut(event) {
            const previous = this.entryFromTarget(event.target);
            if (!previous || this.entries[previous.key]?.state !== 'dirty') return;

            window.setTimeout(() => {
                const next = this.entryFromTarget(document.activeElement);
                if (!next || next.key !== previous.key) {
                    this.flush().catch(() => {});
                }
            }, 0);
        },

        requestFlush(event) {
            if (event.detail?.scope !== this.scope) return;
            event.detail.promise = this.flush();
        },

        requestReset(event) {
            const detail = event.detail ?? {};
            if (detail.scope && detail.scope !== this.scope) return;

            const model = String(detail.model ?? '');
            const fieldId = String(detail.fieldId ?? '');
            if (!model || !fieldId) return;

            const key = `${model}::${fieldId}`;
            const entry = this.entries[key];
            if (!entry) return;

            window.clearTimeout(this.timer);
            this.clearHideTimer(key);
            this.revision += 1;
            entry.revision = this.revision;
            this.setState(entry, 'idle');

            if (this.hasDirtyEntries()) {
                this.announcement = @js(__('app.unsaved_changes'));
                this.schedule();
            } else if (!this.saving) {
                this.announcement = '';
            }
        },

        eventFields(event) {
            const original = event?.detail ?? {};
            const detail = Array.isArray(original) ? (original[0] ?? {}) : original;
            const fields = [];

            if (Array.isArray(detail.savedFields)) fields.push(...detail.savedFields);
            if (Array.isArray(detail.fields)) fields.push(...detail.fields);
            if (detail.field !== undefined && detail.field !== null) fields.push(detail.field);
            if (Array.isArray(detail.noteIds)) {
                fields.push(...detail.noteIds.map(noteId => `noteBodies.${noteId}`));
            }
            if (detail.noteId !== undefined && detail.noteId !== null) {
                fields.push(`noteBodies.${detail.noteId}`);
            }

            return [...new Set(fields.map(field => String(field)).filter(Boolean))];
        },

        modelMatchesEventField(model, field) {
            return model === field
                || model.startsWith(`${field}.`)
                || model.endsWith(`.${field}`);
        },

        snapshotMatchesFields(snapshotEntry, fields) {
            return fields.length === 0
                || fields.some(field => this.modelMatchesEventField(snapshotEntry.model, field));
        },

        restoreSnapshot(snapshot, completedKeys = []) {
            const completed = new Set(completedKeys);

            snapshot.forEach(savedEntry => {
                if (completed.has(savedEntry.key)) return;

                const current = this.entries[savedEntry.key];
                if (!current || current.revision !== savedEntry.revision) return;

                this.setState(current, 'dirty');
            });

            if (this.hasDirtyEntries()) {
                this.announcement = @js(__('app.unsaved_changes'));
            }
        },

        completeSnapshot(snapshot, fields = []) {
            const candidates = snapshot.filter(entry => this.snapshotMatchesFields(entry, fields));
            const completedKeys = [];
            let needsAnotherSave = false;

            candidates.forEach(savedEntry => {
                const current = this.entries[savedEntry.key];
                if (!current) return;

                if (current.revision !== savedEntry.revision) {
                    // Ein explizit abgebrochenes Inline-Edit bleibt neutral.
                    // Eine echte Folgeaenderung waehrend des Requests bleibt dirty.
                    if (current.state !== 'idle') {
                        this.setState(current, 'dirty');
                        needsAnotherSave = true;
                    }
                    return;
                }

                completedKeys.push(current.key);
                this.setState(current, 'saved');
                this.clearHideTimer(current.key);

                const savedRevision = current.revision;
                this.hideTimers[current.key] = window.setTimeout(() => {
                    const latest = this.entries[current.key];
                    if (!latest || latest.revision !== savedRevision || latest.state !== 'saved') return;

                    this.setState(latest, 'idle');
                    delete this.hideTimers[current.key];

                    if (!Object.values(this.entries).some(entry => entry.state === 'saved')) {
                        this.announcement = '';
                    }
                }, 1350);
            });

            this.restoreSnapshot(snapshot, completedKeys);

            if (completedKeys.length > 0) {
                this.announcement = @js(__('app.saved_automatically'));
                window.RTSound?.play('success');
            }

            if (needsAnotherSave) this.schedule();

            return completedKeys;
        },

        handleSavedFeedback(event) {
            const fields = this.eventFields(event);

            if (this.saving) {
                if (fields.length === 0) {
                    this.savedAllDuringFlush = true;
                } else {
                    const activeFields = fields.filter(field =>
                        this.activeSnapshot.some(entry =>
                            this.modelMatchesEventField(entry.model, field)
                        )
                    );
                    const externalFields = fields.filter(field =>
                        !activeFields.includes(field)
                    );

                    if (activeFields.length > 0) {
                        this.savedFieldsDuringFlush = [...new Set([
                            ...this.savedFieldsDuringFlush,
                            ...activeFields,
                        ])];
                    }

                    if (externalFields.length > 0) {
                        const activeKeys = new Set(this.activeSnapshot.map(entry => entry.key));
                        const externalSnapshot = Object.values(this.entries)
                            .filter(entry => !activeKeys.has(entry.key))
                            .filter(entry => ['dirty', 'saving'].includes(entry.state))
                            .filter(entry => this.snapshotMatchesFields(entry, externalFields))
                            .map(entry => ({ ...entry }));

                        if (externalSnapshot.length > 0) {
                            this.completeSnapshot(externalSnapshot, externalFields);
                        }
                    }
                }

                return;
            }

            const pending = Object.values(this.entries)
                .filter(entry => ['dirty', 'saving'].includes(entry.state))
                .filter(entry => this.snapshotMatchesFields(entry, fields))
                .map(entry => ({ ...entry }));

            if (pending.length > 0) this.completeSnapshot(pending, fields);
        },

        flush() {
            if (this.saving) {
                return this.pendingSave.then(() => this.hasDirtyEntries() ? this.flush() : true);
            }

            const snapshot = this.dirtyEntries().map(entry => ({ ...entry }));
            if (snapshot.length === 0 || !this.saveMethod) return Promise.resolve(true);

            window.clearTimeout(this.timer);
            this.saving = true;
            this.activeSnapshot = snapshot;
            this.savedAllDuringFlush = false;
            this.savedFieldsDuringFlush = [];
            this.announcement = @js(__('app.saving_changes'));

            snapshot.forEach(savedEntry => {
                const current = this.entries[savedEntry.key];
                if (current?.revision === savedEntry.revision) this.setState(current, 'saving');
            });

            this.pendingSave = this.$wire.call(this.saveMethod)
                .then(result => {
                    let completedKeys = [];

                    if (this.savedAllDuringFlush || this.savedFieldsDuringFlush.length > 0) {
                        completedKeys = this.completeSnapshot(
                            snapshot,
                            this.savedAllDuringFlush ? [] : this.savedFieldsDuringFlush,
                        );
                    }

                    if (completedKeys.length === 0) this.restoreSnapshot(snapshot);

                    return result;
                })
                .catch(error => {
                    this.restoreSnapshot(snapshot);
                    throw error;
                })
                .finally(() => {
                    this.saving = false;
                    this.activeSnapshot = [];
                    this.pendingSave = null;
                    this.savedAllDuringFlush = false;
                    this.savedFieldsDuringFlush = [];
                });

            return this.pendingSave;
        },

    }"
    x-on:input.window="markDirty($event)"
    x-on:change.window="markDirty($event)"
    x-on:keydown.window="continueInteraction($event)"
    x-on:focusin.window="handleFocusIn($event)"
    x-on:focusout.window="handleFocusOut($event)"
    x-on:pointerdown.window.capture="handlePointerDown($event)"
    x-on:rt-autosave-flush.window="requestFlush($event)"
    x-on:rt-autosave-reset.window="requestReset($event)"
    x-on:{{ $event }}.window="handleSavedFeedback($event)"
    class="sr-only"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    data-autosave-controller
    data-autosave-status="{{ $event }}"
    x-text="announcement"
></span>
