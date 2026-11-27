                    const syncPreviewResizer = (geometry = latestPreviewGeometry) => {
                        if (previewResizeFrame !== null) window.cancelAnimationFrame(previewResizeFrame);
                        previewResizeFrame = window.requestAnimationFrame(() => {
                            previewResizeFrame = null;
                            if (!previewResizer || !editorFrame || !geometry) return;

                            const target = selectedViewMode !== 'edit'
                                ? deliveryFrame
                                : instance?.editor?.Canvas?.getFrameEl?.();
                            const targetRect = target?.getBoundingClientRect?.();
                            const hostRect = editorFrame.getBoundingClientRect?.();
                            if (!targetRect || !hostRect || targetRect.width <= 0 || targetRect.height <= 0) {
                                previewResizer.hidden = true;
                                return;
                            }

                            const left = Math.max(0, Math.min(hostRect.width, targetRect.right - hostRect.left));
                            const top = Math.max(0, targetRect.top - hostRect.top);
                            const height = Math.max(44, Math.min(targetRect.height, hostRect.height - top));
                            editorFrame.style.setProperty('--rt-mail-resizer-left', `${left}px`);
                            editorFrame.style.setProperty('--rt-mail-resizer-top', `${top}px`);
                            editorFrame.style.setProperty('--rt-mail-resizer-height', `${height}px`);
                            previewResizer.hidden = false;
                        });
                    };

                    const updatePreviewStatus = (geometry = null) => {
                        if (geometry) latestPreviewGeometry = geometry;
                        const activeGeometry = geometry || latestPreviewGeometry;
                        const logicalWidth = Math.round(Number(activeGeometry?.logicalWidth) || 1920);
                        const activeDevice = activeGeometry?.device || selectedDevice;
                        if (activeDevice === 'custom') selectedDevice = 'custom';

                        queryViewControls('[data-mail-preview-device]').forEach((button) => {
                            button.setAttribute('aria-pressed', String(
                                activeDevice !== 'custom'
                                && button.dataset.mailPreviewDevice === activeDevice
                            ));
                        });
                        const previewWidthInput = queryViewControl('[data-mail-preview-width]');
                        if (previewWidthInput && window.document.activeElement !== previewWidthInput) {
                            previewWidthInput.value = String(logicalWidth);
                        }
                        if (previewResizer) {
                            previewResizer.setAttribute('aria-valuenow', String(logicalWidth));
                            previewResizer.setAttribute('aria-valuetext', `${logicalWidth} Pixel`);
                        }

                        const previewStatus = queryViewControl('[data-mail-preview-status]');
                        if (previewStatus) {
                            const labels = {
                                wide: 'Systemmail breit',
                                desktop: 'Desktop',
                                tablet: 'Tablet',
                                mobile: 'Mobil',
                                custom: 'Individuell',
                            };
                            const degradationLabels = {
                                'forward': 'Weiterleitung ohne Head-CSS',
                                'images-off': 'Bilder aus',
                                'head-css-off': 'Head-CSS aus',
                                'css-off': 'Gesamtes CSS aus',
                            };
                            const scale = activeGeometry?.scale && activeGeometry.scale < 0.999
                                ? ` · Fit ${Math.round(activeGeometry.scale * 100)} %`
                                : ' · 100 %';
                            const effectiveDegradationMode = selectedViewMode === 'forward'
                                ? 'forward'
                                : selectedDegradationMode;
                            const degradationDisclaimer = effectiveDegradationMode === 'forward'
                                ? 'Weiterleitungs-Robustheitsvorschau, keine iPhone- oder Mailclient-Emulation'
                                : 'Robustheitsvorschau, keine Mailclient-Emulation';
                            const degradation = effectiveDegradationMode === 'normal'
                                ? ''
                                : ` · ${degradationLabels[effectiveDegradationMode]} · ${degradationDisclaimer}`;
                            const rendering = selectedViewMode === 'forward'
                                ? 'Kompilierte Weiterleitungsbasis im Browser'
                                : (selectedViewMode === 'delivery'
                                    ? 'Versandvorschau · aktueller Entwurf nach CSS-Inliner'
                                    : `Autorenansicht · ${labels[activeDevice] || 'Editor'} · keine Client-Emulation`);
                            previewStatus.textContent = `${rendering} · ${logicalWidth} px${scale}${degradation}`;
                        }

                        syncPreviewResizer(activeGeometry);
                    };

                    const selectTheme = (theme) => {
                        selectedTheme = theme === 'dark' ? 'dark' : 'light';
                        editorFrame?.setAttribute('data-preview-theme', selectedTheme);
                        queryViewControls('[data-mail-theme-button]').forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailThemeButton === selectedTheme));
                        });
                        instance?.setTheme?.(selectedTheme);
                        if (selectedDegradationMode !== 'normal' && selectedViewMode === 'edit') {
                            instance?.setDegradationMode?.(selectedDegradationMode);
                        }
                    };

                    const prepareCustomViewport = () => {
                        const canvasFrame = instance?.editor?.Canvas?.getFrameEl?.();
                        const main = editorFrame?.querySelector?.('.lmz-builder__main');
                        const canvasRect = canvasFrame?.getBoundingClientRect?.();
                        const mainRect = main?.getBoundingClientRect?.();
                        if (canvasRect && mainRect) {
                            editorFrame.style.setProperty(
                                '--rt-mail-custom-left',
                                `${Math.max(0, canvasRect.left - mainRect.left)}px`,
                            );
                        }

                        const geometry = instance?.getPreviewGeometry?.() || latestPreviewGeometry;
                        return geometry?.device === 'custom'
                            ? Math.round(geometry.logicalWidth)
                            : Math.round(geometry?.displayWidth || geometry?.logicalWidth || 1024);
                    };

                    const selectPreviewWidth = (width, { prepare = true } = {}) => {
                        if (!instance) return null;
                        if (prepare && selectedDevice !== 'custom') prepareCustomViewport();
                        selectedDevice = 'custom';
                        const normalized = Math.min(1920, Math.max(320, Math.round(Number(width) || 1024)));
                        instance.setPreviewWidth?.(normalized);
                        if (selectedDegradationMode !== 'normal' && selectedViewMode === 'edit') {
                            instance.setDegradationMode?.(selectedDegradationMode);
                        }
                        updatePreviewStatus(instance.getPreviewGeometry?.());
                        return normalized;
                    };

                    const selectDevice = (device) => {
                        selectedDevice = ['wide', 'desktop', 'tablet', 'mobile'].includes(device) ? device : 'wide';
                        editorFrame?.style.removeProperty('--rt-mail-custom-left');
                        queryViewControls('[data-mail-preview-device]').forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailPreviewDevice === selectedDevice));
                        });
                        instance?.setPreviewDevice?.(selectedDevice);
                        if (selectedDegradationMode !== 'normal' && selectedViewMode === 'edit') {
                            instance?.setDegradationMode?.(selectedDegradationMode);
                        }
                        updatePreviewStatus(instance?.getPreviewGeometry?.());
                    };

                    const rememberForwardPreviewViewport = () => {
                        if (forwardPreviewRestore !== null) return;

                        const geometry = instance?.getPreviewGeometry?.() || latestPreviewGeometry;
                        forwardPreviewRestore = {
                            device: selectedDevice,
                            width: Math.round(Number(geometry?.logicalWidth) || 1920),
                        };
                    };

                    const restoreForwardPreviewViewport = () => {
                        const restore = forwardPreviewRestore;
                        forwardPreviewRestore = null;
                        if (!restore) return;

                        if (restore.device === 'custom') {
                            selectPreviewWidth(restore.width, { prepare: false });
                            return;
                        }

                        selectDevice(restore.device);
                    };

                    const renderCompiledDeliveryHtml = () => {
                        if (!deliveryFrame || compiledDeliveryHtml === '') return;

                        const effectiveDegradationMode = selectedViewMode === 'forward'
                            ? 'forward'
                            : selectedDegradationMode;
                        const preview = effectiveDegradationMode === 'normal'
                            ? {
                                html: compiledDeliveryHtml,
                                disclaimer: 'Aktueller Entwurf nach CSS-Inliner · Browserdarstellung, kein Outlook-/iPhone-Nachweis.',
                            }
                            : instance?.createDegradationPreview?.(
                                compiledDeliveryHtml,
                                effectiveDegradationMode,
                            );
                        if (!preview?.html) return;

                        if (deliveryState) {
                            deliveryState.textContent = preview.disclaimer || 'Kompilierte Versandquelle wird im Browser dargestellt …';
                        }
                        deliveryFrame.onload = () => {
                            if (deliveryState && effectiveDegradationMode === 'normal') {
                                deliveryState.textContent = preview.disclaimer;
                            }
                            syncPreviewResizer();
                        };
                        deliveryFrame.srcdoc = preview.html;
                        syncPreviewResizer();
                    };

                    const loadCompiledDeliveryPreview = async () => {
                        if (typeof document_.endpoints?.deliveryPreview !== 'string'
                            || document_.endpoints.deliveryPreview.trim() === '') {
                            throw new Error('Der sichere Versandvorschau-Endpunkt ist nicht verfügbar.');
                        }

                        deliveryPreviewRequest?.abort();
                        deliveryPreviewRequest = new AbortController();
                        const generation = ++deliveryPreviewGeneration;
                        // Eine fehlgeschlagene neue Pruefung darf beim Wechsel
                        // der Robustheitsansicht keine alte Mail wiederbeleben.
                        compiledDeliveryHtml = '';
                        if (deliveryState) deliveryState.textContent = 'Aktueller Stand wird mit dem produktiven Systemmail-Compiler geprüft …';
                        if (deliveryFrame) deliveryFrame.srcdoc = '';

                        const candidate = currentCandidateForServer();
                        const payload = await request(document_.endpoints.deliveryPreview, 'POST', {
                            builder_data: candidate.builderData,
                            html: candidate.html,
                            css: candidate.css,
                            expected_hash: document_.contentHash || '',
                        }, { signal: deliveryPreviewRequest.signal });
                        if (destroyed || generation !== deliveryPreviewGeneration || selectedViewMode === 'edit') return;
                        if (payload.preview?.rendering !== 'compiled-system-mail'
                            || typeof payload.preview?.html !== 'string'
                            || payload.preview.html.trim() === '') {
                            throw new Error('Der Server hat kein vollständiges kompiliertes Versand-HTML geliefert.');
                        }

                        compiledDeliveryHtml = payload.preview.html;
                        showFindings(payload.report, payload.compatibility);
                        if (selectedViewMode === 'forward') {
                            const compilerMessages = Array.isArray(payload.report?.messages)
                                ? payload.report.messages.map((message) => `Versand-Compiler: ${message}`)
                                : [];
                            showFindings({
                                title: 'Weiterleitungsansicht: visuelle Prüfung erforderlich',
                                messages: [
                                    'Head-CSS wird erst im Browser aus dem kompilierten Versand-HTML entfernt. Diese Ansicht ist ein visueller Robustheitstest und kein geprüfter Nachweis für einen bestimmten Mailclient.',
                                    ...compilerMessages,
                                ],
                                findings: payload.report?.findings || [],
                            });
                        }
                        renderCompiledDeliveryHtml();
                    };

                    const selectViewMode = async (mode) => {
                        const nextViewMode = ['delivery', 'forward'].includes(mode) ? mode : 'edit';
                        const enteringForward = selectedViewMode !== 'forward' && nextViewMode === 'forward';
                        const leavingForward = selectedViewMode === 'forward' && nextViewMode !== 'forward';
                        if (enteringForward) rememberForwardPreviewViewport();
                        selectedViewMode = nextViewMode;
                        const shortcutLabel = studioRoot.querySelector('[data-mail-view-shortcut-label]');
                        if (shortcutLabel) shortcutLabel.textContent = selectedViewMode === 'edit' ? 'Versand prüfen' : 'Layout bearbeiten';
                        if (enteringForward) selectDevice('mobile');
                        if (leavingForward) restoreForwardPreviewViewport();
                        editorFrame?.setAttribute('data-mail-view-mode', selectedViewMode);
                        queryViewControls('[data-mail-view-mode]').forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailViewMode === selectedViewMode));
                            button.setAttribute('aria-busy', String(
                                selectedViewMode !== 'edit'
                                && button.dataset.mailViewMode === selectedViewMode
                            ));
                        });
                        queryViewControls('[data-mail-theme-button]').forEach((button) => {
                            button.disabled = selectedViewMode !== 'edit';
                        });
                        queryViewControl('[data-mail-theme-controls]')?.setAttribute('aria-disabled', String(selectedViewMode !== 'edit'));
                        const degradationSelect = queryViewControl('[data-mail-degradation-mode]');
                        if (degradationSelect) degradationSelect.disabled = selectedViewMode === 'forward';

                        if (selectedViewMode === 'edit') {
                            deliveryPreviewGeneration += 1;
                            deliveryPreviewRequest?.abort();
                            deliveryPreviewRequest = null;
                            if (deliveryPreview) deliveryPreview.hidden = true;
                            if (deliveryFrame) deliveryFrame.srcdoc = '';
                            instance?.setDegradationMode?.(selectedDegradationMode);
                            queryViewControls('[data-mail-view-mode]').forEach((button) => button.setAttribute('aria-busy', 'false'));
                            updatePreviewStatus(instance?.getPreviewGeometry?.());
                            return;
                        }

                        instance?.setDegradationMode?.('normal');
                        if (deliveryPreview) deliveryPreview.hidden = false;
                        updatePreviewStatus(instance?.getPreviewGeometry?.());
                        try {
                            await loadCompiledDeliveryPreview();
                        } catch (error) {
                            if (error?.name === 'AbortError') return;
                            const unavailableTitle = selectedViewMode === 'forward'
                                ? 'Weiterleitungsansicht nicht verfügbar'
                                : 'Versandansicht nicht verfügbar';
                            const surfaced = showRequestError(error, unavailableTitle);
                            if (deliveryState) deliveryState.textContent = surfaced.message;
                            toast('error', surfaced.message, unavailableTitle);
                        } finally {
                            queryViewControls('[data-mail-view-mode]').forEach((button) => button.setAttribute('aria-busy', 'false'));
                        }
                    };

                    const selectDegradationMode = (mode) => {
                        selectedDegradationMode = ['normal', 'images-off', 'head-css-off', 'css-off'].includes(mode)
                            ? mode
                            : 'normal';
                        const degradationSelect = queryViewControl('[data-mail-degradation-mode]');
                        if (degradationSelect) degradationSelect.value = selectedDegradationMode;
                        if (selectedViewMode !== 'edit') renderCompiledDeliveryHtml();
                        else instance?.setDegradationMode?.(selectedDegradationMode);
                        updatePreviewStatus(instance?.getPreviewGeometry?.());
                    };

