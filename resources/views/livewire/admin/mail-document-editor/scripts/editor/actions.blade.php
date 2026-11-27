                    const canonicalDraftFromValidation = (payload) => {
                        const canonical = payload?.document || payload?.canonical || null;
                        const builderData = canonical?.builder_data || canonical?.builderData;
                        if (!canonical
                            || typeof canonical.html !== 'string'
                            || typeof canonical.css !== 'string'
                            || !builderData
                            || typeof builderData !== 'object'
                            || Array.isArray(builderData)) {
                            throw new Error('Die serverseitige Prüfung hat keinen vollständigen kanonischen Entwurf zurückgegeben.');
                        }

                        return {
                            html: canonical.html,
                            css: canonical.css,
                            builderData,
                        };
                    };

                    const validateSourceOnServer = async (source, portableMedia = [], expectedHash = document_.contentHash || '') => {
                        if (typeof document_.endpoints?.validate !== 'string' || document_.endpoints.validate.trim() === '') {
                            throw new Error('Der sichere Prüf-Endpunkt für Codeimporte ist nicht verfügbar. Es wurde nichts übernommen.');
                        }

                        const candidate = canonicalizeExternalSource(source);
                        const payload = await request(document_.endpoints.validate, 'POST', {
                            builder_data: candidate.project,
                            html: candidate.html,
                            css: candidate.css,
                            expected_hash: expectedHash,
                            portable_media: portableMedia,
                        });

                        return {
                            draft: canonicalDraftFromValidation(payload),
                            report: payload.report || null,
                            compatibility: payload.compatibility,
                        };
                    };

                    const applyCodeAsDraft = async () => {
                        const source = assertPortableSource({
                            html: codeHtml?.value || '',
                            css: codeCss?.value || '',
                        });
                        const editorInstance = instance;
                        const builder = editorInstance?.instance;
                        const recoveringFailedEditor = editorBootState === 'failed' && !editorInstance;
                        if (destroyed || (!recoveringFailedEditor
                            && (editorBootState !== 'ready' || typeof builder?.setActionLocked !== 'function'))) {
                            throw new Error('Der sichere Importspeicher ist noch nicht vollständig geladen. Bitte versuche es erneut.');
                        }

                        // Ab jetzt darf der bisherige Leinwandstand nicht mehr
                        // parallel zum Import gespeichert werden. Bei gesetzter
                        // Aktionssperre liefert ein nichtmanueller Save nur die
                        // vorhandene Speicherwarteschlange; er startet keinen
                        // neuen Save des moeglicherweise defekten Altstands.
                        // Nach einem abgeschlossenen Startfehler existiert
                        // keine Leinwand mehr. Der beworbene Recovery-Import
                        // geht dann ohne Editorwarteschlange an den Server.
                        builder?.setActionLocked(true);
                        let importSaved = false;
                        try {
                            if (editorInstance) await editorInstance.save('autosave-import-drain');
                            if (destroyed) throw new Error('Der Editor wurde während des Imports geschlossen.');
                            const expectedHash = document_.contentHash || '';

                            // Der gepruefte Stand geht direkt an den normalen
                            // Entwurfs-Endpunkt. loadProjectData() vor dem Save
                            // wuerde kanonische Knoten erneut durch temporaere
                            // GrapesJS-Attribute und Aenderungsereignisse fuehren.
                            setMessage('Code wird serverseitig geprüft …');
                            const validated = await validateSourceOnServer(source, pendingPortableMedia, expectedHash);
                            if (destroyed) throw new Error('Der Editor wurde während des Imports geschlossen.');
                            setMessage('Geprüfter Entwurf wird gespeichert …');
                            const payload = await request(document_.endpoints.update, 'PUT', {
                                builder_data: validated.draft.builderData,
                                html: validated.draft.html,
                                css: validated.draft.css,
                                expected_hash: expectedHash,
                            });
                            importSaved = true;
                            if (destroyed) return;
                            applyDocumentState(payload.document);
                            activeBaselineHtml = String(document_.html || validated.draft.html);
                            showFindings(
                                payload.report || validated.report,
                                payload.compatibility ?? validated.compatibility,
                            );
                            pendingPortableMedia = [];

                            const message = 'Code geprüft und als Entwurf gespeichert. Die veröffentlichte Fassung bleibt unverändert. Der Editor wird neu geladen.';
                            setMessage(message);
                            toast('success', message, 'Import abgeschlossen');
                            codeDialog?.close('saved');
                            window.setTimeout(() => window.location.reload(), 250);
                        } finally {
                            // Nach Erfolg bleibt die alte Leinwand bis zum
                            // Reload gesperrt: sie darf den gerade importierten
                            // Entwurf nicht mit dessen frischem Hash ersetzen.
                            if (!importSaved) builder?.setActionLocked(editorInstance?.readOnly === true);
                        }
                    };

                    bindToolControl('[data-mail-code-open]', (event) => {
                        const codeButton = event.currentTarget;
                        try {
                            openCodeDialog(
                                currentCanonicalSource(),
                                'Kanonischer Stand des aktuellen Editors',
                                codeButton,
                            );
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Codeansicht nicht verfügbar');
                            toast('error', surfaced.message, 'Codeansicht nicht verfügbar');
                        }
                    });

                    bindToolControl('[data-mail-code-export]', async (event) => {
                        const exportButton = event.currentTarget;
                        try {
                            exportButton.disabled = true;
                            exportButton.setAttribute('aria-busy', 'true');
                            setMessage('HTML, CSS und Medien werden vollständig exportiert …');
                            await downloadPortableBundle(currentCanonicalSource());
                            setMessage('Portables JSON-Bundle mit Medien wurde exportiert.');
                            toast('success', 'HTML, CSS und alle zugehörigen GIF-/Bilddateien sind mit SHA-256 im Bundle enthalten.', 'Export erstellt');
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Export nicht möglich');
                            toast('error', surfaced.message, 'Export nicht möglich');
                        } finally {
                            exportButton.disabled = false;
                            exportButton.setAttribute('aria-busy', 'false');
                        }
                    });

                    bindToolControl('[data-mail-code-import]', (event) => {
                        const importButton = event.currentTarget;
                        codeDialogOpener = importButton;
                        importFile?.click();
                    });

                    importFile?.addEventListener('change', async () => {
                        const file = importFile.files?.[0] || null;
                        importFile.value = '';
                        if (!file) return;

                        try {
                            const extension = file.name.toLowerCase().match(/\.[^.]+$/)?.[0] || '';
                            if (!['.json', '.html', '.htm', '.css'].includes(extension)) {
                                throw new Error('Unterstützt werden ausschließlich .json, .html, .htm und .css.');
                            }
                            const fileLimit = extension === '.json' ? MAX_BUNDLE_BYTES : MAX_SOURCE_BYTES;
                            if (file.size > fileLimit) {
                                throw new Error(`„${file.name}“ ist größer als ${extension === '.json' ? '16 MiB' : '1 MiB'} und wurde nicht gelesen.`);
                            }

                            const text = await file.text();
                            if (destroyed) return;
                            let source;
                            let portableMedia = [];
                            if (extension === '.json') {
                                const parsed = await parsePortableBundle(text);
                                source = parsed.source;
                                portableMedia = parsed.media;
                            } else {
                                const current = currentCanonicalSource();
                                source = extension === '.css'
                                    ? assertPortableSource({ html: current.html, css: text })
                                    : assertPortableSource({ html: text, css: current.css });
                            }

                            openCodeDialog(
                                source,
                                `Importdatei: ${file.name} · ${portableMedia.length} Medien · Übernahme speichert einen Entwurf`,
                                codeDialogOpener,
                                portableMedia,
                            );
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Import nicht möglich');
                            toast('error', surfaced.message, 'Import nicht möglich');
                        }
                    }, { signal: controlListeners.signal });

                    [codeHtml, codeCss].forEach((field) => {
                        field?.addEventListener('input', () => {
                            setCodeError();
                            updateCodeSize();
                        }, { signal: controlListeners.signal });
                    });

                    codeDialog?.addEventListener('close', () => {
                        const opener = codeDialogOpener;
                        codeDialogOpener = null;
                        pendingPortableMedia = [];
                        if (!destroyed && opener?.isConnected) opener.focus();
                    }, { signal: controlListeners.signal });

                    codeDialog?.addEventListener('cancel', (event) => {
                        if (codeApplyButton?.getAttribute('aria-busy') === 'true') {
                            event.preventDefault();
                        }
                    }, { signal: controlListeners.signal });

                    codeApplyButton?.addEventListener('click', async () => {
                        setActionsBusy(true);
                        if (codeHtml) codeHtml.readOnly = true;
                        if (codeCss) codeCss.readOnly = true;
                        let importApplied = false;

                        try {
                            setCodeError();
                            await applyCodeAsDraft();
                            importApplied = true;
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Code konnte nicht übernommen werden');
                            setCodeError(surfaced.message);
                            toast('error', surfaced.message, 'Nicht gespeichert');
                        } finally {
                            if (!importApplied) {
                                if (codeHtml) codeHtml.readOnly = false;
                                if (codeCss) codeCss.readOnly = false;
                                setActionsBusy(false);
                            }
                        }
                    }, { signal: controlListeners.signal });

                    saveButton?.addEventListener('click', async () => {
                        setActionsBusy(true);

                        try {
                            await saveCurrentDraft();
                            const successText = document_.hasUnpublishedChanges
                                ? 'Entwurf gespeichert. Mail-Notifications und Systemmails verwenden weiterhin die veröffentlichte Fassung.'
                                : 'Der aktuelle veröffentlichte Stand ist vollständig gespeichert.';
                            setMessage(successText);
                            toast('success', successText, 'Entwurf gespeichert');
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Speichern nicht möglich');
                            toast('error', surfaced.message, 'Nicht gespeichert');
                        } finally {
                            setActionsBusy(false);
                        }
                    }, { signal: controlListeners.signal });

                    bindToolControl('[data-mail-document-test-mail]', async () => {
                        setActionsBusy(true);
                        try {
                            await saveCurrentDraft();
                            const payload = await request(document_.endpoints.testMail, 'POST', {
                                expected_hash: document_.contentHash || '',
                            });
                            showFindings(payload.report, payload.compatibility);
                            setMessage(payload.message || 'Testmail wurde gesendet.');
                            toast('success', payload.message || 'Testmail wurde gesendet.', 'Testmail gesendet');
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Testmail nicht möglich');
                            toast('error', surfaced.message, 'Testmail nicht gesendet');
                        } finally {
                            setActionsBusy(false);
                        }
                    });

                    publishButton?.addEventListener('click', async () => {
                        setActionsBusy(true);

                        try {
                            // Erst der Arbeitsstand, dann die Freigabe:
                            // veroeffentlicht wird exakt der serverautoritative
                            // Entwurf. Der manuelle Save laeuft bewusst auch,
                            // wenn GrapesJS keinen Dirty-Stand meldet.
                            await saveCurrentDraft();

                            const payload = await request(document_.endpoints.publish, 'POST', {
                                expected_hash: document_.contentHash || '',
                            });
                            applyDocumentState(payload.document);
                            showFindings(payload.report, payload.compatibility);
                            setMessage(`Veröffentlicht am ${payload.document?.published_label ?? ''} Uhr. Verwendung im gleichnamigen Menü prüfen; bestehende Zuordnungen bleiben erhalten.`);
                            const successText = config.currentDocument === 'signature'
                                ? 'Outlook-Paket und Systemmails verwenden ab sofort diese Signatur.'
                                : 'Mail-Notifications und Systemmails verwenden ab sofort diese Nachrichtenschale.';
                            toast('success', successText, 'Veröffentlicht');
                            // Slotstatus, Schutzschalter und Versionsliste
                            // stammen serverseitig aus einer gemeinsamen
                            // Transaktion. Ein kurzer Reload zieht das Modal
                            // nach, ohne den eben gespeicherten Entwurf zu
                            // verlieren.
                            window.setTimeout(() => window.location.reload(), 350);
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Veröffentlichung nicht möglich');
                            toast('error', surfaced.message, 'Nicht veröffentlicht');
                        } finally {
                            setActionsBusy(false);
                        }
                    }, { signal: controlListeners.signal });

