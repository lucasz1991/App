                    const boot = async () => {
                        // Die Preview-Seite bleibt leichtgewichtig: Erst ein
                        // bewusster Vollbild-Start laedt LMZ/GrapesJS und CSS.
                        if (!(await waitForFullscreenActivation()) || destroyed) return;
                        const runtime = await ensureRuntime();

                        if (destroyed) return;

                        // GrapesJS baut beim Start mehrere tausend Knoten in
                        // einem Zug auf. wire:ignore schuetzt nur Livewire;
                        // ohne diese Grenze versucht Alpines globaler
                        // MutationObserver jeden dieser fremdverwalteten
                        // Knoten als Alpine-Komponente zu initialisieren und
                        // blockiert den Renderer. Die Pause umfasst bewusst
                        // nur den initialen Builder-Aufbau.
                        const alpine = window.Alpine;
                        const canPauseAlpineMutations = typeof alpine?.stopObservingMutations === 'function'
                            && typeof alpine?.startObservingMutations === 'function';

                        if (canPauseAlpineMutations) alpine.stopObservingMutations();

                        try {
                            instance = await runtimeBridge.create({
                                runtime,
                                root,
                                projectId: `mail:${document_.id}`,
                                vendor: config.vendor,
                                theme: selectedTheme,
                                assets: config.mailAssets || [],
                                previewAssets: config.previewAssets || {},
                                previewResponsiveCss: config.previewResponsiveCss || {},
                                previewResponsiveCssByArtifact: config.previewResponsiveCssByArtifact || {},
                                imgOverlapProfile: config.imgOverlapProfile || null,
                                previewThemeValues: config.previewThemeValues || {},
                                compatibilityManifest: config.compatibilityManifest || {},
                                previewDevice: selectedDevice,
                                onPreviewChange: updatePreviewStatus,
                                assistantContext: {
                                    resourceId: document_.id,
                                    formatOrKind: () => config.currentDocument,
                                    persistedHash: () => document_.contentHash || '',
                                    persistedVersion: () => document_.version || 0,
                                },
                                storage: {
                                    onLoad: ({ editor }) => runtimeBridge.projectFor(
                                        document_,
                                        (css) => editor.Parser?.parseCss?.(css) || [],
                                        { kind: config.currentDocument, environment: window, imgOverlapProfile: config.imgOverlapProfile || null },
                                    ),
                                    onSave: async ({ project, html, css, editor }) => {
                                    lastEditorSaveError = null;
                                    let editedDuringSave = false;
                                    const markConcurrentEdit = () => { editedDuringSave = true; };
                                    editor.on?.('update', markConcurrentEdit);

                                    try {
                                        const outgoing = runtimeBridge.serializeForSave({
                                            project,
                                            html,
                                            css,
                                            kind: config.currentDocument,
                                            baselineHtml: activeBaselineHtml,
                                            previewAssets: config.previewAssets || {},
                                            imgOverlapProfile: config.imgOverlapProfile || null,
                                            environment: editor.Canvas?.getWindow?.()
                                                || editor.Canvas?.getDocument?.()?.defaultView
                                                || window,
                                        });
                                        const payload = await request(document_.endpoints.update, 'PUT', {
                                            builder_data: outgoing.project,
                                            html: outgoing.html,
                                            css: outgoing.css,
                                            expected_hash: document_.contentHash || '',
                                        });

                                        // Ab jetzt folgende Server-Hydration ist keine
                                        // neue Benutzereingabe waehrend des Requests.
                                        editor.off?.('update', markConcurrentEdit);
                                        if (destroyed) return;

                                        // Die Serverfassung ist nach der
                                        // E-Mail-Haertung autoritativ. Vor allem
                                        // builder_data darf kein unsauberes
                                        // Parallel-Markup behalten.
                                        document_.builderData = payload.document?.builder_data ?? outgoing.project;
                                        document_.html = payload.document?.html ?? outgoing.html;
                                        document_.css = payload.document?.css ?? outgoing.css;
                                        activeBaselineHtml = document_.html;
                                        applyDocumentState(payload.document);
                                        showFindings(payload.report, payload.compatibility);
                                        // Der gespeicherte Hash wird uebernommen, neuere
                                        // Eingaben im Canvas aber niemals durch den alten
                                        // Requeststand ersetzt. Der Vendor behaelt sie dirty.
                                        if (!editedDuringSave) await runtimeBridge.rehydrateAuthoritative({
                                            editor,
                                            draft: document_,
                                            sanitizationChanged: (payload.report?.findings || [])
                                                .some((finding) => finding.severity === 'violation'),
                                            parseCss: (canonicalCss) => editor.Parser?.parseCss?.(canonicalCss) || [],
                                            projectOptions: { kind: config.currentDocument, environment: window, imgOverlapProfile: config.imgOverlapProfile || null },
                                        });
                                        setMessage(editedDuringSave
                                            ? 'Zwischenstand gespeichert. Neuere Änderungen bleiben im Editor und werden anschließend gespeichert.'
                                            : document_.hasUnpublishedChanges
                                            ? 'Gespeichert — noch nicht veröffentlicht.'
                                            : 'Gespeichert.');
                                    } catch (error) {
                                        // LMZ 2.4.5 protokolliert onSave-Fehler
                                        // und liefert seinem Aufrufer nur false.
                                        // Die echte Server-/Vertragsmeldung
                                        // bleibt deshalb hier fuer den
                                        // expliziten Save erhalten.
                                        lastEditorSaveError = normalizeError(
                                            error,
                                            'Der Entwurf konnte nicht gespeichert werden.',
                                        );
                                        throw lastEditorSaveError;
                                    } finally {
                                        editor.off?.('update', markConcurrentEdit);
                                    }
                                    },
                                },
                            });
                        } finally {
                            if (canPauseAlpineMutations) alpine.startObservingMutations();
                        }

                        if (destroyed) {
                            instance.destroy();
                            instance = null;
                            return;
                        }

                        const syncBuilderToolbarContext = () => {
                            window.document.querySelectorAll('[data-mail-builder-panel^="right:"]').forEach((control) => {
                                const available = Boolean(instance?.isEditorPanelAvailable?.(control.dataset.mailBuilderPanel));
                                control.hidden = !available;
                                control.inert = !available;
                                control.setAttribute('aria-disabled', available ? 'false' : 'true');
                            });
                        };
                        ['component:selected', 'component:deselected', 'component:update'].forEach((eventName) => {
                            instance.editor?.on?.(eventName, syncBuilderToolbarContext);
                        });
                        detachBuilderToolbarContext = () => {
                            ['component:selected', 'component:deselected', 'component:update'].forEach((eventName) => {
                                instance?.editor?.off?.(eventName, syncBuilderToolbarContext);
                            });
                        };
                        syncBuilderToolbarContext();
                        window.requestAnimationFrame(syncBuilderToolbarContext);

                        selectTheme(selectedTheme);
                        selectDevice(selectedDevice);
                        selectDegradationMode(selectedDegradationMode);
                        await selectViewMode(selectedViewMode);
                        if (!destroyed) editorBootState = 'ready';
                    };

