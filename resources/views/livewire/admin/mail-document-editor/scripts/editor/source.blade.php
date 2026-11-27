                    const utf8Size = (value) => new TextEncoder().encode(String(value || '')).byteLength;

                    const sourceSize = ({ html = '', css = '' } = {}) => utf8Size(html) + utf8Size(css);

                    const formatBytes = (bytes) => bytes < 1024
                        ? `${bytes} Byte`
                        : `${(bytes / 1024).toLocaleString('de-DE', { maximumFractionDigits: 1 })} KiB`;

                    const assertPortableSource = ({ html, css }, { enforceLimit = true } = {}) => {
                        if (typeof html !== 'string' || html.trim() === '') {
                            throw new Error('Der HTML-Code darf nicht leer sein.');
                        }
                        if (typeof css !== 'string') {
                            throw new Error('Der CSS-Code muss als Text vorliegen.');
                        }

                        const bytes = sourceSize({ html, css });
                        if (enforceLimit && bytes > MAX_SOURCE_BYTES) {
                            throw new Error(`HTML und CSS sind zusammen ${formatBytes(bytes)} groß. Erlaubt sind maximal 1 MiB.`);
                        }

                        const combined = `${html}\n${css}`;
                        const hasDataAttribute = /\b(?:src|href)\s*=\s*(?:["']\s*)?data:/i.test(combined);
                        const hasDataUrl = /url\s*\(\s*["']?\s*data:/i.test(combined);
                        if (hasDataAttribute || hasDataUrl) {
                            throw new Error('Eingebettete Data-Assets werden nicht importiert oder exportiert. Bitte eine öffentliche HTTPS-Bildquelle verwenden.');
                        }

                        return { html, css };
                    };

                    const currentCandidateForServer = () => {
                        const editor = instance?.editor;
                        if (!editor?.getProjectData || !editor?.getHtml || !editor?.getCss) {
                            // Die Code-/Export-/Import-Werkzeuge liegen bewusst
                            // ausserhalb der Leinwand. Damit kann ein defekter
                            // Altentwurf auch dann durch ein gueltiges Bundle
                            // ersetzt werden, wenn GrapesJS nicht startet.
                            const source = assertPortableSource({
                                html: String(document_.html || ''),
                                css: String(document_.css || ''),
                            }, { enforceLimit: false });

                            return {
                                ...source,
                                builderData: document_.builderData || {},
                            };
                        }

                        const outgoing = runtimeBridge.serializeForSave({
                            project: editor.getProjectData(),
                            html: editor.getHtml(),
                            css: editor.getCss(),
                            kind: config.currentDocument,
                            baselineHtml: activeBaselineHtml,
                            previewAssets: config.previewAssets || {},
                            imgOverlapProfile: config.imgOverlapProfile || null,
                            environment: editor.Canvas?.getWindow?.()
                                || editor.Canvas?.getDocument?.()?.defaultView
                                || window,
                        });

                        // Die kanonische Quelle enthaelt absichtlich weder
                        // builder_data noch Vorschaukonfiguration. Der
                        // Export haengt die geprueften Medien separat mit
                        // MIME, Groesse und SHA-256 an.
                        const source = assertPortableSource({
                            html: String(outgoing.html || ''),
                            css: String(outgoing.css || ''),
                        }, { enforceLimit: false });

                        return {
                            ...source,
                            builderData: outgoing.project,
                        };
                    };

                    const currentCanonicalSource = () => {
                        const { html, css } = currentCandidateForServer();
                        return { html, css };
                    };

                    const importProjectFor = ({ html, css }) => {
                        const editor = instance?.editor;

                        const existingMetadata = document_.builderData?.railtime;
                        const railtime = { document: config.currentDocument };
                        // Portable Signatur-Exporte enthalten absichtlich
                        // keine Builder-Metadaten. Erst die strikt erfolgreiche
                        // Projektion darf sie auf das aktuelle Schema heben;
                        // andernfalls wuerde ein alter IMG-/Background-Stand
                        // faelschlich schon als aktueller Vertrag gelten.
                        if (config.currentDocument !== 'signature'
                            && Number.isInteger(existingMetadata?.schema)) {
                            railtime.schema = existingMetadata.schema;
                        }

                        const portableProject = {
                            html,
                            css,
                            builderData: {
                                pages: [{
                                    name: document_.label || (config.currentDocument === 'signature' ? 'Signaturblock' : 'Nachrichtenvorlage'),
                                    component: html,
                                }],
                                styles: [],
                                railtime,
                            },
                        };

                        if (!editor?.Parser?.parseCss) {
                            return portableProject.builderData;
                        }

                        return runtimeBridge.projectFor(portableProject, (candidateCss) => editor.Parser.parseCss(candidateCss) || [], {
                            kind: config.currentDocument,
                            environment: window,
                            imgOverlapProfile: config.imgOverlapProfile || null,
                        });
                    };

                    const canonicalizeExternalSource = (source) => {
                        const editor = instance?.editor;
                        const checked = assertPortableSource(source);
                        const project = importProjectFor(checked);

                        // Im Recovery-Modus bleibt die Quelle bis zur
                        // serverautoritativen Normalisierung unveraendert.
                        // Der Save-Endpunkt synchronisiert danach HTML,
                        // Builderdaten und Schema als eine Einheit.
                        if (!editor) {
                            return {
                                project,
                                html: checked.html,
                                css: checked.css,
                            };
                        }

                        const canvasHtml = project?.pages?.[0]?.component;
                        if (typeof canvasHtml !== 'string' || canvasHtml.trim() === '') {
                            throw new Error('Aus dem importierten Code konnte kein bearbeitbares Mailprojekt erstellt werden.');
                        }

                        return runtimeBridge.serializeForSave({
                            project,
                            html: canvasHtml,
                            css: checked.css,
                            kind: config.currentDocument,
                            baselineHtml: checked.html,
                            previewAssets: config.previewAssets || {},
                            imgOverlapProfile: config.imgOverlapProfile || null,
                            environment: editor.Canvas?.getWindow?.()
                                || editor.Canvas?.getDocument?.()?.defaultView
                                || window,
                        });
                    };

                    const updateCodeSize = () => {
                        if (!codeSize) return;

                        const bytes = sourceSize({ html: codeHtml?.value || '', css: codeCss?.value || '' });
                        codeSize.textContent = `${formatBytes(bytes)} von maximal 1 MiB`;
                        codeSize.dataset.overLimit = String(bytes > MAX_SOURCE_BYTES);
                    };

                    const setCodeError = (message = '') => {
                        if (!codeError) return;

                        const visibleMessage = String(message || '').trim();
                        codeError.textContent = visibleMessage;
                        codeError.hidden = visibleMessage === '';
                    };

                    const openCodeDialog = (source, origin, opener, portableMedia = []) => {
                        if (!codeDialog?.showModal || !codeHtml || !codeCss) {
                            throw new Error('Die Codeansicht wird von diesem Browser nicht unterstützt.');
                        }

                        codeHtml.value = source.html;
                        codeCss.value = source.css;
                        if (codeOrigin) codeOrigin.textContent = origin;
                        codeDialogOpener = opener || window.document.activeElement;
                        pendingPortableMedia = Array.isArray(portableMedia) ? portableMedia : [];
                        setCodeError();
                        updateCodeSize();
                        if (!codeDialog.open) codeDialog.showModal();
                        window.requestAnimationFrame(() => codeHtml.focus());
                    };

