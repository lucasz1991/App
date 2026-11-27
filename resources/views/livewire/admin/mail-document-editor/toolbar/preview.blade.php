                <div class="rt-mail-studio-toolbar__preview" data-mail-toolbar-region="preview" data-mail-preview-toolbar>
                    <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 shrink-0 rounded-lg px-3" data-mail-view-shortcut title="Zwischen Bearbeitung und serverseitiger Versandvorschau wechseln">
                        <i class="far fa-pen h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                        <span data-mail-view-shortcut-label>Layout bearbeiten</span>
                    </x-ui.buttons.button-basic>
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
                                <i class="far fa-desktop h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Ansicht</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i class="far fa-chevron-down h-3.5 w-3.5 text-xs leading-[14px] text-center" aria-hidden="true"></i></span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="rt-mail-view-menu">
                                <div class="rt-mail-preview-context">
                                    <strong>Versandvorschau &amp; Bearbeitung</strong>
                                    <small data-mail-preview-status aria-live="polite">Systemmail breit · 1920 px · wird eingepasst</small>
                                    <small id="mail-responsive-help-{{ $currentDocument->kind->value }}" data-mail-responsive-note>
                                        Desktop, Tablet und Mobil ändern nur die Vorschau. Formatierungen gelten für alle Breiten; Spalten und Abstände passen sich automatisch an.
                                    </small>
                                    <small data-mail-compiler-parity-note>
                                        „Bearbeiten“ ist die Autorenansicht mit Platzhaltern. „Versandvorschau“ zeigt den aktuellen Entwurf nach dem echten Systemmail-Compiler und CSS-Inliner (Compiler-Parität). Andere Bausteine stammen aus der Freigabe, bei der Ersteinrichtung gegebenenfalls aus deren Entwurf. Outlook erhält clientspezifische Medien- und Wrapper-Anpassungen. Keine Ansicht emuliert Outlook oder iPhone Mail; die Empfangsprüfung erfolgt per Testmail.
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
                                    <i class="far fa-eye h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                                    <span>Leinwand-Vorschau</span>
                                </x-ui.buttons.button-basic>

                    <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-findings-open>
                        <i class="far fa-shield-alt h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                        <span>Prüfhinweise anzeigen</span>
                    </x-ui.buttons.button-basic>

                    <div class="rt-mail-preview-toggle" role="group" aria-label="Editoransicht">
                        <button type="button" data-mail-view-mode="edit" aria-pressed="false" title="Autorenansicht mit Platzhaltern; keine Mailclient-Emulation">
                            <i class="far fa-pen h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Bearbeiten</span>
                        </button>
                        <button type="button" data-mail-view-mode="delivery" aria-pressed="true" title="Aktuellen Entwurf mit dem produktiven Systemmail-Compiler prüfen; die Darstellung erfolgt weiterhin im Browser">
                            <i class="far fa-envelope h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Versandvorschau</span>
                        </button>
                        <button type="button" data-mail-view-mode="forward" aria-pressed="false" title="Kompiliertes Versand-HTML als zitierte Weiterleitung ohne Head-CSS prüfen">
                            <i class="far fa-share h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Weiterleitung</span>
                        </button>
                    </div>

                    <div class="rt-mail-preview-toggle" data-mail-theme-controls role="group" aria-label="Farbschema der Editorvorschau">
                        <button type="button" data-mail-theme-button="light" aria-pressed="true" title="Helle Mail ansehen">
                            <i class="far fa-sun h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Hell</span>
                        </button>
                        <button type="button" data-mail-theme-button="dark" aria-pressed="false" title="Dunkle Mail ansehen">
                            <i class="far fa-moon h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Dunkel</span>
                        </button>
                    </div>

                    <div class="rt-mail-preview-toggle" role="group" aria-label="Breite des Mailprogramms" aria-describedby="mail-responsive-help-{{ $currentDocument->kind->value }}">
                        <button type="button" data-mail-preview-device="wide" aria-pressed="true" title="Breite Systemmail-Vorschau mit 1920 Pixeln">
                            <i class="far fa-expand-alt h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Breit</span>
                        </button>
                        <button type="button" data-mail-preview-device="desktop" aria-pressed="false" title="Desktop-Vorschau mit 1024 Pixeln">
                            <i class="far fa-desktop h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Desktop</span>
                        </button>
                        <button type="button" data-mail-preview-device="tablet" aria-pressed="false" title="Tablet-Vorschau mit 820 Pixeln">
                            <i class="far fa-tablet-alt h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Tablet</span>
                        </button>
                        <button type="button" data-mail-preview-device="mobile" aria-pressed="false" title="Mobil-Vorschau mit 375 Pixeln">
                            <i class="far fa-mobile-alt h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Mobil</span>
                        </button>
                    </div>

                    <label class="rt-mail-preview-width-control" title="Ganzzahlige Breite der Responsive-Vorschau">
                        <span class="sr-only">Individuelle Vorschau-Breite in Pixeln</span>
                        <x-ui.forms.number-input
                            :stepper="false"
                            min="320"
                            max="1920"
                            value="1920"
                            data-mail-preview-width
                            aria-label="Individuelle Vorschau-Breite in ganzen Pixeln"
                            aria-describedby="mail-responsive-help-{{ $currentDocument->kind->value }}"
                        />
                        <span aria-hidden="true">px</span>
                    </label>

                    <label class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-rt-border bg-rt-surface px-2 text-xs font-semibold text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text">
                        <i class="far fa-shield-alt h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
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
                        <i class="far fa-redo-alt h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                        <span>Animation neu starten</span>
                    </button>
                            </div>
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
                </div>
