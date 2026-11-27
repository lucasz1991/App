                    // Die Verdrahtung steht bewusst auf der Seite: das Modul
                    // resources/js/mail-builder.js bringt keinen eigenen
                    // Startvorgang mit, app.js reicht es nur als
                    // window.RailTimeMailBuilder durch.
                    // Die gemeinsame Vollbild-Shell teleportiert den Workspace
                    // erst beim Alpine-Start nach <body>. Livewire kann diesen
                    // Skriptblock vorher auswerten; ein sofortiges querySelector()
                    // wuerde dann dauerhaft am Loader stehen bleiben.
                    const editorStart = await new Promise((resolve) => {
                        let settled = false;
                        const finish = (value) => {
                            if (settled) return;
                            settled = true;
                            window.clearInterval(interval);
                            window.clearTimeout(timeout);
                            observer.disconnect();
                            window.removeEventListener('page-builder-shell:opened', probe);
                            document.removeEventListener('livewire:navigating', cancel);
                            resolve(value);
                        };
                        const probe = () => {
                            const workspace = document.querySelector('[data-mail-document-studio]');
                            const root = workspace?.querySelector('[data-mail-document-root]');
                            const runtimeBridge = window.RailTimeMailBuilder;
                            if (workspace && root && runtimeBridge) finish({ workspace, root, runtimeBridge });
                        };
                        const cancel = () => finish(null);
                        const observer = new MutationObserver(probe);
                        observer.observe(document.body, { childList: true, subtree: true });
                        const interval = window.setInterval(probe, 25);
                        const timeout = window.setTimeout(() => finish(null), 5000);
                        window.addEventListener('page-builder-shell:opened', probe);
                        document.addEventListener('livewire:navigating', cancel, { once: true });
                        probe();
                    });

                    if (!editorStart) {
                        return;
                    }
                    const { workspace, root, runtimeBridge } = editorStart;

                    // Ein zweiter Durchlauf (Livewire-Navigation, erneutes
                    // Rendern) darf keine zweite Instanz auf denselben Knoten
                    // setzen.
                    window.RailTimeMailDocumentEditor?.destroy?.();

                    // Die einzeilige Mail-Toolbar sitzt im Kopf des
                    // Fullscreen-Modals und damit neben (nicht innerhalb)
                    // der eigentlichen Builder-Workspace. Der gemeinsame
                    // Modal-Root umfasst beide Bereiche und bleibt auch fuer
                    // Editoren ohne Single-Toolbar rueckwaertskompatibel.
                    const studioRoot = workspace.closest('[data-rt-fullscreen-modal]')
                        || workspace.closest('[data-page-builder-fullscreen-root]')
                        || workspace;
                    const config = JSON.parse(workspace.querySelector('[data-mail-document-config]')?.textContent || '{}');
                    const document_ = config.documents?.[config.currentDocument];
                    // Der Ansicht-Inhalt liegt nach Alpines Teleport ausserhalb
                    // des Modals. Erst bei Zugriff suchen: Eine fruehe NodeList
                    // bleibt sonst auch nach dem Teleport dauerhaft leer.
                    const viewPanelId = `rt-dropdown-mail-document-view-${config.currentDocument}-content`;
                    const viewControlsRoot = () => window.document.getElementById(viewPanelId)
                        || studioRoot.querySelector('[data-mail-toolbar-menu="view"]');
                    const queryViewControl = (selector) => viewControlsRoot()?.querySelector(selector) || null;
                    const queryViewControls = (selector) => Array.from(viewControlsRoot()?.querySelectorAll(selector) || []);
                    const viewEventControl = (event, selector) => {
                        const control = event.target?.closest?.(selector);
                        return control && viewControlsRoot()?.contains(control)
                            && !control.disabled && control.getAttribute('aria-disabled') !== 'true'
                            ? control : null;
                    };
                    const saveButton = studioRoot.querySelector('[data-mail-document-save]');
                    const publishButton = studioRoot.querySelector('[data-mail-document-publish]');
                    const messageNode = studioRoot.querySelector('[data-mail-document-message]');
                    const findingsBox = studioRoot.querySelector('[data-mail-document-findings]');
                    const findingsList = studioRoot.querySelector('[data-mail-document-findings-list]');
                    const findingsTitle = studioRoot.querySelector('[data-mail-document-findings-title]');
                    const statusBadge = studioRoot.querySelector('[data-mail-document-status]');
                    const editorFrame = studioRoot.querySelector('[data-mail-editor-frame]');
                    const previewResizer = studioRoot.querySelector('[data-mail-preview-resizer]');
                    const deliveryPreview = studioRoot.querySelector('[data-mail-delivery-preview]');
                    const deliveryFrame = studioRoot.querySelector('[data-mail-delivery-frame]');
                    const deliveryState = studioRoot.querySelector('[data-mail-delivery-state]');
                    const importFile = studioRoot.querySelector('[data-mail-code-import-file]');
                    const codeDialog = studioRoot.querySelector('[data-mail-code-dialog]');
                    const codeHtml = studioRoot.querySelector('[data-mail-code-html]');
                    const codeCss = studioRoot.querySelector('[data-mail-code-css]');
                    const codeOrigin = studioRoot.querySelector('[data-mail-code-origin]');
                    const codeSize = studioRoot.querySelector('[data-mail-code-size]');
                    const codeError = studioRoot.querySelector('[data-mail-code-error]');
                    const codeApplyButton = studioRoot.querySelector('[data-mail-code-apply]');
                    const codeCancelButtons = Array.from(studioRoot.querySelectorAll('[data-mail-code-dialog] button[type="submit"]'));

                    if (!document_) {
                        return;
                    }

