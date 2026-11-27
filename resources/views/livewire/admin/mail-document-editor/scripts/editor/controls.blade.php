                    // Anchor-Dropdowns teleportieren ihren Inhalt an den
                    // Dokument-Body. Event-Delegation am Modal allein wuerde
                    // deshalb die neuen Builderbefehle im Dropdown nicht
                    // erreichen. Der AbortController begrenzt den Listener
                    // exakt auf diese Livewire-Editorinstanz.
                    window.document.addEventListener('click', (event) => {
                        const control = event.target?.closest?.('[data-mail-builder-action], [data-mail-builder-panel]');
                        if (!control
                            || control.hidden
                            || control.disabled
                            || control.getAttribute('aria-disabled') === 'true') return;

                        const action = control.dataset.mailBuilderAction;
                        if (action) {
                            if (!instance) {
                                setMessage('Der Editor wird noch geladen …');
                                return;
                            }
                            instance.runEditorAction?.(action);
                            return;
                        }

                        const panel = control.dataset.mailBuilderPanel;
                        if (panel && instance && !instance.openEditorPanel?.(panel)) {
                            setMessage('Diese Einstellung ist für die aktuelle Auswahl nicht verfügbar.');
                        }
                    }, { signal: controlListeners.signal });

                    // Delegation erreicht auch einen erst spaeter teleportierten
                    // Inhalt. Fremde Dokument-/Dropdown-Steuerungen bleiben aus.
                    window.document.addEventListener('click', (event) => {
                        const control = viewEventControl(event, '[data-mail-view-mode], [data-mail-theme-button], [data-mail-preview-device], [data-mail-preview-replay]');
                        if (!control) return;
                        if (control.hasAttribute('data-mail-view-mode')) {
                            void selectViewMode(control.dataset.mailViewMode);
                        } else if (control.hasAttribute('data-mail-theme-button')) {
                            selectTheme(control.dataset.mailThemeButton);
                        } else if (control.hasAttribute('data-mail-preview-device')) {
                            selectDevice(control.dataset.mailPreviewDevice);
                        } else {
                            const restarted = Number(instance?.restartAllGifs?.() || 0);
                            setMessage(restarted > 0
                                ? `${restarted} Animation${restarted === 1 ? '' : 'en'} neu gestartet.`
                                : 'In der aktuellen Vorschau wurde keine GIF-Animation gefunden.');
                        }
                    }, { signal: controlListeners.signal });

                    studioRoot.querySelector('[data-mail-view-shortcut]')?.addEventListener('click', () => {
                        void selectViewMode(selectedViewMode === 'edit' ? 'delivery' : 'edit');
                    }, { signal: controlListeners.signal });

                    window.document.addEventListener('input', (event) => {
                        const control = viewEventControl(event, '[data-mail-preview-width]');
                        if (control && control.value.trim() !== '') selectPreviewWidth(control.value);
                    }, { signal: controlListeners.signal });

                    window.document.addEventListener('change', (event) => {
                        const control = viewEventControl(event, '[data-mail-degradation-mode]');
                        if (!control) return;
                        try {
                            selectDegradationMode(control.value);
                        } catch (error) {
                            selectDegradationMode('normal');
                            const surfaced = showRequestError(error, 'Robustheitsvorschau nicht verfügbar');
                            toast('error', surfaced.message, 'Vorschau nicht verfügbar');
                        }
                    }, { signal: controlListeners.signal });

                    window.document.addEventListener('dropdown-open', (event) => {
                        const menu = event.target?.closest?.('[data-mail-toolbar-menu="view"]');
                        if (!menu || !studioRoot.contains(menu)) return;
                        window.requestAnimationFrame(() => {
                            if (destroyed) return;
                            queryViewControls('[data-mail-view-mode]').forEach((button) => {
                                button.setAttribute('aria-pressed', String(button.dataset.mailViewMode === selectedViewMode));
                            });
                            queryViewControls('[data-mail-theme-button]').forEach((button) => {
                                button.disabled = selectedViewMode !== 'edit';
                                button.setAttribute('aria-pressed', String(button.dataset.mailThemeButton === selectedTheme));
                            });
                            queryViewControl('[data-mail-theme-controls]')?.setAttribute('aria-disabled', String(selectedViewMode !== 'edit'));
                            const degradationSelect = queryViewControl('[data-mail-degradation-mode]');
                            if (degradationSelect) {
                                degradationSelect.disabled = selectedViewMode === 'forward';
                                degradationSelect.value = selectedDegradationMode;
                            }
                            updatePreviewStatus(instance?.getPreviewGeometry?.());
                        });
                    }, { signal: controlListeners.signal });

                    const finishResizeGesture = (event = null) => {
                        if (!resizeGesture) return;
                        if (event?.pointerId !== undefined && event.pointerId !== resizeGesture.pointerId) return;
                        if (previewResizer?.hasPointerCapture?.(resizeGesture.pointerId)) {
                            previewResizer.releasePointerCapture(resizeGesture.pointerId);
                        }
                        resizeGesture = null;
                        editorFrame?.removeAttribute('data-mail-resizing');
                        syncPreviewResizer();
                    };

                    previewResizer?.addEventListener('pointerdown', (event) => {
                        if (event.button !== 0 || !instance) return;
                        event.preventDefault();
                        const startWidth = prepareCustomViewport();
                        selectPreviewWidth(startWidth, { prepare: false });
                        resizeGesture = {
                            pointerId: event.pointerId,
                            startX: event.clientX,
                            startWidth,
                        };
                        previewResizer.setPointerCapture?.(event.pointerId);
                        editorFrame?.setAttribute('data-mail-resizing', 'true');
                    }, { signal: controlListeners.signal });

                    previewResizer?.addEventListener('pointermove', (event) => {
                        if (!resizeGesture || event.pointerId !== resizeGesture.pointerId) return;
                        event.preventDefault();
                        selectPreviewWidth(
                            resizeGesture.startWidth + (event.clientX - resizeGesture.startX),
                            { prepare: false },
                        );
                    }, { signal: controlListeners.signal });

                    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((eventName) => {
                        previewResizer?.addEventListener(eventName, finishResizeGesture, {
                            signal: controlListeners.signal,
                        });
                    });

                    previewResizer?.addEventListener('keydown', (event) => {
                        const geometry = instance?.getPreviewGeometry?.() || latestPreviewGeometry;
                        let nextWidth = geometry?.device === 'custom'
                            ? Math.round(geometry.logicalWidth)
                            : prepareCustomViewport();
                        const step = event.shiftKey ? 10 : 1;
                        if (event.key === 'ArrowLeft') nextWidth -= step;
                        else if (event.key === 'ArrowRight') nextWidth += step;
                        else if (event.key === 'Home') nextWidth = 320;
                        else if (event.key === 'End') nextWidth = 1920;
                        else return;

                        event.preventDefault();
                        selectPreviewWidth(nextWidth, { prepare: false });
                    }, { signal: controlListeners.signal });

