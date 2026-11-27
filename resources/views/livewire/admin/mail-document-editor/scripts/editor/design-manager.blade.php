                    const saveCurrentDraft = async () => {
                        if (!instance) {
                            throw new Error('Der Editor ist noch nicht vollständig geladen.');
                        }

                        // Ein manueller LMZ-Save wartet auch auf einen bereits
                        // laufenden Autosave und wiederholt sich bei Aenderungen
                        // waehrend des Requests bis zu einem stabilen Stand.
                        lastEditorSaveError = null;
                        if (!(await instance.save('manual'))) {
                            const saveError = lastEditorSaveError;
                            lastEditorSaveError = null;

                            throw saveError || new Error('Der Entwurf konnte nicht gespeichert werden.');
                        }

                        lastEditorSaveError = null;
                    };

                    const saveBeforeDesignAction = async () => {
                        if (codeDialog?.open) {
                            throw new Error('Schließe die Codeansicht zuerst mit „Prüfen & als Entwurf speichern“ oder „Abbrechen“.');
                        }
                        if (pendingPortableMedia.length > 0) {
                            throw new Error('Der vorbereitete Medienimport wurde noch nicht übernommen. Bitte schließe den Import zuerst ab.');
                        }

                        const focused = window.document.activeElement;
                        if (focused instanceof HTMLElement && focused !== window.document.body) {
                            focused.blur();
                        }
                        await new Promise((resolve) => window.requestAnimationFrame(resolve));
                        await saveCurrentDraft();
                    };

                    const dispatchConfirmation = ({ title, message, confirmLabel, variant = 'default', action }) => {
                        window.dispatchEvent(new CustomEvent('rt-confirm', {
                            detail: {
                                title,
                                message,
                                confirmLabel,
                                cancelLabel: 'Abbrechen',
                                variant,
                                action,
                            },
                        }));
                    };

                    const bindDesignManager = (attempt = 0) => {
                        const manager = window.document.querySelector('[data-mail-design-manager]');
                        if (!manager) {
                            if (!destroyed && attempt < 90) {
                                window.requestAnimationFrame(() => bindDesignManager(attempt + 1));
                            }
                            return;
                        }

                        const slotFor = (control) => control.closest('[data-mail-design-slot]');
                        const expectedHashFor = (control) => slotFor(control)?.dataset.slotCurrent === 'true'
                            ? document_.contentHash || ''
                            : control.dataset.expectedHash || '';
                        const fail = (error, title) => {
                            const surfaced = showRequestError(error, title);
                            toast('error', surfaced.message, title);
                            return surfaced;
                        };
                        const withBusy = async (callback) => {
                            setActionsBusy(true);
                            manager.setAttribute('aria-busy', 'true');
                            window.dispatchEvent(new CustomEvent('mail-design-manager-busy', { detail: true }));
                            manager.querySelectorAll('button, input').forEach((control) => {
                                control.disabled = true;
                            });
                            try {
                                return await callback();
                            } finally {
                                manager.removeAttribute('aria-busy');
                                manager.querySelectorAll('button, input').forEach((control) => {
                                    if (control.dataset.mailSlotPermanentlyDisabled !== 'true') {
                                        control.disabled = false;
                                    }
                                });
                                window.dispatchEvent(new CustomEvent('mail-design-manager-busy', { detail: false }));
                                setActionsBusy(false);
                            }
                        };

                        manager.addEventListener('submit', async (event) => {
                            const createForm = event.target.closest?.('[data-mail-slot-create-form]');
                            const renameForm = event.target.closest?.('[data-mail-slot-rename-form]');
                            if (!createForm && !renameForm) return;
                            event.preventDefault();

                            try {
                                await withBusy(async () => {
                                    await saveBeforeDesignAction();
                                    const form = createForm || renameForm;
                                    const name = String(form.querySelector('input[name="name"]')?.value || '').trim();
                                    if (!name) throw new Error('Bitte gib einen Namen für den Design-Slot ein.');
                                    const payload = await request(
                                        form.dataset.endpoint,
                                        createForm ? 'POST' : 'PATCH',
                                        {
                                            name,
                                            expected_hash: createForm
                                                ? document_.contentHash || ''
                                                : expectedHashFor(form),
                                        },
                                    );
                                    toast(
                                        'success',
                                        createForm ? 'Der neue Entwurf wurde aus dem aktuellen Design angelegt.' : 'Der Designname wurde gespeichert.',
                                        createForm ? 'Design angelegt' : 'Design umbenannt',
                                    );
                                    window.location.assign(payload.redirect || window.location.href);
                                });
                            } catch (error) {
                                fail(error, createForm ? 'Design konnte nicht angelegt werden' : 'Design konnte nicht umbenannt werden');
                            }
                        }, { signal: controlListeners.signal });

                        manager.addEventListener('click', (event) => {
                            const control = event.target.closest?.(
                                '[data-mail-slot-open], [data-mail-slot-activate], [data-mail-slot-delete], [data-mail-version-restore], [data-mail-version-delete]'
                            );
                            if (!control || control.disabled) return;

                            if (control.matches('[data-mail-slot-open]')) {
                                event.preventDefault();
                                withBusy(async () => {
                                    await saveBeforeDesignAction();
                                    window.location.assign(control.dataset.url);
                                }).catch((error) => fail(error, 'Design konnte nicht geöffnet werden'));
                                return;
                            }

                            if (control.matches('[data-mail-slot-activate]')) {
                                dispatchConfirmation({
                                    title: 'Design aktiv veröffentlichen?',
                                    message: `„${control.dataset.slotName || 'Dieses Design'}“ ersetzt die bisher aktive Fassung für Systemmails. Andere Entwürfe bleiben erhalten.`,
                                    confirmLabel: 'Aktiv veröffentlichen',
                                    action: () => withBusy(async () => {
                                        await saveBeforeDesignAction();
                                        await request(control.dataset.endpoint, 'POST', {
                                            expected_hash: expectedHashFor(control),
                                        });
                                        toast('success', 'Systemmails verwenden jetzt dieses Design.', 'Design aktiviert');
                                        window.location.reload();
                                    }).catch((error) => {
                                        fail(error, 'Design konnte nicht veröffentlicht werden');
                                        throw error;
                                    }),
                                });
                                return;
                            }

                            if (control.matches('[data-mail-slot-delete]')) {
                                dispatchConfirmation({
                                    title: 'Design-Slot löschen?',
                                    message: `„${control.dataset.slotName || 'Dieser Entwurf'}“ und seine Versionshistorie werden gelöscht. Die Outlook-Freigabe und die Outlook-Standardzuordnung einer Vorlage werden automatisch aufgehoben. Aktive Systemmail-Stände bleiben geschützt.`,
                                    confirmLabel: 'Design löschen',
                                    variant: 'destructive',
                                    action: () => withBusy(async () => {
                                        await saveBeforeDesignAction();
                                        const payload = await request(control.dataset.endpoint, 'DELETE', {
                                            expected_hash: expectedHashFor(control),
                                        });
                                        toast('success', 'Der inaktive Design-Slot wurde gelöscht.', 'Design gelöscht');
                                        window.RailTimeMailDocumentEditor?.destroy?.();
                                        window.location.assign(payload.redirect || @js(route('admin.mail-documents.editor')));
                                    }).catch((error) => {
                                        fail(error, 'Design konnte nicht gelöscht werden');
                                        throw error;
                                    }),
                                });
                                return;
                            }

                            if (control.matches('[data-mail-version-delete]')) {
                                dispatchConfirmation({
                                    title: `Version #${control.dataset.revision} löschen?`,
                                    message: 'Der aktuelle Entwurf und die veröffentlichte Fassung bleiben unverändert.',
                                    confirmLabel: 'Version löschen',
                                    variant: 'destructive',
                                    action: () => withBusy(async () => {
                                        await saveBeforeDesignAction();
                                        await request(control.dataset.endpoint, 'DELETE', {
                                            expected_hash: expectedHashFor(control),
                                        });
                                        toast('success', 'Die Historienversion wurde gelöscht.', 'Version gelöscht');
                                        window.location.reload();
                                    }).catch((error) => {
                                        fail(error, 'Version konnte nicht gelöscht werden');
                                        throw error;
                                    }),
                                });
                                return;
                            }

                            dispatchConfirmation({
                                title: `Version #${control.dataset.revision} wiederherstellen?`,
                                message: 'Die Version wird als neuer Entwurf angelegt. Das aktive, veröffentlichte Design bleibt unverändert.',
                                confirmLabel: 'Wiederherstellen',
                                action: () => withBusy(async () => {
                                    await saveBeforeDesignAction();
                                    const payload = await request(control.dataset.endpoint, 'POST', {
                                        expected_hash: expectedHashFor(control),
                                    });
                                    toast('success', 'Die Version wurde als neuer Entwurf wiederhergestellt.', 'Version wiederhergestellt');
                                    if (control.dataset.slotCurrent !== 'true') {
                                        window.location.assign(control.dataset.slotUrl);
                                        return;
                                    }
                                    applyDocumentState(payload.document);
                                    activeBaselineHtml = String(document_.html || '');
                                    await runtimeBridge.rehydrateAuthoritative({
                                        editor: instance.editor,
                                        draft: document_,
                                        sanitizationChanged: true,
                                        parseCss: (canonicalCss) => instance.editor.Parser?.parseCss?.(canonicalCss) || [],
                                        projectOptions: { kind: config.currentDocument, environment: window, imgOverlapProfile: config.imgOverlapProfile || null },
                                    });
                                    window.location.reload();
                                }).catch((error) => {
                                    fail(error, 'Version konnte nicht wiederhergestellt werden');
                                    throw error;
                                }),
                            });
                        }, { signal: controlListeners.signal });
                    };
                    bindDesignManager();

