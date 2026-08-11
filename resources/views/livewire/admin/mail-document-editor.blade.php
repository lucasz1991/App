@section('title', 'Mail- & Signatur-Editor')

@php
    $kinds = [
        \App\Enums\MailDocumentKind::Template->value => ['Nachrichtenvorlage', 'HTML- und EML-Vorlagendownloads'],
        \App\Enums\MailDocumentKind::Signature->value => ['Signaturblock', 'Downloads und Systemmails'],
    ];
@endphp

<x-ui.page-builder.editor-shell
    title="Mail- & Signatur-Editor"
    eyebrow="E-Mail-Vorlagen"
    description="Vorlagendateien und Signaturblock bearbeiten. Die Nachrichtenvorlage gilt für Downloads; der Signaturblock zusätzlich für Systemmails."
    :back-url="route('email-templates.index')"
    back-label="Zur Vorlagen-Seite"
    :preview-sources="$editorPreviewSources"
    preview-default="light"
    workspace-class="min-h-0 flex-1 overflow-hidden p-0"
    data-mail-document-studio
    data-mail-document-back
>
    @if ($currentDocument !== null)
        <x-slot:toolbar>
            <div class="rt-mail-studio-toolbar" data-mail-studio-toolbar>
                <div class="rt-mail-studio-toolbar__documents" role="group" aria-label="Dokument auswählen">
                    @foreach ($kinds as $kindValue => [$kindLabel, $kindHint])
                        <a
                            href="{{ route('admin.mail-documents.editor', ['dokument' => $kindValue]) }}"
                            wire:navigate
                            data-mail-document-switch="{{ $kindValue }}"
                            aria-current="{{ $currentKind === $kindValue ? 'page' : 'false' }}"
                            class="rt-mail-studio-document"
                        >
                            <span>{{ $kindLabel }}</span>
                            <small>{{ $kindHint }}</small>
                        </a>
                    @endforeach
                </div>

                <div class="rt-mail-studio-toolbar__preview" data-mail-preview-toolbar>
                    <div class="rt-mail-preview-context">
                        <strong>Vorschau</strong>
                        <small data-mail-preview-status aria-live="polite">Desktop · 1024 px · wird eingepasst</small>
                    </div>

                    <div class="rt-mail-preview-toggle" role="group" aria-label="Farbschema der Vorschau">
                        <button type="button" data-mail-theme-button="light" aria-pressed="true" title="Helle Mail ansehen">
                            <i data-feather="sun" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Hell</span>
                        </button>
                        <button type="button" data-mail-theme-button="dark" aria-pressed="false" title="Dunkle Mail ansehen">
                            <i data-feather="moon" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Dunkel</span>
                        </button>
                    </div>

                    <div class="rt-mail-preview-toggle" role="group" aria-label="Breite des Mailprogramms">
                        <button type="button" data-mail-preview-device="desktop" aria-pressed="true" title="Desktop-Vorschau mit 1024 Pixeln">
                            <i data-feather="monitor" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Desktop</span>
                        </button>
                        <button type="button" data-mail-preview-device="tablet" aria-pressed="false" title="Tablet-Vorschau mit 820 Pixeln">
                            <i data-feather="tablet" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Tablet</span>
                        </button>
                        <button type="button" data-mail-preview-device="mobile" aria-pressed="false" title="Mobil-Vorschau mit 375 Pixeln">
                            <i data-feather="smartphone" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Mobil</span>
                        </button>
                    </div>
                </div>

                <div class="rt-mail-studio-toolbar__actions">
                    <span
                        data-mail-document-status
                        data-status="{{ $currentDocument->status->value }}"
                        data-status-label="{{ $currentDocument->status->label() }}"
                        data-has-unpublished-changes="{{ $currentDocument->hasUnpublishedChanges() ? 'true' : 'false' }}"
                        class="rt-mail-document-status"
                    >{{ $currentDocument->status->label() }}{{ $currentDocument->isPublished() && $currentDocument->hasUnpublishedChanges() ? ' · Entwurf' : '' }}</span>

                    <p class="rt-mail-studio-toolbar__message" data-mail-document-message aria-live="polite">
                        @if ($currentDocument->isPublished())
                            Veröffentlicht am {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                        @else
                            Noch nichts veröffentlicht — es gilt die heutige Blade-Quelle.
                        @endif
                    </p>

                    <x-ui.buttons.button-basic
                        type="button"
                        mode="primary"
                        size="sm"
                        class="min-h-11 shrink-0 rounded-lg px-3"
                        data-mail-document-publish
                        title="Gespeicherten Entwurf veröffentlichen"
                    >
                        <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
                        <span class="rt-mail-studio-toolbar__publish-label">Veröffentlichen</span>
                    </x-ui.buttons.button-basic>
                </div>
            </div>
        </x-slot:toolbar>
    @endif

    @if ($currentDocument === null)
        <div class="h-full overflow-y-auto p-3 sm:p-5">
            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200" role="status">
                <i data-feather="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                <div>
                    <p><strong>Die Maildokumente sind noch nicht eingerichtet.</strong></p>
                    <p class="mt-1 leading-6">
                        Solange nichts veröffentlicht ist, arbeiten Downloads und Systemmails unverändert mit den heutigen
                        Blade-Quellen weiter — es fehlt also nichts, es lässt sich nur nichts bearbeiten.
                        Zum Einrichten <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/10">php artisan migrate</code>
                        und <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/10">php artisan db:seed --class=MailDocumentSeeder</code> ausführen.
                    </p>
                </div>
            </div>
        </div>
    @else
        <script type="application/json" data-mail-document-config>{!! json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

        <div class="rt-mail-studio" data-mail-studio>
            {{-- Beanstandungen der Haertung. Sie werden nie stillschweigend
                 geschluckt: was der Sanitizer entfernt hat, steht hier. --}}
            <div class="hidden shrink-0 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200" data-mail-document-findings role="alert" hidden>
                <p class="font-semibold" data-mail-document-findings-title>Hinweise der Prüfung</p>
                <ul class="mt-1 list-disc space-y-1 pl-5 leading-6" data-mail-document-findings-list></ul>
            </div>

            <div class="rt-mail-editor-frame" data-mail-editor-frame data-preview-device="desktop">
                <div
                    id="mail-document-editor-{{ $currentDocument->public_id }}"
                    class="rt-mail-builder-root"
                    data-mail-document-root
                    wire:ignore
                >
                    <div class="rt-mail-editor-loading" role="status">
                        <span class="rt-mail-editor-loading__mark">RT</span>
                        <span>LMZ Page Builder wird geladen …</span>
                    </div>
                </div>
            </div>
        </div>

        @script
            <script>
                (async function () {
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

                    const studioRoot = workspace.closest('[data-page-builder-fullscreen-root]') || workspace;
                    const config = JSON.parse(workspace.querySelector('[data-mail-document-config]')?.textContent || '{}');
                    const document_ = config.documents?.[config.currentDocument];
                    const publishButton = studioRoot.querySelector('[data-mail-document-publish]');
                    const messageNode = studioRoot.querySelector('[data-mail-document-message]');
                    const findingsBox = studioRoot.querySelector('[data-mail-document-findings]');
                    const findingsList = studioRoot.querySelector('[data-mail-document-findings-list]');
                    const findingsTitle = studioRoot.querySelector('[data-mail-document-findings-title]');
                    const statusBadge = studioRoot.querySelector('[data-mail-document-status]');
                    const editorFrame = studioRoot.querySelector('[data-mail-editor-frame]');
                    const previewStatus = studioRoot.querySelector('[data-mail-preview-status]');
                    const themeButtons = Array.from(studioRoot.querySelectorAll('[data-mail-theme-button]'));
                    const deviceButtons = Array.from(studioRoot.querySelectorAll('[data-mail-preview-device]'));

                    if (!document_) {
                        return;
                    }

                    let instance = null;
                    let destroyed = false;
                    let selectedTheme = 'light';
                    let selectedDevice = 'desktop';
                    let unregisterNavigation = null;
                    const controlListeners = new AbortController();

                    const toast = (type, text, title) => window.dispatchEvent(new CustomEvent('swal:toast', {
                        detail: { type, text, title: title || undefined },
                    }));

                    const setMessage = (text) => {
                        if (messageNode) messageNode.textContent = text;
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

                    const updatePreviewStatus = (geometry = null) => {
                        if (!previewStatus) return;

                        const widths = { desktop: 1024, tablet: 820, mobile: 375 };
                        const labels = { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Mobil' };
                        const scale = geometry?.scale
                            ? ` · Fit ${Math.round(geometry.scale * 100)} %`
                            : '';
                        previewStatus.textContent = `${labels[selectedDevice]} · ${geometry?.logicalWidth || widths[selectedDevice]} px${scale}`;
                    };

                    const selectTheme = (theme) => {
                        selectedTheme = theme === 'dark' ? 'dark' : 'light';
                        editorFrame?.setAttribute('data-preview-theme', selectedTheme);
                        themeButtons.forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailThemeButton === selectedTheme));
                        });
                        instance?.setTheme?.(selectedTheme);
                    };

                    const selectDevice = (device) => {
                        selectedDevice = ['desktop', 'tablet', 'mobile'].includes(device) ? device : 'desktop';
                        deviceButtons.forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailPreviewDevice === selectedDevice));
                        });
                        instance?.setPreviewDevice?.(selectedDevice);
                        updatePreviewStatus(instance?.getPreviewGeometry?.());
                    };

                    const showFindings = (report) => {
                        if (!findingsBox || !findingsList) return;

                        const messages = Array.isArray(report?.messages) ? report.messages : [];
                        findingsList.replaceChildren();

                        if (messages.length === 0) {
                            findingsBox.hidden = true;
                            findingsBox.classList.add('hidden');
                            return;
                        }

                        const removed = (report.findings || []).some((finding) => finding.severity === 'violation');
                        if (findingsTitle) {
                            findingsTitle.textContent = removed
                                ? 'Die Prüfung hat Inhalte entfernt'
                                : 'Hinweise der Prüfung';
                        }

                        messages.forEach((message) => {
                            const item = window.document.createElement('li');
                            item.textContent = message;
                            findingsList.appendChild(item);
                        });

                        findingsBox.hidden = false;
                        findingsBox.classList.remove('hidden');
                    };

                    const applyDocumentState = (payload) => {
                        if (!payload) return;

                        // Pflicht: ohne den frischen Hash laeuft der naechste
                        // Autosave in die Konfliktmeldung.
                        document_.contentHash = payload.content_hash || document_.contentHash;
                        document_.version = payload.version ?? document_.version;
                        document_.status = payload.status || document_.status;
                        document_.hasUnpublishedChanges = Boolean(payload.has_unpublished_changes);

                        if (statusBadge) {
                            statusBadge.dataset.status = document_.status;
                            statusBadge.dataset.hasUnpublishedChanges = String(document_.hasUnpublishedChanges);
                            statusBadge.dataset.statusLabel = payload.status_label || statusBadge.dataset.statusLabel || statusBadge.textContent;
                            statusBadge.textContent = document_.status === 'published' && document_.hasUnpublishedChanges
                                ? `${statusBadge.dataset.statusLabel} · Entwurf`
                                : statusBadge.dataset.statusLabel;
                        }
                    };

                    const loadOnce = (tag, attributes) => new Promise((resolve, reject) => {
                        const selector = tag === 'link'
                            ? `link[href="${attributes.href}"]`
                            : `script[src="${attributes.src}"]`;

                        if (window.document.querySelector(selector)) {
                            resolve();
                            return;
                        }

                        const node = window.document.createElement(tag);
                        Object.assign(node, attributes);
                        node.addEventListener('load', resolve, { once: true });
                        node.addEventListener('error', () => reject(new Error('Der LMZ Page Builder konnte nicht geladen werden.')), { once: true });
                        window.document.head.appendChild(node);
                    });

                    const ensureRuntime = async () => {
                        if (window.LMZBuilder?.create) {
                            return window.LMZBuilder;
                        }

                        await Promise.all([
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.grapesCss }),
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.builderCss }),
                        ]);
                        await loadOnce('script', { src: config.vendor.builderJs, defer: true });

                        if (!window.LMZBuilder?.create) {
                            throw new Error('LMZ Page Builder 2.4.5 wurde nicht initialisiert.');
                        }

                        return window.LMZBuilder;
                    };

                    const waitForFullscreenActivation = async () => {
                        if (workspace.dataset.pageBuilderEditorActive !== 'false') return true;

                        const shellId = studioRoot.dataset.pageBuilderShellId || '';
                        return await new Promise((resolve) => {
                            const finish = (active) => {
                                window.removeEventListener('page-builder-shell:opened', opened);
                                document.removeEventListener('livewire:navigating', cancelled);
                                controlListeners.signal.removeEventListener('abort', cancelled);
                                resolve(active);
                            };
                            const opened = (event) => {
                                if (shellId && event.detail?.id && event.detail.id !== shellId) return;
                                finish(true);
                            };
                            const cancelled = () => finish(false);

                            window.addEventListener('page-builder-shell:opened', opened);
                            document.addEventListener('livewire:navigating', cancelled, { once: true });
                            controlListeners.signal.addEventListener('abort', cancelled, { once: true });
                        });
                    };

                    const request = async (url, method, body = null) => {
                        const response = await fetch(url, {
                            method,
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                ...(body ? { 'Content-Type': 'application/json' } : {}),
                            },
                            body: body ? JSON.stringify(body) : null,
                        });

                        let payload = {};
                        try {
                            payload = await response.json();
                        } catch (_) {
                            payload = {};
                        }

                        if (!response.ok) {
                            const validation = payload.errors
                                ? Object.values(payload.errors).flat().filter(Boolean)
                                : [];
                            const error = new Error(validation[0] || payload.message || `Anfrage fehlgeschlagen (${response.status}).`);
                            error.messages = validation;
                            throw error;
                        }

                        return payload;
                    };

                    const boot = async () => {
                        // Die Preview-Seite bleibt leichtgewichtig: Erst ein
                        // bewusster Vollbild-Start laedt LMZ/GrapesJS und CSS.
                        if (!(await waitForFullscreenActivation()) || destroyed) return;
                        const runtime = await ensureRuntime();

                        if (destroyed) return;

                        instance = await runtimeBridge.create({
                            runtime,
                            root,
                            projectId: `mail:${document_.id}`,
                            vendor: config.vendor,
                            theme: selectedTheme,
                            previewAssets: config.previewAssets || {},
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
                                    { kind: config.currentDocument, environment: window },
                                ),
                                onSave: async ({ project, html, css, editor }) => {
                                    const outgoing = runtimeBridge.serializeForSave({
                                        project,
                                        html,
                                        css,
                                        kind: config.currentDocument,
                                        baselineHtml: document_.html || '',
                                        previewAssets: config.previewAssets || {},
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

                                    // Die Serverfassung ist nach der
                                    // E-Mail-Haertung autoritativ. Vor allem
                                    // builder_data darf kein unsauberes
                                    // Parallel-Markup behalten.
                                    document_.builderData = payload.document?.builder_data ?? outgoing.project;
                                    document_.html = payload.document?.html ?? outgoing.html;
                                    document_.css = payload.document?.css ?? outgoing.css;
                                    applyDocumentState(payload.document);
                                    showFindings(payload.report);
                                    await runtimeBridge.rehydrateAuthoritative({
                                        editor,
                                        draft: document_,
                                        sanitizationChanged: (payload.report?.findings || [])
                                            .some((finding) => finding.severity === 'violation'),
                                        parseCss: (canonicalCss) => editor.Parser?.parseCss?.(canonicalCss) || [],
                                        projectOptions: { kind: config.currentDocument, environment: window },
                                    });
                                    setMessage(document_.hasUnpublishedChanges
                                        ? 'Gespeichert — noch nicht veröffentlicht.'
                                        : 'Gespeichert.');
                                },
                            },
                        });

                        if (destroyed) {
                            instance.destroy();
                            instance = null;
                            return;
                        }

                        selectTheme(selectedTheme);
                        selectDevice(selectedDevice);
                    };

                    themeButtons.forEach((button) => {
                        button.addEventListener('click', () => selectTheme(button.dataset.mailThemeButton), {
                            signal: controlListeners.signal,
                        });
                    });

                    deviceButtons.forEach((button) => {
                        button.addEventListener('click', () => selectDevice(button.dataset.mailPreviewDevice), {
                            signal: controlListeners.signal,
                        });
                    });

                    publishButton?.addEventListener('click', async () => {
                        publishButton.disabled = true;

                        try {
                            // Erst der Arbeitsstand, dann die Freigabe:
                            // veroeffentlicht wird der gespeicherte Entwurf.
                            if (instance && instance.hasUnsavedChanges() && !(await instance.save('manual'))) {
                                throw new Error('Der Entwurf konnte nicht gespeichert werden.');
                            }

                            const payload = await request(document_.endpoints.publish, 'POST', {
                                expected_hash: document_.contentHash || '',
                            });
                            applyDocumentState(payload.document);
                            showFindings(payload.report);
                            setMessage(`Veröffentlicht am ${payload.document?.published_label ?? ''} Uhr.`);
                            const successText = config.currentDocument === 'signature'
                                ? 'Signaturdownloads und Systemmails verwenden ab sofort diese Fassung.'
                                : 'HTML- und EML-Vorlagendownloads verwenden ab sofort diese Fassung.';
                            toast('success', successText, 'Veröffentlicht');
                        } catch (error) {
                            showFindings({ messages: error.messages || [error.message], findings: [{ severity: 'violation' }] });
                            toast('error', error.message, 'Nicht veröffentlicht');
                        } finally {
                            publishButton.disabled = false;
                        }
                    });

                    boot().catch((error) => {
                        if (destroyed) return;

                        root.innerHTML = '';
                        const notice = window.document.createElement('div');
                        notice.className = 'rt-mail-editor-error';
                        notice.setAttribute('role', 'alert');
                        notice.textContent = `Editor konnte nicht geladen werden: ${error.message}`;
                        root.appendChild(notice);
                        toast('error', error.message, 'E-Mail-Editor nicht verfügbar');
                    });

                    const teardown = () => {
                        destroyed = true;
                        controlListeners.abort();
                        unregisterNavigation?.();
                        unregisterNavigation = null;
                        instance?.destroy?.();
                        instance = null;
                        window.RailTimeMailDocumentEditor = null;
                        window.document.removeEventListener('livewire:navigating', teardown);
                    };

                    window.document.addEventListener('livewire:navigating', teardown);
                    window.RailTimeMailDocumentEditor = {
                        destroy: teardown,
                        hasUnsavedChanges: () => Boolean(instance?.hasUnsavedChanges?.()),
                    };
                })();
            </script>
        @endscript
    @endif
</x-ui.page-builder.editor-shell>
