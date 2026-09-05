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
    :auto-open="$editorRequested"
    :open-url="$currentDocument !== null && ! $editorRequested ? $editorOpenUrl : null"
    :render-workspace="$currentDocument === null || $editorRequested"
    :single-toolbar="$currentDocument !== null && $editorRequested"
    workspace-class="min-h-0 flex-1 overflow-hidden p-0"
    data-mail-document-studio
    data-mail-document-back
>
    @if ($currentDocument !== null && $editorRequested)
        <x-slot:toolbar>
            <div class="rt-mail-studio-toolbar" role="toolbar" aria-label="Mail- und Signatur-Editor" data-mail-studio-toolbar data-mail-toolbar-layout="responsive" data-mail-toolbar-single>
                <div class="rt-mail-studio-toolbar__documents" data-mail-toolbar-region="documents" role="group" aria-label="Dokument und Inhalt">
                    <x-ui.dropdown.anchor-dropdown
                        align="left"
                        width="80"
                        :offset="8"
                        dropdown-id="mail-document-select-{{ $currentDocument->kind->value }}"
                        layer-group="mail-document-editor"
                        content-role="dialog"
                        content-label="Dokument auswählen"
                        content-classes="bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                        dropdown-classes="shadow-xl"
                        data-mail-toolbar-menu="document"
                    >
                        <x-slot:trigger>
                            <x-ui.buttons.button-basic
                                type="button"
                                mode="secondary"
                                size="sm"
                                class="min-h-11 min-w-0 shrink-0 rounded-lg px-3"
                                title="Nachrichtenvorlage oder Signaturblock auswählen"
                            >
                                <i data-feather="file-text" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Dokument</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true">
                                    <i data-feather="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                </span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <nav class="grid gap-1" aria-label="Maildokument auswählen">
                                @foreach ($kinds as $kindValue => [$kindLabel, $kindHint])
                                    <a
                                        href="{{ route('admin.mail-documents.editor', ['dokument' => $kindValue, 'open' => 1]) }}"
                                        data-mail-document-switch="{{ $kindValue }}"
                                        data-mail-document-hard-switch
                                        aria-current="{{ $currentKind === $kindValue ? 'page' : 'false' }}"
                                        class="rt-mail-studio-document"
                                    >
                                        <span>{{ $kindLabel }}</span>
                                        <small>{{ $kindHint }}</small>
                                    </a>
                                @endforeach
                            </nav>
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>

                    <x-ui.dropdown.anchor-dropdown
                        align="left"
                        width="64"
                        :offset="8"
                        dropdown-id="mail-document-content-{{ $currentDocument->kind->value }}"
                        layer-group="mail-document-editor"
                        content-role="dialog"
                        content-label="Inhalt und Medien"
                        content-classes="bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                        dropdown-classes="shadow-xl"
                        data-mail-toolbar-menu="content"
                    >
                        <x-slot:trigger>
                            <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 shrink-0 rounded-lg px-3" title="Bausteine, Ebenen und Medien öffnen">
                                <i data-feather="layers" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Inhalt</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i data-feather="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i></span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="grid gap-1">
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="left:blocks" x-on:click="close()" title="Bausteine in der linken Seitenleiste öffnen">
                                    <i data-feather="grid" class="h-4 w-4" aria-hidden="true"></i><span>Bausteine</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="left:layers" x-on:click="close()" title="Ebenen in der linken Seitenleiste öffnen">
                                    <i data-feather="layers" class="h-4 w-4" aria-hidden="true"></i><span>Ebenen</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-action="assets" x-on:click="close()" title="Medienbibliothek öffnen">
                                    <i data-feather="image" class="h-4 w-4" aria-hidden="true"></i><span>Medien</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-action="upload" x-on:click="close()" title="Bild oder GIF hochladen" hidden aria-disabled="true">
                                    <i data-feather="upload" class="h-4 w-4" aria-hidden="true"></i><span>Bild / GIF hochladen</span>
                                </x-ui.buttons.button-basic>
                            </div>
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>

                    <x-ui.dropdown.anchor-dropdown
                        align="left"
                        width="64"
                        :offset="8"
                        dropdown-id="mail-document-edit-{{ $currentDocument->kind->value }}"
                        layer-group="mail-document-editor"
                        content-role="dialog"
                        content-label="Bearbeiten und Eigenschaften"
                        content-classes="bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                        dropdown-classes="shadow-xl"
                        data-mail-toolbar-menu="edit"
                    >
                        <x-slot:trigger>
                            <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 shrink-0 rounded-lg px-3" title="Bearbeitungs- und Eigenschaftswerkzeuge öffnen">
                                <i data-feather="edit-3" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Bearbeiten</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i data-feather="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i></span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="grid grid-cols-2 gap-1 border-b border-rt-border pb-2 dark:border-rt-dark-border">
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 justify-center rounded-lg px-3" data-mail-builder-action="undo" x-on:click="close()" title="Letzte Änderung rückgängig machen">
                                    <i data-feather="corner-up-left" class="h-4 w-4" aria-hidden="true"></i><span>Zurück</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 justify-center rounded-lg px-3" data-mail-builder-action="redo" x-on:click="close()" title="Änderung wiederholen">
                                    <i data-feather="corner-up-right" class="h-4 w-4" aria-hidden="true"></i><span>Vor</span>
                                </x-ui.buttons.button-basic>
                            </div>
                            <div class="mt-2 grid gap-1">
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="right:styles" x-on:click="close()" title="Stile in der rechten Seitenleiste öffnen">
                                    <i data-feather="sliders" class="h-4 w-4" aria-hidden="true"></i><span>Stile</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="right:traits" x-on:click="close()" title="Eigenschaften in der rechten Seitenleiste öffnen">
                                    <i data-feather="settings" class="h-4 w-4" aria-hidden="true"></i><span>Eigenschaften</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="right:classes" x-on:click="close()" title="Klassen in der rechten Seitenleiste öffnen">
                                    <i data-feather="tag" class="h-4 w-4" aria-hidden="true"></i><span>Klassen</span>
                                </x-ui.buttons.button-basic>
                            </div>
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
                </div>

                <div class="rt-mail-studio-toolbar__preview" data-mail-toolbar-region="preview" data-mail-preview-toolbar>
                    <x-ui.dropdown.anchor-dropdown
                        align="left"
                        width="96"
                        :offset="8"
                        dropdown-id="mail-document-view-{{ $currentDocument->kind->value }}"
                        layer-group="mail-document-editor"
                        content-role="dialog"
                        content-label="Ansicht und Vorschau"
                        content-classes="bg-rt-surface p-3 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                        dropdown-classes="shadow-xl"
                        data-mail-toolbar-menu="view"
                    >
                        <x-slot:trigger>
                            <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 shrink-0 rounded-lg px-3" title="Editor- und Versandansicht einstellen">
                                <i data-feather="monitor" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Ansicht</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i data-feather="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i></span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="rt-mail-view-menu">
                                <div class="rt-mail-preview-context">
                                    <strong>Mailclient-Prüfung</strong>
                                    <small data-mail-preview-status aria-live="polite">Systemmail breit · 1920 px · wird eingepasst</small>
                                    <small data-mail-compiler-parity-note>
                                        „Bearbeiten“ nutzt nur mail-sichere Werkzeuge. „Compiler-Parität“ zeigt die produktiv kompilierte Systemmail-Quelle; Outlook nutzt dieselben veröffentlichten Dokumente mit clientspezifischen Medien- und Wrapper-Anpassungen. Die abschließende Word-Renderer-Prüfung erfolgt per Testmail.
                                    </small>
                                </div>

                                <x-ui.buttons.button-basic
                                    type="button"
                                    mode="secondary"
                                    size="sm"
                                    class="min-h-11 w-full justify-start rounded-lg px-3"
                                    data-mail-builder-action="preview"
                                    title="Leinwand-Vorschau des Builders umschalten"
                                >
                                    <i data-feather="eye" class="h-4 w-4" aria-hidden="true"></i>
                                    <span>Leinwand-Vorschau</span>
                                </x-ui.buttons.button-basic>

                    <div class="rt-mail-preview-toggle" role="group" aria-label="Editoransicht">
                        <button type="button" data-mail-view-mode="edit" aria-pressed="true" title="Bearbeitbare Mail-Leinwand anzeigen">
                            <i data-feather="edit-3" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Bearbeiten</span>
                        </button>
                        <button type="button" data-mail-view-mode="delivery" aria-pressed="false" title="Verbindliche Versandquelle mit dem produktiven Systemmail-Compiler prüfen; die Darstellung erfolgt weiterhin im Browser">
                            <i data-feather="mail" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Compiler-Parität</span>
                        </button>
                        <button type="button" data-mail-view-mode="forward" aria-pressed="false" title="Kompiliertes Versand-HTML als zitierte Weiterleitung ohne Head-CSS prüfen">
                            <i data-feather="corner-up-right" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Weiterleitung</span>
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
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
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
                        @if ($currentDocument->isActive() && $currentDocument->isPublished())
                            @if ($currentDocument->hasUnpublishedChanges())
                                Entwurf gespeichert — Systemmails verwenden weiterhin die Veröffentlichung vom {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                            @else
                                Systemmails verwenden die Veröffentlichung vom {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                            @endif
                        @elseif ($activeDocument instanceof \App\Models\MailDocument)
                            Entwurf „{{ $currentDocument->name }}“ — Systemmails verwenden weiterhin „{{ $activeDocument->name }}“.
                        @else
                            Noch kein Design veröffentlicht — veröffentliche einen Slot für Systemmails.
                        @endif
                    </p>

                    <div class="rt-mail-studio-toolbar__action-buttons" role="group" aria-label="Code, Import, Export, Entwurf und Veröffentlichung">
                        <x-ui.buttons.button-basic
                            type="button"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 min-w-0 shrink-0 rounded-lg px-3"
                            x-on:click="$dispatch('mail-design-manager-open')"
                            data-mail-design-manager-trigger
                            data-mail-toolbar-menu="designs-versions"
                            title="Design-Slots und gespeicherte Versionen verwalten"
                        >
                            <i data-feather="clock" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                            <span class="max-w-44 truncate">Designs &amp; Versionen</span>
                        </x-ui.buttons.button-basic>

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
                            data-mail-toolbar-menu="tools"
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
                                    <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true">
                                        <i data-feather="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                    </span>
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
                            data-mail-document-hard-switch
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
    @elseif ($editorRequested)
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
                    x-ignore
                >
                    <div class="rt-mail-editor-loading" role="status">
                        <span class="rt-mail-editor-loading__mark">RT</span>
                        <span>LMZ Page Builder wird im Mailmodus geladen …</span>
                    </div>
                </div>
                <div class="rt-mail-delivery-preview" data-mail-delivery-preview hidden>
                    <iframe
                        data-mail-delivery-frame
                        title="Kompilierte produktive Systemmail-Quelle im Browser"
                        sandbox=""
                        referrerpolicy="no-referrer"
                    ></iframe>
                    <div class="rt-mail-delivery-preview__state" data-mail-delivery-state role="status" aria-live="polite">
                        Verbindliche Versandquelle wird kompiliert …
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

        <div
            x-data="{
                managerOpen: false,
                managerBusy: false,
                openManager() {
                    this.managerOpen = true;
                },
                closeManager() {
                    if (this.managerBusy) return;
                    this.managerOpen = false;
                    this.$nextTick(() => document.querySelector('[data-mail-design-manager-trigger]')?.focus?.({ preventScroll: true }));
                },
            }"
            x-on:mail-design-manager-open.window="openManager()"
            x-on:mail-design-manager-busy.window="managerBusy = $event.detail === true"
            data-mail-design-manager-host
        >
            <x-ui.state-modal
                id="mail-design-manager-{{ $currentDocument->kind->value }}"
                state="managerOpen"
                title="Designs &amp; Versionen"
                description="Verwalte getrennte Arbeitsentwürfe. Genau ein veröffentlichtes Design wird von Systemmails verwendet."
                icon="far fa-layer-group"
                max-width="6xl"
                close-action="closeManager()"
                body-class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain p-4 [scrollbar-gutter:stable] sm:p-6"
                data-mail-design-manager
                data-page-builder-subdialog
            >
                <x-slot:actions>
                    <x-ui.badge color="slate">{{ count($documentSlots) }} {{ count($documentSlots) === 1 ? 'Design' : 'Designs' }}</x-ui.badge>
                </x-slot:actions>

                @if (! \Illuminate\Support\Facades\Schema::hasColumn('mail_documents', 'is_active'))
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-100" role="alert">
                        Die Design-Slot-Migration ist noch nicht installiert. Bitte führe zuerst die Datenbankmigrationen aus.
                    </div>
                @else
                    <form
                        class="mb-5 grid gap-3 rounded-2xl border border-rt-border bg-rt-surface-muted/60 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                        data-mail-slot-create-form
                        data-endpoint="{{ route('admin.mail-documents.slots.store', $currentDocument) }}"
                    >
                        <label class="grid min-w-0 gap-1.5">
                            <span class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Neues Design aus „{{ $currentDocument->name }}“</span>
                            <span class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Der aktuelle gespeicherte Entwurf wird dupliziert; die Veröffentlichung bleibt unverändert.</span>
                            <x-ui.forms.input
                                name="name"
                                maxlength="80"
                                required
                                aria-required="true"
                                autocomplete="off"
                                placeholder="z. B. Herbstkampagne"
                                data-mail-slot-create-name
                            />
                        </label>
                        <x-ui.buttons.button-basic type="submit" mode="primary" size="sm" class="min-h-11 rounded-xl px-4" data-mail-slot-create>
                            <i data-feather="plus" class="h-4 w-4" aria-hidden="true"></i>
                            <span>Design anlegen</span>
                        </x-ui.buttons.button-basic>
                    </form>

                    <div class="grid gap-4" data-mail-design-slot-list>
                        @foreach ($documentSlots as $slot)
                            @php
                                $isCurrentSlot = $slot->is($currentDocument);
                                $isActiveSlot = $slot->isActive();
                                $slotVersionCount = \Illuminate\Support\Facades\Schema::hasTable('mail_document_versions')
                                    ? (int) ($slot->versions_count ?? 0)
                                    : 0;
                                $slotVersions = $isCurrentSlot && \Illuminate\Support\Facades\Schema::hasTable('mail_document_versions')
                                    ? $slot->versions()->with('creator:id,name')->limit(40)->get()
                                    : collect();
                                $slotDeleteReason = $isActiveSlot
                                    ? 'Dieses Design ist für Systemmails aktiv. Aktiviere zuerst einen anderen Slot.'
                                    : (count($documentSlots) <= 1
                                        ? 'Mindestens ein Design-Slot muss erhalten bleiben.'
                                        : 'Beim Löschen werden auch alle Versionen dieses Entwurfs entfernt.');
                            @endphp
                            <x-ui.surface.card
                                padding="p-0"
                                class="overflow-hidden {{ $isCurrentSlot ? 'ring-2 ring-rt-red/30' : '' }}"
                                data-mail-design-slot
                                data-slot-id="{{ $slot->public_id }}"
                                data-slot-current="{{ $isCurrentSlot ? 'true' : 'false' }}"
                                data-slot-active="{{ $isActiveSlot ? 'true' : 'false' }}"
                            >
                                <div class="grid gap-4 p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="truncate text-base font-semibold tracking-tight" data-mail-slot-heading>{{ $slot->name ?: $slot->kind->label() }}</h3>
                                            @if ($isActiveSlot)
                                                <x-ui.badge color="green" data-mail-slot-active-badge>Für Systemmails aktiv</x-ui.badge>
                                            @else
                                                <x-ui.badge color="slate" data-mail-slot-draft-badge>Arbeitsentwurf</x-ui.badge>
                                            @endif
                                            @if ($isActiveSlot && $slot->hasUnpublishedChanges())
                                                <x-ui.badge color="amber" data-mail-slot-changed-badge>Neuere Entwurfsänderungen</x-ui.badge>
                                            @endif
                                            @if ($isCurrentSlot)
                                                <x-ui.badge color="red">Im Editor geöffnet</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                                            Dokumentversion {{ $slot->version }}
                                            @if ($slot->updated_at)
                                                · geändert {{ $slot->updated_at->translatedFormat('d.m.Y H:i') }} Uhr
                                            @endif
                                            @if ($slot->updater?->name)
                                                · {{ $slot->updater->name }}
                                            @endif
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap gap-2 lg:justify-end">
                                        @unless ($isCurrentSlot)
                                            <x-ui.buttons.button-basic
                                                type="button"
                                                mode="secondary"
                                                size="sm"
                                                class="min-h-11 rounded-xl px-3"
                                                data-mail-slot-open
                                                data-url="{{ route('admin.mail-documents.editor', ['dokument' => $slot->kind->value, 'slot' => $slot->public_id, 'open' => 1]) }}"
                                            >
                                                <i data-feather="edit-3" class="h-4 w-4" aria-hidden="true"></i>
                                                <span>Im Editor öffnen</span>
                                            </x-ui.buttons.button-basic>
                                        @endunless
                                        @unless ($isActiveSlot)
                                            <x-ui.buttons.button-basic
                                                type="button"
                                                mode="success"
                                                size="sm"
                                                class="min-h-11 rounded-xl px-3"
                                                data-mail-slot-activate
                                                data-endpoint="{{ route('admin.mail-documents.publish', $slot) }}"
                                                data-expected-hash="{{ $slot->content_hash }}"
                                                data-slot-name="{{ $slot->name }}"
                                            >
                                                <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
                                                <span>Aktiv veröffentlichen</span>
                                            </x-ui.buttons.button-basic>
                                        @endunless
                                    </div>
                                </div>

                                <div class="grid border-t border-rt-border dark:border-rt-dark-border lg:grid-cols-[minmax(16rem,0.8fr)_minmax(0,1.4fr)]">
                                    <div class="border-b border-rt-border p-4 dark:border-rt-dark-border lg:border-b-0 lg:border-r sm:p-5">
                                        <form
                                            class="grid gap-2"
                                            data-mail-slot-rename-form
                                            data-endpoint="{{ route('admin.mail-documents.slots.update', $slot) }}"
                                            data-expected-hash="{{ $slot->content_hash }}"
                                        >
                                            <label for="mail-slot-name-{{ $slot->public_id }}" class="text-xs font-semibold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted">Designname</label>
                                            <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                                <x-ui.forms.input id="mail-slot-name-{{ $slot->public_id }}" name="name" maxlength="80" value="{{ $slot->name }}" required aria-required="true" data-mail-slot-rename-name />
                                                <x-ui.buttons.button-basic type="submit" mode="secondary" size="sm" class="min-h-11 rounded-xl px-3" data-mail-slot-rename>
                                                    <i data-feather="check" class="h-4 w-4" aria-hidden="true"></i>
                                                    <span>Umbenennen</span>
                                                </x-ui.buttons.button-basic>
                                            </div>
                                        </form>

                                        <div class="mt-4 border-t border-rt-border pt-4 dark:border-rt-dark-border">
                                            <x-ui.buttons.button-basic
                                                type="button"
                                                mode="danger"
                                                size="sm"
                                                class="min-h-11 rounded-xl px-3"
                                                data-mail-slot-delete
                                                data-endpoint="{{ route('admin.mail-documents.slots.destroy', $slot) }}"
                                                data-expected-hash="{{ $slot->content_hash }}"
                                                data-slot-name="{{ $slot->name }}"
                                                :disabled="$isActiveSlot || count($documentSlots) <= 1"
                                                data-mail-slot-permanently-disabled="{{ $isActiveSlot || count($documentSlots) <= 1 ? 'true' : 'false' }}"
                                                aria-describedby="mail-slot-delete-help-{{ $slot->public_id }}"
                                                title="{{ $isActiveSlot ? 'Aktive Designs können erst nach dem Aktivieren eines anderen Slots gelöscht werden.' : (count($documentSlots) <= 1 ? 'Der letzte Design-Slot kann nicht gelöscht werden.' : 'Design-Slot löschen') }}"
                                            >
                                                <i data-feather="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                                <span>Design löschen</span>
                                            </x-ui.buttons.button-basic>
                                            <p id="mail-slot-delete-help-{{ $slot->public_id }}" class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $slotDeleteReason }}</p>
                                        </div>
                                    </div>

                                    <div class="min-w-0 p-4 sm:p-5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <h4 class="text-sm font-semibold">Versionsverlauf</h4>
                                                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Wiederherstellen erzeugt einen neuen Entwurf und verändert die aktive Veröffentlichung nicht.</p>
                                            </div>
                                            <x-ui.badge color="slate">
                                                @if ($slotVersionCount > 40)
                                                    Letzte 40 von {{ $slotVersionCount }} Versionen
                                                @else
                                                    {{ $slotVersionCount }} {{ $slotVersionCount === 1 ? 'Version' : 'Versionen' }}
                                                @endif
                                            </x-ui.badge>
                                        </div>

                                        @if (! $isCurrentSlot)
                                            <p class="mt-4 rounded-xl border border-dashed border-rt-border p-4 text-sm leading-6 text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">
                                                Öffne dieses Design im Editor, um seine Versionshistorie hier zu verwalten. Dadurch bleibt das Modal auch bei vielen Entwürfen schnell.
                                            </p>
                                        @else
                                        <ol class="mt-4 grid max-h-72 gap-2 overflow-y-auto pr-1" data-mail-slot-version-list>
                                            @forelse ($slotVersions as $version)
                                                <li class="grid gap-3 rounded-xl border border-rt-border bg-rt-surface-muted/45 p-3 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/35 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center" data-mail-slot-version="{{ $version->public_id }}">
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-semibold">
                                                            #{{ $version->revision }} ·
                                                            {{ match ($version->action) {
                                                                'imported' => 'Importiert',
                                                                'published' => 'Veröffentlicht',
                                                                'restored' => 'Wiederhergestellt',
                                                                'duplicated' => 'Dupliziert',
                                                                default => 'Gespeichert',
                                                            } }}
                                                        </p>
                                                        <p class="mt-1 truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                                            {{ $version->created_at?->translatedFormat('d.m.Y H:i') }} Uhr
                                                            @if ($version->creator?->name) · {{ $version->creator->name }} @endif
                                                            @if ($version->was_published) · war veröffentlicht @endif
                                                        </p>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2 sm:justify-end">
                                                        <x-ui.buttons.button-basic
                                                            type="button"
                                                            mode="secondary"
                                                            size="sm"
                                                            class="min-h-10 rounded-lg px-3"
                                                            data-mail-version-restore
                                                            data-endpoint="{{ route('admin.mail-documents.versions.restore', [$slot, $version]) }}"
                                                            data-expected-hash="{{ $slot->content_hash }}"
                                                            data-slot-url="{{ route('admin.mail-documents.editor', ['dokument' => $slot->kind->value, 'slot' => $slot->public_id, 'open' => 1]) }}"
                                                            data-slot-current="{{ $isCurrentSlot ? 'true' : 'false' }}"
                                                            data-revision="{{ $version->revision }}"
                                                        >Wiederherstellen</x-ui.buttons.button-basic>
                                                        <x-ui.buttons.button-basic
                                                            type="button"
                                                            mode="danger"
                                                            size="sm"
                                                            class="min-h-10 rounded-lg px-3"
                                                            data-mail-version-delete
                                                            data-endpoint="{{ route('admin.mail-documents.versions.destroy', [$slot, $version]) }}"
                                                            data-expected-hash="{{ $slot->content_hash }}"
                                                            data-revision="{{ $version->revision }}"
                                                            :disabled="$slotVersions->count() <= 1"
                                                            data-mail-slot-permanently-disabled="{{ $slotVersions->count() <= 1 ? 'true' : 'false' }}"
                                                            aria-describedby="mail-version-delete-help-{{ $slot->public_id }}"
                                                            title="{{ $slotVersions->count() <= 1 ? 'Die einzige gespeicherte Version bleibt als Rückfallebene erhalten.' : 'Historienversion löschen' }}"
                                                        >Löschen</x-ui.buttons.button-basic>
                                                    </div>
                                                </li>
                                            @empty
                                                <li class="rounded-xl border border-dashed border-rt-border p-4 text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">Noch keine gespeicherte Version vorhanden.</li>
                                            @endforelse
                                        </ol>
                                        @if ($slotVersions->count() <= 1)
                                            <p id="mail-version-delete-help-{{ $slot->public_id }}" class="mt-3 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die einzige gespeicherte Version bleibt als sichere Rückfallebene erhalten.</p>
                                        @else
                                            <p id="mail-version-delete-help-{{ $slot->public_id }}" class="sr-only">Gelöschte Historienversionen können nicht wiederhergestellt werden.</p>
                                        @endif
                                        @endif
                                    </div>
                                </div>
                            </x-ui.surface.card>
                        @endforeach
                    </div>
                @endif
            </x-ui.state-modal>
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
                    let editorBootState = 'loading';
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
                    let latestPreviewGeometry = null;
                    let compiledDeliveryHtml = '';
                    let deliveryPreviewRequest = null;
                    let deliveryPreviewGeneration = 0;
                    let forwardPreviewRestore = null;
                    let previewResizeFrame = null;
                    let resizeGesture = null;
                    let detachBuilderToolbarContext = null;
                    const controlListeners = new AbortController();
                    const MAIL_SOURCE_FORMAT = 'railtime-mail-document';
                    const MAIL_SOURCE_VERSION = 2;
                    const MAX_SOURCE_BYTES = 1024 * 1024;
                    const MAX_BUNDLE_BYTES = 16 * 1024 * 1024;
                    const MAX_MEDIA_BYTES = 2 * 1024 * 1024;
                    const toolsPanelId = `rt-dropdown-mail-document-tools-${config.currentDocument}-content`;
                    const queryToolControl = (selector) => window.document
                        .getElementById(toolsPanelId)
                        ?.querySelector(selector) || null;
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

                    const toast = (type, text, title) => window.dispatchEvent(new CustomEvent('swal:toast', {
                        detail: { type, text, title: title || undefined },
                    }));

                    const setMessage = (text) => {
                        if (messageNode) messageNode.textContent = text;
                    };
                    if (document_.autoRepaired) {
                        setMessage('Ein bekannter Signatur-Altstand wurde für den Editor sicher repariert. Beim nächsten Speichern wird Schema 28 übernommen.');
                    }

                    const setActionsBusy = (busy) => {
                        actionsBusy = Boolean(busy);
                        const testMailButton = queryToolControl('[data-mail-document-test-mail]');
                        [
                            saveButton,
                            publishButton,
                            codeApplyButton,
                            queryToolControl('[data-mail-code-open]'),
                            queryToolControl('[data-mail-code-export]'),
                            queryToolControl('[data-mail-code-import]'),
                            testMailButton,
                            ...codeCancelButtons,
                        ].forEach((button) => {
                            if (!button) return;

                            button.disabled = actionsBusy
                                || ([publishButton, testMailButton].includes(button) && compatibilityBlocksPublication);
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
                                    ? 'Compiler-Parität · produktive Systemmail-Quelle im Browser'
                                    : labels[activeDevice] || 'Editor');
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
                                disclaimer: 'Produktive Systemmail-Quelle · Outlook-Darstellung abschließend per Testmail prüfen.',
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
                        if (deliveryState) deliveryState.textContent = 'Aktueller Stand wird mit dem produktiven Systemmail-Compiler geprüft …';
                        if (deliveryFrame) deliveryFrame.srcdoc = '';

                        const candidate = currentCandidateForServer();
                        const payload = await request(document_.endpoints.deliveryPreview, 'POST', {
                            builder_data: candidate.builderData,
                            html: candidate.html,
                            css: candidate.css,
                            expected_hash: document_.contentHash || '',
                        }, { signal: deliveryPreviewRequest.signal });
                        if (generation !== deliveryPreviewGeneration || selectedViewMode === 'edit') return;
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
                        if (enteringForward) selectDevice('mobile');
                        if (leavingForward) restoreForwardPreviewViewport();
                        editorFrame?.setAttribute('data-mail-view-mode', selectedViewMode);
                        viewModeButtons.forEach((button) => {
                            button.setAttribute('aria-pressed', String(button.dataset.mailViewMode === selectedViewMode));
                            button.setAttribute('aria-busy', String(
                                selectedViewMode !== 'edit'
                                && button.dataset.mailViewMode === selectedViewMode
                            ));
                        });
                        themeButtons.forEach((button) => {
                            button.disabled = selectedViewMode !== 'edit';
                        });
                        themeControls?.setAttribute('aria-disabled', String(selectedViewMode !== 'edit'));
                        if (degradationSelect) degradationSelect.disabled = selectedViewMode === 'forward';

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
                            const unavailableTitle = selectedViewMode === 'forward'
                                ? 'Weiterleitungsansicht nicht verfügbar'
                                : 'Versandansicht nicht verfügbar';
                            const surfaced = showRequestError(error, unavailableTitle);
                            if (deliveryState) deliveryState.textContent = surfaced.message;
                            toast('error', surfaced.message, unavailableTitle);
                        } finally {
                            viewModeButtons.forEach((button) => button.setAttribute('aria-busy', 'false'));
                        }
                    };

                    const selectDegradationMode = (mode) => {
                        selectedDegradationMode = ['normal', 'images-off', 'head-css-off', 'css-off'].includes(mode)
                            ? mode
                            : 'normal';
                        if (degradationSelect) degradationSelect.value = selectedDegradationMode;
                        if (selectedViewMode !== 'edit') renderCompiledDeliveryHtml();
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
                        const expectedVersion = String(config.vendor.builderVersion || '');
                        const runtimeIsCurrent = () => window.LMZBuilder?.create
                            && (!expectedVersion || String(window.LMZBuilder.assetVersion || '') === expectedVersion);

                        if (runtimeIsCurrent()) {
                            return window.LMZBuilder;
                        }

                        await Promise.all([
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.coreCss }),
                            loadOnce('link', { rel: 'stylesheet', href: config.vendor.builderCss }),
                        ]);
                        await loadOnce('script', { src: config.vendor.builderJs, defer: true });

                        if (!runtimeIsCurrent()) {
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

                    const saveBeforeDesignAction = async () => {
                        if (codeDialog?.open) {
                            throw new Error('Schließe die Codeansicht zuerst mit „Prüfen & als Entwurf speichern“ oder „Abbrechen“.');
                        }
                        if (pendingPortableMedia.length > 0) {
                            throw new Error('Der vorbereitete Medienimport wurde noch nicht übernommen. Bitte schließe den Import zuerst ab.');
                        }

                        const focused = window.document.activeElement;
                        if (focused instanceof HTMLElement && focused !== window.document.body) {
                            focused.blur();
                        }
                        await new Promise((resolve) => window.requestAnimationFrame(resolve));
                        await saveCurrentDraft();
                    };

                    const dispatchConfirmation = ({ title, message, confirmLabel, variant = 'default', action }) => {
                        window.dispatchEvent(new CustomEvent('rt-confirm', {
                            detail: {
                                title,
                                message,
                                confirmLabel,
                                cancelLabel: 'Abbrechen',
                                variant,
                                action,
                            },
                        }));
                    };

                    const bindDesignManager = (attempt = 0) => {
                        const manager = window.document.querySelector('[data-mail-design-manager]');
                        if (!manager) {
                            if (!destroyed && attempt < 90) {
                                window.requestAnimationFrame(() => bindDesignManager(attempt + 1));
                            }
                            return;
                        }

                        const slotFor = (control) => control.closest('[data-mail-design-slot]');
                        const expectedHashFor = (control) => slotFor(control)?.dataset.slotCurrent === 'true'
                            ? document_.contentHash || ''
                            : control.dataset.expectedHash || '';
                        const fail = (error, title) => {
                            const surfaced = showRequestError(error, title);
                            toast('error', surfaced.message, title);
                            return surfaced;
                        };
                        const withBusy = async (callback) => {
                            setActionsBusy(true);
                            manager.setAttribute('aria-busy', 'true');
                            window.dispatchEvent(new CustomEvent('mail-design-manager-busy', { detail: true }));
                            manager.querySelectorAll('button, input').forEach((control) => {
                                control.disabled = true;
                            });
                            try {
                                return await callback();
                            } finally {
                                manager.removeAttribute('aria-busy');
                                manager.querySelectorAll('button, input').forEach((control) => {
                                    if (control.dataset.mailSlotPermanentlyDisabled !== 'true') {
                                        control.disabled = false;
                                    }
                                });
                                window.dispatchEvent(new CustomEvent('mail-design-manager-busy', { detail: false }));
                                setActionsBusy(false);
                            }
                        };

                        manager.addEventListener('submit', async (event) => {
                            const createForm = event.target.closest?.('[data-mail-slot-create-form]');
                            const renameForm = event.target.closest?.('[data-mail-slot-rename-form]');
                            if (!createForm && !renameForm) return;
                            event.preventDefault();

                            try {
                                await withBusy(async () => {
                                    await saveBeforeDesignAction();
                                    const form = createForm || renameForm;
                                    const name = String(form.querySelector('input[name="name"]')?.value || '').trim();
                                    if (!name) throw new Error('Bitte gib einen Namen für den Design-Slot ein.');
                                    const payload = await request(
                                        form.dataset.endpoint,
                                        createForm ? 'POST' : 'PATCH',
                                        {
                                            name,
                                            expected_hash: createForm
                                                ? document_.contentHash || ''
                                                : expectedHashFor(form),
                                        },
                                    );
                                    toast(
                                        'success',
                                        createForm ? 'Der neue Entwurf wurde aus dem aktuellen Design angelegt.' : 'Der Designname wurde gespeichert.',
                                        createForm ? 'Design angelegt' : 'Design umbenannt',
                                    );
                                    window.location.assign(payload.redirect || window.location.href);
                                });
                            } catch (error) {
                                fail(error, createForm ? 'Design konnte nicht angelegt werden' : 'Design konnte nicht umbenannt werden');
                            }
                        }, { signal: controlListeners.signal });

                        manager.addEventListener('click', (event) => {
                            const control = event.target.closest?.(
                                '[data-mail-slot-open], [data-mail-slot-activate], [data-mail-slot-delete], [data-mail-version-restore], [data-mail-version-delete]'
                            );
                            if (!control || control.disabled) return;

                            if (control.matches('[data-mail-slot-open]')) {
                                event.preventDefault();
                                withBusy(async () => {
                                    await saveBeforeDesignAction();
                                    window.location.assign(control.dataset.url);
                                }).catch((error) => fail(error, 'Design konnte nicht geöffnet werden'));
                                return;
                            }

                            if (control.matches('[data-mail-slot-activate]')) {
                                dispatchConfirmation({
                                    title: 'Design aktiv veröffentlichen?',
                                    message: `„${control.dataset.slotName || 'Dieses Design'}“ ersetzt die bisher aktive Fassung für Systemmails. Andere Entwürfe bleiben erhalten.`,
                                    confirmLabel: 'Aktiv veröffentlichen',
                                    action: () => withBusy(async () => {
                                        await saveBeforeDesignAction();
                                        await request(control.dataset.endpoint, 'POST', {
                                            expected_hash: expectedHashFor(control),
                                        });
                                        toast('success', 'Systemmails verwenden jetzt dieses Design.', 'Design aktiviert');
                                        window.location.reload();
                                    }).catch((error) => {
                                        fail(error, 'Design konnte nicht veröffentlicht werden');
                                        throw error;
                                    }),
                                });
                                return;
                            }

                            if (control.matches('[data-mail-slot-delete]')) {
                                dispatchConfirmation({
                                    title: 'Design-Slot löschen?',
                                    message: `„${control.dataset.slotName || 'Dieser Entwurf'}“ und seine Versionshistorie werden gelöscht. Das aktive Design bleibt unverändert.`,
                                    confirmLabel: 'Design löschen',
                                    variant: 'destructive',
                                    action: () => withBusy(async () => {
                                        await saveBeforeDesignAction();
                                        const payload = await request(control.dataset.endpoint, 'DELETE', {
                                            expected_hash: expectedHashFor(control),
                                        });
                                        toast('success', 'Der inaktive Design-Slot wurde gelöscht.', 'Design gelöscht');
                                        window.location.assign(payload.redirect || window.location.href);
                                    }).catch((error) => {
                                        fail(error, 'Design konnte nicht gelöscht werden');
                                        throw error;
                                    }),
                                });
                                return;
                            }

                            if (control.matches('[data-mail-version-delete]')) {
                                dispatchConfirmation({
                                    title: `Version #${control.dataset.revision} löschen?`,
                                    message: 'Der aktuelle Entwurf und die veröffentlichte Fassung bleiben unverändert.',
                                    confirmLabel: 'Version löschen',
                                    variant: 'destructive',
                                    action: () => withBusy(async () => {
                                        await saveBeforeDesignAction();
                                        await request(control.dataset.endpoint, 'DELETE', {
                                            expected_hash: expectedHashFor(control),
                                        });
                                        toast('success', 'Die Historienversion wurde gelöscht.', 'Version gelöscht');
                                        window.location.reload();
                                    }).catch((error) => {
                                        fail(error, 'Version konnte nicht gelöscht werden');
                                        throw error;
                                    }),
                                });
                                return;
                            }

                            dispatchConfirmation({
                                title: `Version #${control.dataset.revision} wiederherstellen?`,
                                message: 'Die Version wird als neuer Entwurf angelegt. Das aktive, veröffentlichte Design bleibt unverändert.',
                                confirmLabel: 'Wiederherstellen',
                                action: () => withBusy(async () => {
                                    await saveBeforeDesignAction();
                                    const payload = await request(control.dataset.endpoint, 'POST', {
                                        expected_hash: expectedHashFor(control),
                                    });
                                    toast('success', 'Die Version wurde als neuer Entwurf wiederhergestellt.', 'Version wiederhergestellt');
                                    if (control.dataset.slotCurrent !== 'true') {
                                        window.location.assign(control.dataset.slotUrl);
                                        return;
                                    }
                                    applyDocumentState(payload.document);
                                    activeBaselineHtml = String(document_.html || '');
                                    await runtimeBridge.rehydrateAuthoritative({
                                        editor: instance.editor,
                                        draft: document_,
                                        sanitizationChanged: true,
                                        parseCss: (canonicalCss) => instance.editor.Parser?.parseCss?.(canonicalCss) || [],
                                        projectOptions: { kind: config.currentDocument, environment: window },
                                    });
                                    window.location.reload();
                                }).catch((error) => {
                                    fail(error, 'Version konnte nicht wiederhergestellt werden');
                                    throw error;
                                }),
                            });
                        }, { signal: controlListeners.signal });
                    };
                    bindDesignManager();

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
                            setMessage(`Veröffentlicht am ${payload.document?.published_label ?? ''} Uhr — diese Fassung wird jetzt für Systemmails verwendet.`);
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
                        editorBootState = 'failed';
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
                        detachBuilderToolbarContext?.();
                        detachBuilderToolbarContext = null;
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
