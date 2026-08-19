@section('title', 'Mail- & Signatur-Editor')

@php
    $kinds = [
        \App\Enums\MailDocumentKind::Template->value => ['Nachrichtenvorlage', 'Mail-Notifications und Systemmails'],
        \App\Enums\MailDocumentKind::Signature->value => ['Signaturblock', 'Outlook-Paket und Systemmails'],
    ];
@endphp

<x-ui.page-builder.editor-shell
    title="Mail- & Signatur-Editor"
    eyebrow="E-Mail-Vorlagen"
    description="Nachrichtenschale und Signaturblock zentral bearbeiten. Die veröffentlichten Fassungen gelten für Downloads und Systemmails."
    :back-url="route('email-templates.index')"
    back-label="Zur Vorlagen-Seite"
    :preview-sources="$editorPreviewSources"
    preview-default="light"
    preview-replayable
    :preview-loading-overlay="false"
    :auto-open="request()->boolean('open')"
    workspace-class="min-h-0 flex-1 overflow-hidden p-0"
    data-mail-document-studio
    data-mail-document-back
>
    @if ($currentDocument !== null)
        <x-slot:toolbar>
            <div class="rt-mail-studio-toolbar" data-mail-studio-toolbar data-mail-toolbar-layout="responsive">
                <div class="rt-mail-studio-toolbar__documents" data-mail-toolbar-region="documents" role="group" aria-label="Dokument auswählen">
                    @foreach ($kinds as $kindValue => [$kindLabel, $kindHint])
                        <a
                            href="{{ route('admin.mail-documents.editor', ['dokument' => $kindValue, 'open' => 1]) }}"
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

                <div class="rt-mail-studio-toolbar__preview" data-mail-toolbar-region="preview" data-mail-preview-toolbar>
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

                    <button
                        type="button"
                        data-mail-preview-replay
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 text-xs font-semibold text-rt-accent ring-1 ring-inset ring-rt-accent/25 transition hover:bg-rt-accent-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30 dark:text-rt-dark-accent dark:ring-rt-dark-accent/25 dark:hover:bg-rt-dark-accent-soft"
                        title="Alle GIF-Animationen in der Editorvorschau neu starten"
                    >
                        <i data-feather="rotate-cw" class="h-4 w-4" aria-hidden="true"></i>
                        <span>Animation neu starten</span>
                    </button>
                </div>

                <div class="rt-mail-studio-toolbar__actions" data-mail-toolbar-region="actions">
                    <span
                        data-mail-document-status
                        data-status="{{ $currentDocument->status->value }}"
                        data-status-label="{{ $currentDocument->status->label() }}"
                        data-has-unpublished-changes="{{ $currentDocument->hasUnpublishedChanges() ? 'true' : 'false' }}"
                        class="rt-mail-document-status"
                    >{{ $currentDocument->isPublished() && $currentDocument->hasUnpublishedChanges() ? 'Entwurf' : $currentDocument->status->label() }}</span>

                    <p class="rt-mail-studio-toolbar__message" data-mail-document-message aria-live="polite">
                        @if ($currentDocument->isPublished())
                            @if ($currentDocument->hasUnpublishedChanges())
                                Entwurf gespeichert — Systemmails verwenden weiterhin die Veröffentlichung vom {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                            @else
                                Systemmails verwenden die Veröffentlichung vom {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                            @endif
                        @else
                            Nicht veröffentlicht — Systemmails bleiben bis zur Freigabe gesperrt.
                        @endif
                    </p>

                    <div class="rt-mail-studio-toolbar__action-buttons" role="group" aria-label="Code, Import, Export, Entwurf und Veröffentlichung">
                        <x-ui.buttons.button-basic
                            type="button"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-code-open
                            title="Kanonischen HTML- und CSS-Code ansehen oder bearbeiten"
                        >
                            <i data-feather="code" class="h-4 w-4" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label rt-mail-studio-toolbar__utility-label">Code</span>
                        </x-ui.buttons.button-basic>

                        <x-ui.buttons.button-basic
                            type="button"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-code-export
                            title="Kanonischen Entwurf als portables JSON-Bundle exportieren"
                        >
                            <i data-feather="download" class="h-4 w-4" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label rt-mail-studio-toolbar__utility-label">Export</span>
                        </x-ui.buttons.button-basic>

                        <x-ui.buttons.button-basic
                            type="button"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-code-import
                            title="JSON-Bundle, HTML- oder CSS-Datei als Entwurf importieren"
                        >
                            <i data-feather="file-plus" class="h-4 w-4" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label rt-mail-studio-toolbar__utility-label">Import</span>
                        </x-ui.buttons.button-basic>

                        <x-ui.buttons.button-basic
                            type="button"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-document-save
                            title="Aktuellen Arbeitsstand als Entwurf speichern"
                        >
                            <i data-feather="save" class="h-4 w-4" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label">Speichern</span>
                        </x-ui.buttons.button-basic>

                        <x-ui.buttons.button-basic
                            type="button"
                            mode="primary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-document-publish
                            title="Aktuellen Entwurf speichern und für Mail-Notifications sowie Systemmails veröffentlichen"
                        >
                            <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label">Speichern &amp; veröffentlichen</span>
                        </x-ui.buttons.button-basic>
                    </div>
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
                        Ohne die beiden veröffentlichten Dokumente sind Downloads und Systemmails bewusst gesperrt.
                        Zum autoritativen Einrichten <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/10">php artisan migrate --force</code>
                        und danach <code class="rounded bg-black/5 px-1 py-0.5 dark:bg-white/10">php artisan db:seed --class=MailDocumentSeeder --force</code> ausführen.
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

        <input
            type="file"
            class="hidden"
            accept="application/json,.json,text/html,.html,.htm,text/css,.css"
            data-mail-code-import-file
            aria-hidden="true"
            tabindex="-1"
        >

        <dialog
            class="rt-mail-code-dialog"
            data-mail-code-dialog
            aria-labelledby="rt-mail-code-dialog-title-{{ $currentDocument->public_id }}"
            aria-describedby="rt-mail-code-dialog-description-{{ $currentDocument->public_id }}"
        >
            <form method="dialog" class="rt-mail-code-dialog__surface">
                <header class="rt-mail-code-dialog__header">
                    <div>
                        <p class="rt-mail-code-dialog__eyebrow">Mail-kompatibler Quellcode</p>
                        <h2 id="rt-mail-code-dialog-title-{{ $currentDocument->public_id }}">HTML &amp; CSS bearbeiten</h2>
                        <p id="rt-mail-code-dialog-description-{{ $currentDocument->public_id }}" data-mail-code-origin>
                            Kanonischer Stand des aktuellen Entwurfs
                        </p>
                    </div>
                    <button type="submit" value="cancel" class="rt-mail-code-dialog__close" aria-label="Codeansicht schließen">
                        <i data-feather="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="rt-mail-code-dialog__notice" role="note">
                    <i data-feather="shield" class="h-5 w-5" aria-hidden="true"></i>
                    <p>
                        Beim Übernehmen wird der Code zuerst serverseitig geprüft und anschließend über denselben sicheren Speicherweg als
                        <strong>Entwurf</strong> gespeichert. Die veröffentlichte Fassung ändert sich dadurch nicht.
                    </p>
                </div>

                <div class="rt-mail-code-dialog__editors">
                    <label class="rt-mail-code-dialog__field">
                        <span>HTML</span>
                        <textarea
                            data-mail-code-html
                            spellcheck="false"
                            autocomplete="off"
                            autocapitalize="off"
                            wrap="off"
                            aria-label="HTML-Code des Maildokuments"
                        ></textarea>
                    </label>

                    <label class="rt-mail-code-dialog__field">
                        <span>CSS</span>
                        <textarea
                            data-mail-code-css
                            spellcheck="false"
                            autocomplete="off"
                            autocapitalize="off"
                            wrap="off"
                            aria-label="CSS-Code des Maildokuments"
                        ></textarea>
                    </label>
                </div>

                <footer class="rt-mail-code-dialog__footer">
                    <p data-mail-code-size aria-live="polite">Maximal 1 MiB pro Importdatei.</p>
                    <div class="rt-mail-code-dialog__footer-actions">
                        <x-ui.buttons.button-basic
                            type="submit"
                            value="cancel"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 rounded-lg px-4"
                        >Abbrechen</x-ui.buttons.button-basic>

                        <x-ui.buttons.button-basic
                            type="button"
                            mode="primary"
                            size="sm"
                            class="min-h-11 rounded-lg px-4"
                            data-mail-code-apply
                        >
                            <i data-feather="shield-check" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Prüfen &amp; als Entwurf speichern</span>
                        </x-ui.buttons.button-basic>
                    </div>
                </footer>
            </form>
        </dialog>

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
                    const saveButton = studioRoot.querySelector('[data-mail-document-save]');
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
                    const replayButton = studioRoot.querySelector('[data-mail-preview-replay]');
                    const codeButton = studioRoot.querySelector('[data-mail-code-open]');
                    const exportButton = studioRoot.querySelector('[data-mail-code-export]');
                    const importButton = studioRoot.querySelector('[data-mail-code-import]');
                    const importFile = studioRoot.querySelector('[data-mail-code-import-file]');
                    const codeDialog = studioRoot.querySelector('[data-mail-code-dialog]');
                    const codeHtml = studioRoot.querySelector('[data-mail-code-html]');
                    const codeCss = studioRoot.querySelector('[data-mail-code-css]');
                    const codeOrigin = studioRoot.querySelector('[data-mail-code-origin]');
                    const codeSize = studioRoot.querySelector('[data-mail-code-size]');
                    const codeApplyButton = studioRoot.querySelector('[data-mail-code-apply]');

                    if (!document_) {
                        return;
                    }

                    let instance = null;
                    let destroyed = false;
                    let selectedTheme = 'light';
                    let selectedDevice = 'desktop';
                    let unregisterNavigation = null;
                    let lastEditorSaveError = null;
                    let activeBaselineHtml = String(document_.html || '');
                    let codeDialogOpener = null;
                    const controlListeners = new AbortController();
                    const MAIL_SOURCE_FORMAT = 'railtime-mail-document';
                    const MAIL_SOURCE_VERSION = 1;
                    const MAX_IMPORT_BYTES = 1024 * 1024;

                    const toast = (type, text, title) => window.dispatchEvent(new CustomEvent('swal:toast', {
                        detail: { type, text, title: title || undefined },
                    }));

                    const setMessage = (text) => {
                        if (messageNode) messageNode.textContent = text;
                    };

                    const setActionsBusy = (busy) => {
                        [
                            saveButton,
                            publishButton,
                            codeButton,
                            exportButton,
                            importButton,
                            codeApplyButton,
                        ].forEach((button) => {
                            if (!button) return;

                            button.disabled = busy;
                            button.setAttribute('aria-busy', String(busy));
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
                        const explicitTitle = typeof report?.title === 'string' ? report.title.trim() : '';
                        if (findingsTitle) {
                            findingsTitle.textContent = explicitTitle || (removed
                                ? 'Die Prüfung hat Inhalte entfernt'
                                : 'Hinweise der Prüfung');
                        }

                        messages.forEach((message) => {
                            const item = window.document.createElement('li');
                            item.textContent = message;
                            findingsList.appendChild(item);
                        });

                        findingsBox.hidden = false;
                        findingsBox.classList.remove('hidden');
                    };

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

                        if (statusBadge) {
                            statusBadge.dataset.status = document_.status;
                            statusBadge.dataset.hasUnpublishedChanges = String(document_.hasUnpublishedChanges);
                            statusBadge.dataset.statusLabel = payload.status_label || statusBadge.dataset.statusLabel || statusBadge.textContent;
                            statusBadge.textContent = document_.status === 'published' && document_.hasUnpublishedChanges
                                ? 'Entwurf'
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
                        if (enforceLimit && bytes > MAX_IMPORT_BYTES) {
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

                    const currentCanonicalSource = () => {
                        const editor = instance?.editor;
                        if (!editor?.getProjectData || !editor?.getHtml || !editor?.getCss) {
                            throw new Error('Der Editor ist noch nicht vollständig geladen.');
                        }

                        const outgoing = runtimeBridge.serializeForSave({
                            project: editor.getProjectData(),
                            html: editor.getHtml(),
                            css: editor.getCss(),
                            kind: config.currentDocument,
                            baselineHtml: activeBaselineHtml,
                            previewAssets: config.previewAssets || {},
                            environment: editor.Canvas?.getWindow?.()
                                || editor.Canvas?.getDocument?.()?.defaultView
                                || window,
                        });

                        // Das Exportformat enthaelt absichtlich weder
                        // builder_data noch Vorschau-/Asset-Konfiguration.
                        return assertPortableSource({
                            html: String(outgoing.html || ''),
                            css: String(outgoing.css || ''),
                        }, { enforceLimit: false });
                    };

                    const importProjectFor = ({ html, css }) => {
                        const editor = instance?.editor;
                        if (!editor?.Parser?.parseCss) {
                            throw new Error('Der Editor ist noch nicht vollständig geladen.');
                        }

                        const existingMetadata = document_.builderData?.railtime;
                        const railtime = { document: config.currentDocument };
                        if (Number.isInteger(existingMetadata?.schema)) {
                            railtime.schema = existingMetadata.schema;
                        }

                        return runtimeBridge.projectFor({
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
                        }, (candidateCss) => editor.Parser.parseCss(candidateCss) || [], {
                            kind: config.currentDocument,
                            environment: window,
                        });
                    };

                    const canonicalizeExternalSource = (source) => {
                        const editor = instance?.editor;
                        const checked = assertPortableSource(source);
                        const project = importProjectFor(checked);
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
                            environment: editor.Canvas?.getWindow?.()
                                || editor.Canvas?.getDocument?.()?.defaultView
                                || window,
                        });
                    };

                    const updateCodeSize = () => {
                        if (!codeSize) return;

                        const bytes = sourceSize({ html: codeHtml?.value || '', css: codeCss?.value || '' });
                        codeSize.textContent = `${formatBytes(bytes)} von maximal 1 MiB`;
                        codeSize.dataset.overLimit = String(bytes > MAX_IMPORT_BYTES);
                    };

                    const openCodeDialog = (source, origin, opener) => {
                        if (!codeDialog?.showModal || !codeHtml || !codeCss) {
                            throw new Error('Die Codeansicht wird von diesem Browser nicht unterstützt.');
                        }

                        codeHtml.value = source.html;
                        codeCss.value = source.css;
                        if (codeOrigin) codeOrigin.textContent = origin;
                        codeDialogOpener = opener || window.document.activeElement;
                        updateCodeSize();
                        if (!codeDialog.open) codeDialog.showModal();
                        window.requestAnimationFrame(() => codeHtml.focus());
                    };

                    const portableBundle = (source) => ({
                        format: MAIL_SOURCE_FORMAT,
                        version: MAIL_SOURCE_VERSION,
                        kind: config.currentDocument,
                        html: source.html,
                        css: source.css,
                    });

                    const downloadPortableBundle = (source) => {
                        const bundle = portableBundle(source);
                        const blob = new Blob([`${JSON.stringify(bundle, null, 2)}\n`], {
                            type: 'application/json;charset=utf-8',
                        });
                        const objectUrl = URL.createObjectURL(blob);
                        const link = window.document.createElement('a');
                        const documentName = config.currentDocument === 'signature' ? 'signatur' : 'nachrichtenvorlage';
                        link.href = objectUrl;
                        link.download = `railtime-${documentName}-v${MAIL_SOURCE_VERSION}.json`;
                        link.hidden = true;
                        window.document.body.appendChild(link);

                        try {
                            link.click();
                        } finally {
                            link.remove();
                            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
                        }
                    };

                    const parsePortableBundle = (text) => {
                        let bundle;
                        try {
                            bundle = JSON.parse(String(text || '').replace(/^\uFEFF/, ''));
                        } catch (_) {
                            throw new Error('Die JSON-Datei ist nicht gültig.');
                        }

                        if (!bundle || Array.isArray(bundle) || typeof bundle !== 'object'
                            || bundle.format !== MAIL_SOURCE_FORMAT
                            || bundle.version !== MAIL_SOURCE_VERSION) {
                            throw new Error(`Erwartet wird ein RailTime-Mail-Bundle in Version ${MAIL_SOURCE_VERSION}.`);
                        }
                        if (bundle.kind !== config.currentDocument) {
                            const expected = config.currentDocument === 'signature' ? 'eine Signatur' : 'eine Nachrichtenvorlage';
                            throw new Error(`Dieses Bundle gehört zu „${bundle.kind || 'unbekannt'}“. Geöffnet ist ${expected}.`);
                        }

                        return assertPortableSource({ html: bundle.html, css: bundle.css });
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
                            assets: config.mailAssets || [],
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
                                    lastEditorSaveError = null;

                                    try {
                                        const outgoing = runtimeBridge.serializeForSave({
                                            project,
                                            html,
                                            css,
                                            kind: config.currentDocument,
                                            baselineHtml: activeBaselineHtml,
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
                                        activeBaselineHtml = document_.html;
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
                                    }
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

                    replayButton?.addEventListener('click', () => {
                        const restarted = Number(instance?.restartAllGifs?.() || 0);
                        setMessage(restarted > 0
                            ? `${restarted} Animation${restarted === 1 ? '' : 'en'} neu gestartet.`
                            : 'In der aktuellen Vorschau wurde keine GIF-Animation gefunden.');
                    }, { signal: controlListeners.signal });

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

                    const validateSourceOnServer = async (source) => {
                        if (typeof document_.endpoints?.validate !== 'string' || document_.endpoints.validate.trim() === '') {
                            throw new Error('Der sichere Prüf-Endpunkt für Codeimporte ist nicht verfügbar. Es wurde nichts übernommen.');
                        }

                        const candidate = canonicalizeExternalSource(source);
                        const payload = await request(document_.endpoints.validate, 'POST', {
                            builder_data: candidate.project,
                            html: candidate.html,
                            css: candidate.css,
                            expected_hash: document_.contentHash || '',
                        });

                        return {
                            draft: canonicalDraftFromValidation(payload),
                            report: payload.report || null,
                        };
                    };

                    const applyCodeAsDraft = async () => {
                        if (!instance?.editor?.loadProjectData) {
                            throw new Error('Der Editor ist noch nicht vollständig geladen.');
                        }

                        const source = assertPortableSource({
                            html: codeHtml?.value || '',
                            css: codeCss?.value || '',
                        });

                        // Bis hier wurde der laufende Editor nicht verändert.
                        // Erst der serverseitig kanonisierte Stand darf auf die
                        // Leinwand und danach durch den normalen Save-Request.
                        setMessage('Code wird serverseitig geprüft …');
                        const validated = await validateSourceOnServer(source);
                        const editor = instance.editor;
                        const previousProject = structuredClone(editor.getProjectData());
                        const previousBaselineHtml = activeBaselineHtml;
                        let editorWasReplaced = false;

                        try {
                            const validatedProject = runtimeBridge.projectFor(
                                validated.draft,
                                (canonicalCss) => editor.Parser?.parseCss?.(canonicalCss) || [],
                                { kind: config.currentDocument, environment: window },
                            );
                            activeBaselineHtml = validated.draft.html;
                            editorWasReplaced = true;
                            await editor.loadProjectData(validatedProject);
                            selectTheme(selectedTheme);
                            selectDevice(selectedDevice);
                            await saveCurrentDraft();

                            if (Array.isArray(validated.report?.messages) && validated.report.messages.length > 0) {
                                showFindings(validated.report);
                            }

                            const message = 'Code geprüft und als Entwurf gespeichert. Die veröffentlichte Fassung bleibt unverändert.';
                            setMessage(message);
                            toast('success', message, 'Import abgeschlossen');
                            codeDialog?.close('saved');
                        } catch (error) {
                            activeBaselineHtml = previousBaselineHtml;

                            if (editorWasReplaced) {
                                try {
                                    await editor.loadProjectData(previousProject);
                                    selectTheme(selectedTheme);
                                    selectDevice(selectedDevice);
                                } catch (_) {
                                    await runtimeBridge.rehydrateAuthoritative({
                                        editor,
                                        draft: document_,
                                        sanitizationChanged: true,
                                        parseCss: (canonicalCss) => editor.Parser?.parseCss?.(canonicalCss) || [],
                                        projectOptions: { kind: config.currentDocument, environment: window },
                                    });
                                }
                            }

                            throw error;
                        }
                    };

                    codeButton?.addEventListener('click', () => {
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
                    }, { signal: controlListeners.signal });

                    exportButton?.addEventListener('click', () => {
                        try {
                            downloadPortableBundle(currentCanonicalSource());
                            setMessage('Portables JSON-Bundle wurde exportiert.');
                            toast('success', 'Das Bundle enthält nur kind, HTML und CSS — keine Vorschau- oder privaten Assetdaten.', 'Export erstellt');
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Export nicht möglich');
                            toast('error', surfaced.message, 'Export nicht möglich');
                        }
                    }, { signal: controlListeners.signal });

                    importButton?.addEventListener('click', () => {
                        if (!instance) {
                            const error = new Error('Der Editor ist noch nicht vollständig geladen.');
                            const surfaced = showRequestError(error, 'Import nicht möglich');
                            toast('error', surfaced.message, 'Import nicht möglich');
                            return;
                        }

                        codeDialogOpener = importButton;
                        importFile?.click();
                    }, { signal: controlListeners.signal });

                    importFile?.addEventListener('change', async () => {
                        const file = importFile.files?.[0] || null;
                        importFile.value = '';
                        if (!file) return;

                        try {
                            if (file.size > MAX_IMPORT_BYTES) {
                                throw new Error(`„${file.name}“ ist größer als 1 MiB und wurde nicht gelesen.`);
                            }

                            const extension = file.name.toLowerCase().match(/\.[^.]+$/)?.[0] || '';
                            if (!['.json', '.html', '.htm', '.css'].includes(extension)) {
                                throw new Error('Unterstützt werden ausschließlich .json, .html, .htm und .css.');
                            }

                            const text = await file.text();
                            if (destroyed) return;
                            let source;
                            if (extension === '.json') {
                                source = parsePortableBundle(text);
                            } else {
                                const current = currentCanonicalSource();
                                source = extension === '.css'
                                    ? assertPortableSource({ html: current.html, css: text })
                                    : assertPortableSource({ html: text, css: current.css });
                            }

                            openCodeDialog(source, `Importdatei: ${file.name} · Übernahme speichert einen Entwurf`, codeDialogOpener);
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Import nicht möglich');
                            toast('error', surfaced.message, 'Import nicht möglich');
                        }
                    }, { signal: controlListeners.signal });

                    [codeHtml, codeCss].forEach((field) => {
                        field?.addEventListener('input', updateCodeSize, { signal: controlListeners.signal });
                    });

                    codeDialog?.addEventListener('close', () => {
                        const opener = codeDialogOpener;
                        codeDialogOpener = null;
                        if (!destroyed && opener?.isConnected) opener.focus();
                    }, { signal: controlListeners.signal });

                    codeApplyButton?.addEventListener('click', async () => {
                        setActionsBusy(true);
                        if (codeHtml) codeHtml.readOnly = true;
                        if (codeCss) codeCss.readOnly = true;

                        try {
                            await applyCodeAsDraft();
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Code konnte nicht übernommen werden');
                            toast('error', surfaced.message, 'Nicht gespeichert');
                        } finally {
                            if (codeHtml) codeHtml.readOnly = false;
                            if (codeCss) codeCss.readOnly = false;
                            setActionsBusy(false);
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
                            showFindings(payload.report);
                            setMessage(`Veröffentlicht am ${payload.document?.published_label ?? ''} Uhr — diese Fassung wird jetzt für Systemmails verwendet.`);
                            const successText = config.currentDocument === 'signature'
                                ? 'Outlook-Paket und Systemmails verwenden ab sofort diese Signatur.'
                                : 'Mail-Notifications und Systemmails verwenden ab sofort diese Nachrichtenschale.';
                            toast('success', successText, 'Veröffentlicht');
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Veröffentlichung nicht möglich');
                            toast('error', surfaced.message, 'Nicht veröffentlicht');
                        } finally {
                            setActionsBusy(false);
                        }
                    }, { signal: controlListeners.signal });

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
                        codeDialogOpener = null;
                        if (codeDialog?.open) codeDialog.close('teardown');
                        if (importFile) importFile.value = '';
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
