                    let instance = null;
                    let editorBootState = 'loading';
                    let destroyed = false;
                    let selectedTheme = 'light';
                    let selectedDevice = 'wide';
                    // Zuerst die tatsaechlich kompilierte Quelle zeigen.
                    // Die editierbare Autorenansicht bleibt explizit erreichbar.
                    let selectedViewMode = 'delivery';
                    let selectedDegradationMode = 'normal';
                    let actionsBusy = false;
                    let compatibilityBlocksPublication = false;
                    let unregisterNavigation = null;
                    let lastEditorSaveError = null;
                    let activeBaselineHtml = String(document_.html || '');
                    let codeDialogOpener = null;
                    let pendingPortableMedia = [];
                    let latestPreviewGeometry = null;
                    let compiledDeliveryHtml = '';
                    let deliveryPreviewRequest = null;
                    let deliveryPreviewGeneration = 0;
                    let forwardPreviewRestore = null;
                    let previewResizeFrame = null;
                    let resizeGesture = null;
                    let detachBuilderToolbarContext = null;
                    const controlListeners = new AbortController();
                    const MAIL_SOURCE_FORMAT = 'railtime-mail-document';
                    const MAIL_SOURCE_VERSION = 2;
                    const MAX_SOURCE_BYTES = 1024 * 1024;
                    const MAX_BUNDLE_BYTES = 16 * 1024 * 1024;
                    const MAX_MEDIA_BYTES = 2 * 1024 * 1024;
                    const toolsPanelId = `rt-dropdown-mail-document-tools-${config.currentDocument}-content`;
                    const queryToolControl = (selector) => window.document
                        .getElementById(toolsPanelId)
                        ?.querySelector(selector) || null;
                    const bindTeleportedControl = (queryControl, selector, listener, attempt = 0) => {
                        const control = queryControl(selector);
                        if (control) {
                            control.addEventListener('click', listener, { signal: controlListeners.signal });
                            return;
                        }

                        // anchor-dropdown teleportiert seinen Inhalt nach body.
                        // Das Livewire-Skript kann einen Frame vor Alpine laufen;
                        // deshalb wird nur die Bindung kurz nachgeholt, niemals
                        // eine zweite Editorinstanz erzeugt.
                        if (!destroyed && attempt < 60) {
                            window.requestAnimationFrame(() => bindTeleportedControl(
                                queryControl,
                                selector,
                                listener,
                                attempt + 1,
                            ));
                        }
                    };
                    const bindToolControl = (selector, listener) => bindTeleportedControl(
                        queryToolControl,
                        selector,
                        listener,
                    );

                    const toast = (type, text, title) => window.dispatchEvent(new CustomEvent('swal:toast', {
                        detail: { type, text, title: title || undefined },
                    }));

                    const setMessage = (text) => {
                        if (messageNode) messageNode.textContent = text;
                    };
                    if (document_.autoRepaired) {
                        setMessage('Ein bekannter Signatur-Altstand wurde für den Editor sicher repariert. Beim nächsten Speichern wird Schema 28 übernommen.');
                    }

                    const setActionsBusy = (busy) => {
                        actionsBusy = Boolean(busy);
                        const testMailButton = queryToolControl('[data-mail-document-test-mail]');
                        [
                            saveButton,
                            publishButton,
                            codeApplyButton,
                            queryToolControl('[data-mail-code-open]'),
                            queryToolControl('[data-mail-code-export]'),
                            queryToolControl('[data-mail-code-import]'),
                            testMailButton,
                            ...codeCancelButtons,
                        ].forEach((button) => {
                            if (!button) return;

                            button.disabled = actionsBusy
                                || ([publishButton, testMailButton].includes(button) && compatibilityBlocksPublication);
                            button.setAttribute('aria-busy', String(actionsBusy));
                        });
                    };

                    const navigationCoordinator = window.ensureRailTimeNavigationCoordinator?.()
                        || window.RailTimeNavigationCoordinator
                        || null;
                    const navigationController = runtimeBridge.createNavigationController?.({
                        getBuilder: () => instance,
                        onSaving: () => setMessage('Offene Änderungen werden vor dem Seitenwechsel gespeichert …'),
                        onSaved: () => setMessage('Gespeichert. Seitenwechsel wird fortgesetzt …'),
                        onFlushError: (error) => {
                            const message = error?.message || 'Offene Änderungen konnten nicht gespeichert werden.';
                            setMessage(message);
                            toast('error', message, 'Seitenwechsel angehalten');
                        },
                    });
                    if (navigationController) {
                        unregisterNavigation = navigationCoordinator?.register?.(navigationController) || null;
                    }

