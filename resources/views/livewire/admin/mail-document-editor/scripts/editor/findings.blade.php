                    let lastFindingsAlert = '';
                    const openFindingsAlert = () => {
                        const content = findingsBox.cloneNode(true);
                        content.hidden = false;
                        content.querySelector('[data-mail-document-findings-title]')?.remove();
                        if (window.Swal?.fire) {
                            window.Swal.fire({
                                titleText: findingsTitle.textContent,
                                html: content,
                                icon: compatibilityBlocksPublication ? 'error' : (findingsList.children.length ? 'warning' : 'info'),
                                confirmButtonText: 'Verstanden',
                                returnFocus: true,
                            });
                        } else {
                            window.alert(`${findingsTitle.textContent}\n\n${content.textContent}`);
                        }
                    };
                    window.document.addEventListener('click', (event) => {
                        if (!viewEventControl(event, '[data-mail-findings-open]')) return;
                        event.preventDefault();
                        openFindingsAlert();
                    }, { signal: controlListeners.signal });

                    const showFindings = (report, compatibility = undefined) => {
                        if (!findingsBox || !findingsList) return;

                        const sanitizerMessages = Array.isArray(report?.messages) ? report.messages : [];
                        let normalizedCompatibility = null;
                        if (compatibility !== undefined) {
                            normalizedCompatibility = runtimeBridge.normalizeCompatibilityReport?.(compatibility)
                                || compatibility
                                || null;
                            compatibilityBlocksPublication = normalizedCompatibility?.status === 'block';
                            publishButton?.setAttribute(
                                'title',
                                compatibilityBlocksPublication
                                    ? 'Veröffentlichung durch E-Mail-Kompatibilitätsprüfung blockiert'
                                    : 'Aktuellen Entwurf veröffentlichen',
                            );
                            setActionsBusy(actionsBusy);
                        }

                        const compatibilityMessages = Array.isArray(normalizedCompatibility?.findings)
                            ? normalizedCompatibility.findings.map((finding) => {
                                const ruleId = finding.ruleId || finding.rule_id || 'E-Mail-Kompatibilität';
                                const message = finding.message || '';
                                const fix = finding.fix || '';
                                const profiles = finding.clientProfiles || finding.client_profiles || [];
                                const profileHint = Array.isArray(profiles) && profiles.length > 0
                                    ? ` Betroffene Profile: ${profiles.join(', ')}.`
                                    : '';
                                const fixHint = fix ? ` Lösung: ${fix}` : '';

                                return `[${ruleId}] ${message}${fixHint}${profileHint}`.trim();
                            })
                            : [];
                        const messages = [...new Set([...sanitizerMessages, ...compatibilityMessages]
                            .filter((message) => typeof message === 'string' && message.trim() !== ''))];
                        findingsList.replaceChildren();

                        // Rule coverage is not a client-rendering result. Old reports
                        // without this metadata must not become an invented zero-pass.
                        const checks = normalizedCompatibility?.checks;
                        const hasCheckSummary = checks !== null && typeof checks === 'object';
                        const summary = findingsBox.querySelector('[data-mail-document-check-summary]');
                        if (summary) {
                            const count = (value) => Number.isSafeInteger(value) && value >= 0 ? value : 0;
                            summary.hidden = !hasCheckSummary;
                            summary.textContent = hasCheckSummary
                                ? `${count(checks.automated)} Regeln automatisch geprüft · ${count(checks.manual)} Regeln benötigen eine manuelle Prüfung. Die Darstellung im tatsächlichen Mailclient ist hier nicht verifiziert.`
                                : '';
                        }

                        if (messages.length === 0 && !hasCheckSummary) {
                            findingsBox.hidden = true;
                            findingsBox.classList.add('hidden');
                            return;
                        }

                        const removed = (report?.findings || []).some((finding) => finding.severity === 'violation');
                        const explicitTitle = typeof report?.title === 'string' ? report.title.trim() : '';
                        if (findingsTitle) {
                            findingsTitle.textContent = explicitTitle || (
                                compatibility !== undefined && compatibilityBlocksPublication
                                    ? 'Veröffentlichung durch Kompatibilitätsregeln blockiert'
                                    : (removed
                                        ? 'Die Prüfung hat Inhalte entfernt'
                                        : (messages.length === 0 && hasCheckSummary
                                            ? 'Automatisch geprüft · Mailclient-Prüfung offen'
                                            : 'Hinweise zur E-Mail'))
                            );
                        }

                        messages.forEach((message) => {
                            const item = window.document.createElement('li');
                            item.textContent = message;
                            findingsList.appendChild(item);
                        });

                        // Keep the canvas unobstructed. New findings alert once;
                        // details remain available from the View menu. Publication
                        // blocking above is independent from dismissing this alert.
                        findingsBox.hidden = true;
                        const fingerprint = JSON.stringify([findingsTitle.textContent, messages]);
                        if ((messages.length > 0 || removed || compatibilityBlocksPublication) && fingerprint !== lastFindingsAlert) {
                            lastFindingsAlert = fingerprint;
                            openFindingsAlert();
                        } else if (messages.length === 0 && !compatibilityBlocksPublication) {
                            lastFindingsAlert = '';
                        }
                    };

                    // Bereits der serverseitig geladene Entwurf zeigt seinen
                    // Katalogstatus. BLOCK deaktiviert nur Freigabe/Testmail;
                    // der Entwurf selbst bleibt weiterhin bearbeitbar.
                    showFindings(null, document_.compatibility);

                    const normalizeError = (error, fallback) => {
                        if (error instanceof Error) return error;

                        const normalized = new Error(
                            typeof error?.message === 'string' && error.message.trim() !== ''
                                ? error.message
                                : fallback,
                        );
                        if (Array.isArray(error?.messages)) normalized.messages = error.messages;

                        return normalized;
                    };

                    const showRequestError = (error, title) => {
                        const normalized = normalizeError(error, title);
                        const validationMessages = Array.isArray(normalized.messages)
                            ? normalized.messages.filter((message) => typeof message === 'string' && message.trim() !== '')
                            : [];
                        const messages = validationMessages.length > 0
                            ? validationMessages
                            : [normalized.message];

                        // Ein fehlgeschlagener Request ist kein erfolgreicher
                        // Sanitizer-Bericht. Nur payload.report darf behaupten,
                        // dass Inhalte tatsächlich entfernt wurden.
                        showFindings({ title, messages, findings: [] });
                        setMessage(messages[0]);

                        return normalized;
                    };

                    const applyDocumentState = (payload) => {
                        if (!payload) return;

                        // Pflicht: ohne den frischen Hash laeuft der naechste
                        // Autosave in die Konfliktmeldung.
                        document_.contentHash = payload.content_hash || document_.contentHash;
                        document_.version = payload.version ?? document_.version;
                        document_.status = payload.status || document_.status;
                        document_.hasUnpublishedChanges = Boolean(payload.has_unpublished_changes);
                        if (typeof payload.html === 'string') document_.html = payload.html;
                        if (typeof payload.css === 'string') document_.css = payload.css;
                        if (payload.builder_data) document_.builderData = payload.builder_data;
                        if (Array.isArray(payload.versions)) document_.versions = payload.versions;

                        if (statusBadge) {
                            statusBadge.dataset.status = document_.status;
                            statusBadge.dataset.hasUnpublishedChanges = String(document_.hasUnpublishedChanges);
                            statusBadge.dataset.statusLabel = payload.status_label || statusBadge.dataset.statusLabel || statusBadge.textContent;
                            statusBadge.textContent = document_.status === 'published' && document_.hasUnpublishedChanges
                                ? 'Entwurf'
                                : statusBadge.dataset.statusLabel;
                        }
                    };

