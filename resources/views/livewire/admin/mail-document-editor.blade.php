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
                        <small data-mail-preview-status aria-live="polite">Systemmail breit · 1920 px · wird eingepasst</small>
                    </div>

                    <div class="rt-mail-preview-toggle" role="group" aria-label="Editoransicht">
                        <button type="button" data-mail-view-mode="edit" aria-pressed="true" title="Bearbeitbare Mail-Leinwand anzeigen">
                            <i data-feather="edit-3" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Bearbeiten</span>
                        </button>
                        <button type="button" data-mail-view-mode="delivery" aria-pressed="false" title="Aktuellen Stand mit dem produktiven Systemmail-Compiler anzeigen">
                            <i data-feather="mail" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Versandansicht</span>
                        </button>
                    </div>

                    <div class="rt-mail-preview-toggle" data-mail-theme-controls role="group" aria-label="Farbschema der Editorvorschau">
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
                        <button type="button" data-mail-preview-device="wide" aria-pressed="true" title="Breite Systemmail-Vorschau mit 1920 Pixeln">
                            <i data-feather="maximize-2" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Breit</span>
                        </button>
                        <button type="button" data-mail-preview-device="desktop" aria-pressed="false" title="Desktop-Vorschau mit 1024 Pixeln">
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

                    <label class="rt-mail-preview-width-control" title="Ganzzahlige Breite der Responsive-Vorschau">
                        <span class="sr-only">Individuelle Vorschau-Breite in Pixeln</span>
                        <input
                            type="number"
                            min="320"
                            max="1920"
                            step="1"
                            inputmode="numeric"
                            value="1920"
                            data-mail-preview-width
                            aria-label="Individuelle Vorschau-Breite in ganzen Pixeln"
                        >
                        <span aria-hidden="true">px</span>
                    </label>

                    <label class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-rt-border bg-rt-surface px-2 text-xs font-semibold text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text">
                        <i data-feather="shield" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                        <span class="sr-only">Robustheitsvorschau</span>
                        <select
                            data-mail-degradation-mode
                            class="min-h-9 max-w-44 border-0 bg-transparent pr-7 text-xs font-semibold focus:outline-none focus:ring-0 dark:bg-rt-dark-surface"
                            aria-label="Robustheitsvorschau auswählen; keine Mailclient-Emulation"
                            title="Heuristische Robustheitsvorschau – keine Outlook- oder Gmail-Emulation"
                        >
                            <option value="normal">Normale Vorschau</option>
                            <option value="images-off">Bilder aus</option>
                            <option value="head-css-off">Head-CSS aus</option>
                            <option value="css-off">Gesamtes CSS aus</option>
                        </select>
                    </label>

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
                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="96"
                            :offset="8"
                            dropdown-id="mail-document-versions-{{ $currentDocument->kind->value }}"
                            layer-group="mail-document-editor"
                            content-role="dialog"
                            content-label="Gespeicherte Versionen"
                            content-classes="bg-rt-surface p-1.5 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                            dropdown-classes="shadow-xl"
                            data-mail-document-version
                        >
                            <x-slot:trigger>
                                <x-ui.buttons.button-basic
                                    type="button"
                                    mode="secondary"
                                    size="sm"
                                    class="min-h-11 min-w-0 shrink-0 rounded-lg px-3"
                                    data-mail-document-version-trigger
                                    title="Gespeicherte Version auswählen"
                                >
                                    <i data-feather="history" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                                    <span data-mail-document-version-trigger-label class="max-w-40 truncate">Versionen</span>
                                    <i data-feather="chevron-down" class="h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"></i>
                                </x-ui.buttons.button-basic>
                            </x-slot:trigger>

                            <x-slot:content>
                                <div class="grid gap-1.5">
                                    <span id="mail-document-version-label" class="px-3 pb-1 pt-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                                        Gespeicherte Version
                                    </span>
                                    <div
                                        id="mail-document-version-listbox"
                                        role="listbox"
                                        aria-labelledby="mail-document-version-label"
                                        class="max-h-72 space-y-1 overflow-y-auto"
                                        data-mail-document-version-list
                                    ></div>
                                    <div class="border-t border-rt-border px-1.5 pt-1.5 dark:border-rt-dark-border">
                                        <x-ui.buttons.button-basic
                                            type="button"
                                            mode="secondary"
                                            size="sm"
                                            class="min-h-11 w-full justify-start rounded-lg px-3"
                                            data-mail-document-version-restore
                                            disabled
                                            title="Ausgewählte Version als neuen Entwurf wiederherstellen"
                                        >
                                            <i data-feather="clock" class="h-4 w-4" aria-hidden="true"></i>
                                            <span class="rt-mail-studio-toolbar__utility-label">Wiederherstellen</span>
                                        </x-ui.buttons.button-basic>
                                    </div>
                                </div>
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>

                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="80"
                            :offset="8"
                            dropdown-id="mail-document-tools-{{ $currentDocument->kind->value }}"
                            layer-group="mail-document-editor"
                            content-role="dialog"
                            content-label="Werkzeuge des E-Mail-Editors"
                            content-classes="bg-rt-surface p-3 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                            dropdown-classes="shadow-xl"
                            data-mail-more-actions
                        >
                            <x-slot:trigger>
                                <x-ui.buttons.button-basic
                                    type="button"
                                    mode="secondary"
                                    size="sm"
                                    class="min-h-11 shrink-0 rounded-lg px-3"
                                    title="Weitere Werkzeuge des E-Mail-Editors öffnen"
                                >
                                    <i data-feather="more-horizontal" class="h-4 w-4" aria-hidden="true"></i>
                                    <span>Werkzeuge</span>
                                    <i data-feather="chevron-down" class="h-3.5 w-3.5 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"></i>
                                </x-ui.buttons.button-basic>
                            </x-slot:trigger>

                            <x-slot:content>
                                <div class="grid gap-2">
                                    <x-ui.buttons.button-basic
                                        type="button"
                                        mode="secondary"
                                        size="sm"
                                        class="min-h-11 w-full justify-start rounded-lg px-3"
                                        data-mail-document-test-mail
                                        title="Testmail an die Admin-E-Mail-Adresse der Systemeinstellungen senden"
                                    >
                                        <i data-feather="send" class="h-4 w-4" aria-hidden="true"></i>
                                        <span class="rt-mail-studio-toolbar__utility-label">Testmail</span>
                                    </x-ui.buttons.button-basic>

                                    <x-ui.buttons.button-basic
                                        type="button"
                                        mode="secondary"
                                        size="sm"
                                        class="min-h-11 w-full justify-start rounded-lg px-3"
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
                                        class="min-h-11 w-full justify-start rounded-lg px-3"
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
                                        class="min-h-11 w-full justify-start rounded-lg px-3"
                                        data-mail-code-import
                                        title="JSON-Bundle, HTML- oder CSS-Datei als Entwurf importieren"
                                    >
                                        <i data-feather="file-plus" class="h-4 w-4" aria-hidden="true"></i>
                                        <span class="rt-mail-studio-toolbar__action-label rt-mail-studio-toolbar__utility-label">Import</span>
                                    </x-ui.buttons.button-basic>

                                </div>
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>

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
        <div class="h-full overflow-y-auto p-3 sm:p-5" data-mail-document-bootstrap data-kind="{{ $currentKind }}" data-endpoint="{{ route('admin.mail-documents.import') }}">
            <div class="mx-auto max-w-3xl space-y-4">
                <nav class="grid gap-2 sm:grid-cols-2" aria-label="Maildokument auswählen">
                    @foreach ($kinds as $kindValue => [$kindLabel, $kindHint])
                        <a
                            href="{{ route('admin.mail-documents.editor', ['dokument' => $kindValue, 'open' => 1]) }}"
                            wire:navigate
                            aria-current="{{ $currentKind === $kindValue ? 'page' : 'false' }}"
                            class="rounded-xl border px-4 py-3 text-sm transition {{ $currentKind === $kindValue ? 'border-rt-accent bg-rt-accent-soft text-rt-accent' : 'border-slate-200 bg-white text-slate-700 hover:border-rt-accent/40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200' }}"
                        >
                            <strong class="block">{{ $kindLabel }}</strong>
                            <span class="mt-1 block text-xs opacity-75">{{ $kindHint }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
                    <div class="flex items-start gap-3">
                        <i data-feather="upload" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true"></i>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-semibold">{{ $kinds[$currentKind][0] }} per Exportdatei einrichten</h2>
                            <p class="mt-1 leading-6">
                                Dieses Dokument fehlt noch. Wähle ein vollständiges RailTime-JSON-Bundle aus; es wird geprüft und ausschließlich als Entwurf angelegt. Vorhandene Dokumente werden nie überschrieben. Systemmails bleiben bis zur ausdrücklichen Veröffentlichung gesperrt.
                            </p>
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-rt-accent px-4 py-2 font-semibold text-white transition hover:bg-rt-accent/90 disabled:cursor-wait disabled:opacity-60" data-mail-document-bootstrap-button>
                                    <i data-feather="file-plus" class="h-4 w-4" aria-hidden="true"></i>
                                    JSON-Bundle importieren
                                </button>
                                <span class="text-xs" data-mail-document-bootstrap-status aria-live="polite">Format v2 · maximal 16 MiB</span>
                            </div>
                            <p class="mt-3 hidden rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" data-mail-document-bootstrap-error role="alert" hidden></p>
                            <input type="file" class="hidden" accept="application/json,.json" data-mail-document-bootstrap-file tabindex="-1" aria-hidden="true">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @script
            <script>
                (() => {
                    const root = $wire.$el.querySelector('[data-mail-document-bootstrap]');
                    const button = root?.querySelector('[data-mail-document-bootstrap-button]');
                    const input = root?.querySelector('[data-mail-document-bootstrap-file]');
                    const status = root?.querySelector('[data-mail-document-bootstrap-status]');
                    const errorBox = root?.querySelector('[data-mail-document-bootstrap-error]');
                    if (!root || !button || !input || !status || !errorBox) return;

                    const listeners = new AbortController();
                    const setError = (message = '') => {
                        errorBox.textContent = message;
                        errorBox.hidden = message === '';
                        errorBox.classList.toggle('hidden', message === '');
                    };
                    const requestError = async (response) => {
                        let payload = null;
                        try { payload = await response.json(); } catch (_) {}
                        const messages = Object.values(payload?.errors || {}).flat().filter(Boolean);
                        return new Error(messages[0] || payload?.message || `Import fehlgeschlagen (HTTP ${response.status}).`);
                    };

                    button.addEventListener('click', () => input.click(), { signal: listeners.signal });
                    input.addEventListener('change', async () => {
                        const file = input.files?.[0] || null;
                        input.value = '';
                        if (!file) return;

                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');
                        setError();
                        status.textContent = 'Bundle wird lokal gelesen und serverseitig geprüft …';

                        try {
                            if (file.size < 1 || file.size > 16 * 1024 * 1024) {
                                throw new Error('Das JSON-Bundle muss zwischen 1 Byte und 16 MiB groß sein.');
                            }
                            const bundle = JSON.parse((await file.text()).replace(/^\uFEFF/, ''));
                            if (!bundle || Array.isArray(bundle)
                                || bundle.format !== 'railtime-mail-document'
                                || bundle.version !== 2
                                || bundle.kind !== root.dataset.kind
                                || typeof bundle.html !== 'string'
                                || typeof bundle.css !== 'string'
                                || !Array.isArray(bundle.media)) {
                                throw new Error('Die Datei ist kein passendes RailTime-Mail-Bundle in Version 2.');
                            }

                            const response = await fetch(root.dataset.endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.content || '',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify(bundle),
                            });
                            if (!response.ok) throw await requestError(response);
                            const payload = await response.json();
                            status.textContent = 'Entwurf angelegt. Editor wird geöffnet …';
                            window.location.assign(payload.redirect);
                        } catch (error) {
                            setError(error instanceof Error ? error.message : 'Das Bundle konnte nicht importiert werden.');
                            status.textContent = 'Nicht importiert.';
                            button.disabled = false;
                            button.removeAttribute('aria-busy');
                        }
                    }, { signal: listeners.signal });

                    window.document.addEventListener('livewire:navigating', () => listeners.abort(), {
                        once: true,
                        signal: listeners.signal,
                    });
                })();
            </script>
        @endscript
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
                    data-mail-editor-mode="mail"
                    wire:ignore
                >
                    <div class="rt-mail-editor-loading" role="status">
                        <span class="rt-mail-editor-loading__mark">RT</span>
                        <span>LMZ Page Builder wird im Mailmodus geladen …</span>
                    </div>
                </div>
                <div class="rt-mail-delivery-preview" data-mail-delivery-preview hidden>
                    <iframe
                        data-mail-delivery-frame
                        title="Kompilierte Versandansicht im Browser"
                        sandbox=""
                        referrerpolicy="no-referrer"
                    ></iframe>
                    <div class="rt-mail-delivery-preview__state" data-mail-delivery-state role="status" aria-live="polite">
                        Versand-HTML wird kompiliert …
                    </div>
                </div>
                <div
                    class="rt-mail-preview-resizer"
                    data-mail-preview-resizer
                    role="separator"
                    aria-label="Breite der Mailvorschau ändern"
                    aria-orientation="vertical"
                    aria-valuemin="320"
                    aria-valuemax="1920"
                    aria-valuenow="1920"
                    aria-valuetext="1920 Pixel"
                    tabindex="0"
                    hidden
                >
                    <span aria-hidden="true"></span>
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

                <p class="rt-mail-code-dialog__error" data-mail-code-error role="alert" hidden></p>

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
                            <i data-feather="check-circle" class="h-4 w-4" aria-hidden="true"></i>
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
                    const viewModeButtons = Array.from(studioRoot.querySelectorAll('[data-mail-view-mode]'));
                    const themeButtons = Array.from(studioRoot.querySelectorAll('[data-mail-theme-button]'));
                    const themeControls = studioRoot.querySelector('[data-mail-theme-controls]');
                    const deviceButtons = Array.from(studioRoot.querySelectorAll('[data-mail-preview-device]'));
                    const previewWidthInput = studioRoot.querySelector('[data-mail-preview-width]');
                    const previewResizer = studioRoot.querySelector('[data-mail-preview-resizer]');
                    const deliveryPreview = studioRoot.querySelector('[data-mail-delivery-preview]');
                    const deliveryFrame = studioRoot.querySelector('[data-mail-delivery-frame]');
                    const deliveryState = studioRoot.querySelector('[data-mail-delivery-state]');
                    const degradationSelect = studioRoot.querySelector('[data-mail-degradation-mode]');
                    const replayButton = studioRoot.querySelector('[data-mail-preview-replay]');
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

                    let instance = null;
                    let destroyed = false;
                    let selectedTheme = 'light';
                    let selectedDevice = 'wide';
                    let selectedViewMode = 'edit';
                    let selectedDegradationMode = 'normal';
                    let actionsBusy = false;
                    let compatibilityBlocksPublication = false;
                    let unregisterNavigation = null;
                    let lastEditorSaveError = null;
                    let activeBaselineHtml = String(document_.html || '');
                    let codeDialogOpener = null;
                    let pendingPortableMedia = [];
                    let selectedVersionId = '';
                    let latestPreviewGeometry = null;
                    let compiledDeliveryHtml = '';
                    let deliveryPreviewRequest = null;
                    let deliveryPreviewGeneration = 0;
                    let previewResizeFrame = null;
                    let resizeGesture = null;
                    const controlListeners = new AbortController();
                    const MAIL_SOURCE_FORMAT = 'railtime-mail-document';
                    const MAIL_SOURCE_VERSION = 2;
                    const MAX_SOURCE_BYTES = 1024 * 1024;
                    const MAX_BUNDLE_BYTES = 16 * 1024 * 1024;
                    const MAX_MEDIA_BYTES = 2 * 1024 * 1024;
                    const toolsPanelId = `rt-dropdown-mail-document-tools-${config.currentDocument}-content`;
                    const versionsPanelId = `rt-dropdown-mail-document-versions-${config.currentDocument}-content`;
                    const queryToolControl = (selector) => window.document
                        .getElementById(toolsPanelId)
                        ?.querySelector(selector) || null;
                    const queryVersionControl = (selector) => studioRoot
                        .querySelector(`[data-mail-document-version] ${selector}`)
                        || window.document.getElementById(versionsPanelId)?.querySelector(selector)
                        || null;
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
                    const bindVersionControl = (selector, listener) => bindTeleportedControl(
                        queryVersionControl,
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
                        setMessage('Ein bekannter Signatur-Altstand wurde für den Editor sicher repariert. Beim nächsten Speichern wird Schema 25 übernommen.');
                    }

                    const setActionsBusy = (busy) => {
                        actionsBusy = Boolean(busy);
                        const restoreButton = queryVersionControl('[data-mail-document-version-restore]');
                        const testMailButton = queryToolControl('[data-mail-document-test-mail]');
                        [
                            saveButton,
                            publishButton,
                            codeApplyButton,
                            queryToolControl('[data-mail-code-open]'),
                            queryToolControl('[data-mail-code-export]'),
                            queryToolControl('[data-mail-code-import]'),
                            testMailButton,
                            queryVersionControl('[data-mail-document-version-trigger]'),
                            restoreButton,
                            ...codeCancelButtons,
                        ].forEach((button) => {
                            if (!button) return;

                            button.disabled = actionsBusy
                                || ([publishButton, testMailButton].includes(button) && compatibilityBlocksPublication)
                                || (button === restoreButton && selectedVersionId === '');
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

                    const syncPreviewResizer = (geometry = latestPreviewGeometry) => {
                        if (previewResizeFrame !== null) window.cancelAnimationFrame(previewResizeFrame);
                        previewResizeFrame = window.requestAnimationFrame(() => {
                            previewResizeFrame = null;
                            if (!previewResizer || !editorFrame || !geometry) return;

                            const target = selectedViewMode === 'delivery'
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

                        deviceButtons.forEach((button) => {
                            button.setAttribute('aria-pressed', String(
                                activeDevice !== 'custom'
                                && button.dataset.mailPreviewDevice === activeDevice
                            ));
                        });
                        if (previewWidthInput && window.document.activeElement !== previewWidthInput) {
                            previewWidthInput.value = String(logicalWidth);
                        }
                        if (previewResizer) {
                            previewResizer.setAttribute('aria-valuenow', String(logicalWidth));
                            previewResizer.setAttribute('aria-valuetext', `${logicalWidth} Pixel`);
                        }

                        if (previewStatus) {
                            const labels = {
                                wide: 'Systemmail breit',
                                desktop: 'Desktop',
                                tablet: 'Tablet',
                                mobile: 'Mobil',
                                custom: 'Individuell',
                            };
                            const degradationLabels = {
                                'images-off': 'Bilder aus',
                                'head-css-off': 'Head-CSS aus',
                                'css-off': 'Gesamtes CSS aus',
                            };
                            const scale = activeGeometry?.scale && activeGeometry.scale < 0.999
                                ? ` · Fit ${Math.round(activeGeometry.scale * 100)} %`
                                : ' · 100 %';
                            const degradation = selectedDegradationMode === 'normal'
                                ? ''
                                : ` · ${degradationLabels[selectedDegradationMode]} · Robustheitsvorschau, keine Mailclient-Emulation`;
                            const rendering = selectedViewMode === 'delivery'
                                ? 'Kompiliertes Versand-HTML im Browser'
                                : labels[activeDevice] || 'Editor';
                            previewStatus.textContent = `${rendering} · ${logicalWidth} px${scale}${degradation}`;
                        }

                        syncPreviewResizer(activeGeometry);
                    };

                    const selectTheme = (theme) => {
                        selectedTheme = theme === 'dark' ? 'dark' : 'light';
                        editorFrame?.setAttribute('data-preview-theme', selectedTheme);
                        themeButtons.forEach((button) => {
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
                        deviceButtons.forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailPreviewDevice === selectedDevice));
                        });
                        instance?.setPreviewDevice?.(selectedDevice);
                        if (selectedDegradationMode !== 'normal' && selectedViewMode === 'edit') {
                            instance?.setDegradationMode?.(selectedDegradationMode);
                        }
                        updatePreviewStatus(instance?.getPreviewGeometry?.());
                    };

                    const renderCompiledDeliveryHtml = () => {
                        if (!deliveryFrame || compiledDeliveryHtml === '') return;

                        const preview = selectedDegradationMode === 'normal'
                            ? { html: compiledDeliveryHtml, disclaimer: '' }
                            : instance?.createDegradationPreview?.(
                                compiledDeliveryHtml,
                                selectedDegradationMode,
                            );
                        if (!preview?.html) return;

                        if (deliveryState) {
                            deliveryState.textContent = preview.disclaimer || 'Kompiliertes Versand-HTML wird im Browser dargestellt …';
                        }
                        deliveryFrame.onload = () => {
                            if (deliveryState && selectedDegradationMode === 'normal') deliveryState.textContent = '';
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
                        if (deliveryState) deliveryState.textContent = 'Aktueller Stand wird mit dem produktiven Systemmail-Compiler geprüft …';
                        if (deliveryFrame) deliveryFrame.srcdoc = '';

                        const candidate = currentCandidateForServer();
                        const payload = await request(document_.endpoints.deliveryPreview, 'POST', {
                            builder_data: candidate.builderData,
                            html: candidate.html,
                            css: candidate.css,
                            expected_hash: document_.contentHash || '',
                        }, { signal: deliveryPreviewRequest.signal });
                        if (generation !== deliveryPreviewGeneration || selectedViewMode !== 'delivery') return;
                        if (payload.preview?.rendering !== 'compiled-system-mail'
                            || typeof payload.preview?.html !== 'string'
                            || payload.preview.html.trim() === '') {
                            throw new Error('Der Server hat kein vollständiges kompiliertes Versand-HTML geliefert.');
                        }

                        compiledDeliveryHtml = payload.preview.html;
                        showFindings(payload.report, payload.compatibility);
                        renderCompiledDeliveryHtml();
                    };

                    const selectViewMode = async (mode) => {
                        selectedViewMode = mode === 'delivery' ? 'delivery' : 'edit';
                        editorFrame?.setAttribute('data-mail-view-mode', selectedViewMode);
                        viewModeButtons.forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailViewMode === selectedViewMode));
                            button.setAttribute('aria-busy', String(
                                selectedViewMode === 'delivery'
                                && button.dataset.mailViewMode === 'delivery'
                            ));
                        });
                        themeButtons.forEach((button) => {
                            button.disabled = selectedViewMode === 'delivery';
                        });
                        themeControls?.setAttribute('aria-disabled', String(selectedViewMode === 'delivery'));

                        if (selectedViewMode === 'edit') {
                            deliveryPreviewGeneration += 1;
                            deliveryPreviewRequest?.abort();
                            deliveryPreviewRequest = null;
                            if (deliveryPreview) deliveryPreview.hidden = true;
                            if (deliveryFrame) deliveryFrame.srcdoc = '';
                            instance?.setDegradationMode?.(selectedDegradationMode);
                            viewModeButtons.forEach((button) => button.setAttribute('aria-busy', 'false'));
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
                            const surfaced = showRequestError(error, 'Versandansicht nicht verfügbar');
                            if (deliveryState) deliveryState.textContent = surfaced.message;
                            toast('error', surfaced.message, 'Versandansicht nicht verfügbar');
                        } finally {
                            viewModeButtons.forEach((button) => button.setAttribute('aria-busy', 'false'));
                        }
                    };

                    const selectDegradationMode = (mode) => {
                        selectedDegradationMode = ['normal', 'images-off', 'head-css-off', 'css-off'].includes(mode)
                            ? mode
                            : 'normal';
                        if (degradationSelect) degradationSelect.value = selectedDegradationMode;
                        if (selectedViewMode === 'delivery') renderCompiledDeliveryHtml();
                        else instance?.setDegradationMode?.(selectedDegradationMode);
                        updatePreviewStatus(instance?.getPreviewGeometry?.());
                    };

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

                        if (messages.length === 0) {
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
                                        : 'Hinweise der Sicherheits- und Kompatibilitätsprüfung')
                            );
                        }

                        messages.forEach((message) => {
                            const item = window.document.createElement('li');
                            item.textContent = message;
                            findingsList.appendChild(item);
                        });

                        findingsBox.hidden = false;
                        findingsBox.classList.remove('hidden');
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

                    const renderVersions = (versions = document_.versions || [], attempt = 0) => {
                        const list = queryVersionControl('[data-mail-document-version-list]');
                        const triggerLabel = queryVersionControl('[data-mail-document-version-trigger-label]');
                        const restoreButton = queryVersionControl('[data-mail-document-version-restore]');
                        if (!list || !triggerLabel || !restoreButton) {
                            if (!destroyed && attempt < 60) {
                                window.requestAnimationFrame(() => renderVersions(versions, attempt + 1));
                            }
                            return;
                        }

                        const selected = versions.find((version) => String(version.id) === selectedVersionId) || null;
                        if (!selected) selectedVersionId = '';
                        triggerLabel.textContent = selected
                            ? `#${selected.revision} · ${selected.action_label}`
                            : 'Versionen';
                        restoreButton.disabled = !selected;
                        list.replaceChildren();

                        if (versions.length === 0) {
                            const empty = window.document.createElement('p');
                            empty.className = 'px-3 py-2 text-sm text-rt-muted dark:text-rt-dark-muted';
                            empty.textContent = 'Noch keine gespeicherte Version vorhanden.';
                            list.appendChild(empty);
                            return;
                        }

                        versions.forEach((version) => {
                            const published = version.was_published ? ' · veröffentlicht' : '';
                            const creator = version.creator ? ` · ${version.creator}` : '';
                            const option = window.document.createElement('button');
                            option.type = 'button';
                            option.setAttribute('role', 'option');
                            option.setAttribute('aria-selected', String(String(version.id) === selectedVersionId));
                            option.dataset.mailDocumentVersionOption = String(version.id);
                            option.className = 'flex min-h-11 w-full items-start gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium text-rt-text outline-none transition hover:bg-rt-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-accent/35 dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted dark:focus-visible:ring-rt-dark-accent/40';
                            option.textContent = `#${version.revision} · ${version.action_label} · ${version.created_label || ''}${creator}${published}`;
                            option.addEventListener('click', () => {
                                selectedVersionId = String(version.id);
                                renderVersions(versions);
                            }, { signal: controlListeners.signal });
                            list.appendChild(option);
                        });
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
                        renderVersions();

                        if (statusBadge) {
                            statusBadge.dataset.status = document_.status;
                            statusBadge.dataset.hasUnpublishedChanges = String(document_.hasUnpublishedChanges);
                            statusBadge.dataset.statusLabel = payload.status_label || statusBadge.dataset.statusLabel || statusBadge.textContent;
                            statusBadge.textContent = document_.status === 'published' && document_.hasUnpublishedChanges
                                ? 'Entwurf'
                                : statusBadge.dataset.statusLabel;
                        }
                    };
                    renderVersions();

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
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.coreCss }),
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

                    const request = async (url, method, body = null, options = {}) => {
                        const response = await fetch(url, {
                            method,
                            credentials: 'same-origin',
                            signal: options.signal || undefined,
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

                    const bytesToBase64 = (bytes) => {
                        let binary = '';
                        const chunkSize = 0x8000;
                        for (let offset = 0; offset < bytes.length; offset += chunkSize) {
                            binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
                        }

                        return window.btoa(binary);
                    };

                    const base64ToBytes = (encoded) => {
                        if (typeof encoded !== 'string'
                            || encoded.length === 0
                            || encoded.length % 4 !== 0
                            || !/^[A-Za-z0-9+/]*={0,2}$/.test(encoded)) {
                            throw new Error('Ein Medium enthält keine gültigen Base64-Daten.');
                        }

                        const binary = window.atob(encoded);
                        const bytes = new Uint8Array(binary.length);
                        for (let index = 0; index < binary.length; index += 1) {
                            bytes[index] = binary.charCodeAt(index);
                        }

                        return bytes;
                    };

                    const sha256 = async (bytes) => Array.from(
                        new Uint8Array(await window.crypto.subtle.digest('SHA-256', bytes)),
                    ).map((value) => value.toString(16).padStart(2, '0')).join('');

                    const portableMediaCatalog = () => {
                        const catalog = Array.isArray(config.portableMedia) ? config.portableMedia : [];
                        const seen = new Set();

                        return catalog.map((asset) => {
                            const source = String(asset?.source || '').trim();
                            const id = String(asset?.id || '').trim();
                            const mimeType = String(asset?.mime_type || '').toLowerCase();
                            const digest = String(asset?.sha256 || '').toLowerCase();
                            const bytes = Number(asset?.bytes || 0);
                            const resolved = new URL(source, window.location.href);
                            if (!id
                                || seen.has(id)
                                || resolved.origin !== window.location.origin
                                || !['image/gif', 'image/png', 'image/jpeg', 'image/webp'].includes(mimeType)
                                || !/^[a-f0-9]{64}$/.test(digest)
                                || !Number.isInteger(bytes)
                                || bytes < 1
                                || bytes > MAX_MEDIA_BYTES) {
                                throw new Error('Der serverseitige Medienbestand ist nicht portabel konfiguriert.');
                            }
                            seen.add(id);

                            return {
                                id,
                                name: String(asset?.name || id),
                                source: resolved.href,
                                mime_type: mimeType,
                                bytes,
                                sha256: digest,
                                required: Boolean(asset?.required),
                                included: asset?.included !== false,
                            };
                        });
                    };

                    const requiredPortableMediaIds = (source) => {
                        if (typeof runtimeBridge.resolvePortableMediaRequirementIds !== 'function') {
                            throw new Error('Die versionsabhängige Medienprüfung ist nicht verfügbar. Bitte Seite neu laden.');
                        }

                        return runtimeBridge.resolvePortableMediaRequirementIds(
                            config.portableMediaRequirements || {},
                            config.currentDocument,
                            source?.html || '',
                        );
                    };

                    const exportPortableMedia = async (source) => {
                        const exported = [];
                        let totalBytes = 0;
                        const requiredIds = new Set(requiredPortableMediaIds(source));

                        for (const asset of portableMediaCatalog().filter((entry) => (
                            requiredIds.has(entry.id)
                            || (entry.included && entry.id.startsWith('mail-imports/'))
                        ))) {
                            const response = await fetch(asset.source, {
                                credentials: 'same-origin',
                                cache: 'no-store',
                                redirect: 'error',
                            });
                            if (!response.ok || new URL(response.url).origin !== window.location.origin) {
                                throw new Error(`„${asset.name}“ konnte nicht sicher gelesen werden.`);
                            }

                            const bytes = new Uint8Array(await response.arrayBuffer());
                            const digest = await sha256(bytes);
                            if (bytes.byteLength !== asset.bytes || digest !== asset.sha256) {
                                throw new Error(`„${asset.name}“ wurde während des Exports verändert. Bitte Seite neu laden.`);
                            }
                            totalBytes += bytes.byteLength;
                            if (totalBytes > MAX_BUNDLE_BYTES) {
                                throw new Error('Die Medien des Bundles sind zusammen größer als 16 MiB.');
                            }

                            exported.push({ ...asset, data: bytesToBase64(bytes) });
                        }

                        return exported;
                    };

                    const portableBundle = async (source) => ({
                        format: MAIL_SOURCE_FORMAT,
                        version: MAIL_SOURCE_VERSION,
                        kind: config.currentDocument,
                        html: source.html,
                        css: source.css,
                        media: await exportPortableMedia(source),
                    });

                    const downloadPortableBundle = async (source) => {
                        const bundle = await portableBundle(source);
                        const blob = new Blob([`${JSON.stringify(bundle, null, 2)}\n`], {
                            type: 'application/json;charset=utf-8',
                        });
                        if (blob.size > MAX_BUNDLE_BYTES) {
                            throw new Error(`Das vollständige Bundle ist ${formatBytes(blob.size)} groß und überschreitet 16 MiB.`);
                        }
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

                    const parsePortableBundle = async (text) => {
                        let bundle;
                        try {
                            bundle = JSON.parse(String(text || '').replace(/^\uFEFF/, ''));
                        } catch (_) {
                            throw new Error('Die JSON-Datei ist nicht gültig.');
                        }

                        if (!bundle || Array.isArray(bundle) || typeof bundle !== 'object'
                            || bundle.format !== MAIL_SOURCE_FORMAT
                            || ![1, MAIL_SOURCE_VERSION].includes(bundle.version)) {
                            throw new Error(`Erwartet wird ein RailTime-Mail-Bundle in Version 1 oder ${MAIL_SOURCE_VERSION}.`);
                        }
                        if (bundle.kind !== config.currentDocument) {
                            const expected = config.currentDocument === 'signature' ? 'eine Signatur' : 'eine Nachrichtenvorlage';
                            throw new Error(`Dieses Bundle gehört zu „${bundle.kind || 'unbekannt'}“. Geöffnet ist ${expected}.`);
                        }

                        const source = assertPortableSource({ html: bundle.html, css: bundle.css });
                        if (bundle.version === 1) {
                            return { source, media: [] };
                        }
                        if (!Array.isArray(bundle.media)) {
                            throw new Error('Dem Bundle fehlt der vollständige Medienbestand.');
                        }

                        const catalog = portableMediaCatalog();
                        const knownIds = new Set(catalog.map((asset) => asset.id));
                        const requiredIds = requiredPortableMediaIds(source);
                        const seenIds = new Set();
                        const seenSources = new Set();
                        let totalBytes = 0;
                        const media = [];
                        for (const entry of bundle.media) {
                            const id = String(entry?.id || '').trim();
                            const name = String(entry?.name || id).trim();
                            const sourceUrl = String(entry?.source || '').trim();
                            const mimeType = String(entry?.mime_type || '').toLowerCase();
                            const declaredBytes = Number(entry?.bytes || 0);
                            const declaredHash = String(entry?.sha256 || '').toLowerCase();
                            const importedId = id.match(/^mail-imports\/([a-f0-9]{64})\.(gif|png|jpg|webp)$/i);
                            const expectedExtension = {
                                'image/gif': 'gif',
                                'image/png': 'png',
                                'image/jpeg': 'jpg',
                                'image/webp': 'webp',
                            }[mimeType];
                            if (!id
                                || !name
                                || seenIds.has(id)
                                || (!knownIds.has(id) && !importedId)
                                || !sourceUrl
                                || seenSources.has(sourceUrl)
                                || !/^(?:https?:\/\/|\/)/i.test(sourceUrl)
                                || sourceUrl.includes('{{')
                                || !['image/gif', 'image/png', 'image/jpeg', 'image/webp'].includes(mimeType)
                                || !Number.isInteger(declaredBytes)
                                || declaredBytes < 1
                                || declaredBytes > MAX_MEDIA_BYTES
                                || !/^[a-f0-9]{64}$/.test(declaredHash)
                                || (importedId && (importedId[1].toLowerCase() !== declaredHash
                                    || importedId[2].toLowerCase() !== expectedExtension))) {
                                throw new Error('Das Bundle enthält einen ungültigen oder doppelten Medieneintrag.');
                            }

                            const bytes = base64ToBytes(entry.data);
                            const digest = await sha256(bytes);
                            if (bytes.byteLength !== declaredBytes || digest !== declaredHash) {
                                throw new Error(`Prüfsumme oder Größe von „${name}“ stimmt nicht.`);
                            }
                            totalBytes += bytes.byteLength;
                            if (totalBytes > MAX_BUNDLE_BYTES) {
                                throw new Error('Die Medien des Bundles sind zusammen größer als 16 MiB.');
                            }
                            seenIds.add(id);
                            seenSources.add(sourceUrl);
                            media.push({
                                id,
                                name,
                                source: sourceUrl,
                                mime_type: mimeType,
                                bytes: declaredBytes,
                                sha256: declaredHash,
                                data: entry.data,
                            });
                        }

                        if (requiredIds.some((id) => !seenIds.has(id))) {
                            throw new Error('Das Bundle enthält nicht den vollständigen Medienbestand dieses Dokuments.');
                        }

                        return { source, media };
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
                            previewResponsiveCss: config.previewResponsiveCss || {},
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
                                        showFindings(payload.report, payload.compatibility);
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
                        selectDegradationMode(selectedDegradationMode);
                        await selectViewMode(selectedViewMode);
                    };

                    viewModeButtons.forEach((button) => {
                        button.addEventListener('click', () => selectViewMode(button.dataset.mailViewMode), {
                            signal: controlListeners.signal,
                        });
                    });

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

                    previewWidthInput?.addEventListener('input', () => {
                        if (previewWidthInput.value.trim() === '') return;
                        selectPreviewWidth(previewWidthInput.value);
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

                    degradationSelect?.addEventListener('change', () => {
                        try {
                            selectDegradationMode(degradationSelect.value);
                        } catch (error) {
                            selectDegradationMode('normal');
                            const surfaced = showRequestError(error, 'Robustheitsvorschau nicht verfügbar');
                            toast('error', surfaced.message, 'Vorschau nicht verfügbar');
                        }
                    }, { signal: controlListeners.signal });

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

                    const validateSourceOnServer = async (source, portableMedia = []) => {
                        if (typeof document_.endpoints?.validate !== 'string' || document_.endpoints.validate.trim() === '') {
                            throw new Error('Der sichere Prüf-Endpunkt für Codeimporte ist nicht verfügbar. Es wurde nichts übernommen.');
                        }

                        const candidate = canonicalizeExternalSource(source);
                        const payload = await request(document_.endpoints.validate, 'POST', {
                            builder_data: candidate.project,
                            html: candidate.html,
                            css: candidate.css,
                            expected_hash: document_.contentHash || '',
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

                        // Bis hier wurde der laufende Editor nicht verändert.
                        // Erst der serverseitig kanonisierte Stand darf auf die
                        // Leinwand und danach durch den normalen Save-Request.
                        setMessage('Code wird serverseitig geprüft …');
                        const validated = await validateSourceOnServer(source, pendingPortableMedia);
                        if (!instance?.editor?.loadProjectData) {
                            setMessage('Reparierter Entwurf wird gespeichert …');
                            const payload = await request(document_.endpoints.update, 'PUT', {
                                builder_data: validated.draft.builderData,
                                html: validated.draft.html,
                                css: validated.draft.css,
                                expected_hash: document_.contentHash || '',
                            });
                            applyDocumentState(payload.document);
                            showFindings(
                                payload.report || validated.report,
                                payload.compatibility ?? validated.compatibility,
                            );
                            pendingPortableMedia = [];

                            const message = 'Der reparierte Entwurf wurde gespeichert. Der Editor wird neu geladen.';
                            setMessage(message);
                            toast('success', message, 'Import abgeschlossen');
                            codeDialog?.close('saved');
                            window.setTimeout(() => window.location.reload(), 250);

                            return;
                        }

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

                            if (Array.isArray(validated.report?.messages)
                                || Array.isArray(validated.compatibility?.findings)) {
                                showFindings(validated.report, validated.compatibility);
                            }
                            const reloadForPortableMedia = pendingPortableMedia.length > 0;
                            pendingPortableMedia = [];

                            const message = 'Code geprüft und als Entwurf gespeichert. Die veröffentlichte Fassung bleibt unverändert.';
                            setMessage(message);
                            toast('success', message, 'Import abgeschlossen');
                            codeDialog?.close('saved');
                            if (reloadForPortableMedia) {
                                window.setTimeout(() => window.location.reload(), 250);
                            }
                        } catch (error) {
                            activeBaselineHtml = String(document_.html || previousBaselineHtml);

                            if (editorWasReplaced) {
                                try {
                                    await runtimeBridge.rehydrateAuthoritative({
                                        editor,
                                        draft: document_,
                                        sanitizationChanged: true,
                                        parseCss: (canonicalCss) => editor.Parser?.parseCss?.(canonicalCss) || [],
                                        projectOptions: { kind: config.currentDocument, environment: window },
                                    });
                                    selectTheme(selectedTheme);
                                    selectDevice(selectedDevice);
                                    if (error instanceof Error) {
                                        error.message = `${error.message} Der zuletzt gespeicherte Serverentwurf wurde wiederhergestellt.`;
                                    }
                                } catch (_) {
                                    await editor.loadProjectData(previousProject);
                                    selectTheme(selectedTheme);
                                    selectDevice(selectedDevice);
                                }
                            }

                            throw error;
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

                        try {
                            setCodeError();
                            await applyCodeAsDraft();
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Code konnte nicht übernommen werden');
                            setCodeError(surfaced.message);
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

                    bindVersionControl('[data-mail-document-version-restore]', async () => {
                        const selected = (document_.versions || []).find(
                            (version) => String(version.id) === selectedVersionId,
                        );
                        if (!selected) {
                            toast('warning', 'Bitte zuerst eine gespeicherte Version auswählen.', 'Keine Version gewählt');
                            return;
                        }
                        if (!window.confirm(`Version #${selected.revision} als neuen Entwurf wiederherstellen? Die veröffentlichte Fassung bleibt aktiv.`)) return;

                        setActionsBusy(true);
                        try {
                            await saveCurrentDraft();
                            const payload = await request(selected.restore_url, 'POST', {
                                expected_hash: document_.contentHash || '',
                            });
                            applyDocumentState(payload.document);
                            activeBaselineHtml = String(document_.html || '');
                            await runtimeBridge.rehydrateAuthoritative({
                                editor: instance.editor,
                                draft: document_,
                                sanitizationChanged: true,
                                parseCss: (canonicalCss) => instance.editor.Parser?.parseCss?.(canonicalCss) || [],
                                projectOptions: { kind: config.currentDocument, environment: window },
                            });
                            selectTheme(selectedTheme);
                            selectDevice(selectedDevice);
                            setMessage(`Version #${selected.revision} wurde als neuer Entwurf wiederhergestellt.`);
                            toast('success', 'Die Veröffentlichung wurde nicht verändert.', 'Version wiederhergestellt');
                        } catch (error) {
                            const surfaced = showRequestError(error, 'Version konnte nicht wiederhergestellt werden');
                            toast('error', surfaced.message, 'Wiederherstellung fehlgeschlagen');
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

                        try {
                            instance?.destroy?.();
                        } catch (_) {
                            // Die Recovery-Werkzeuge bleiben auch dann aktiv,
                            // wenn eine halbfertige Builderinstanz sich nicht
                            // mehr vollstaendig abbauen laesst.
                        }
                        instance = null;
                        root.innerHTML = '';
                        const notice = window.document.createElement('div');
                        notice.className = 'rt-mail-editor-error';
                        notice.setAttribute('role', 'alert');
                        notice.textContent = `Editor konnte nicht geladen werden: ${error.message} Ein JSON-, HTML- oder CSS-Import bleibt über „Werkzeuge“ verfügbar.`;
                        root.appendChild(notice);
                        toast('error', error.message, 'E-Mail-Editor nicht verfügbar');
                    });

                    const teardown = () => {
                        destroyed = true;
                        finishResizeGesture();
                        deliveryPreviewGeneration += 1;
                        deliveryPreviewRequest?.abort();
                        deliveryPreviewRequest = null;
                        if (previewResizeFrame !== null) window.cancelAnimationFrame(previewResizeFrame);
                        previewResizeFrame = null;
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
