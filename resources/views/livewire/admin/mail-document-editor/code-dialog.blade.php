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
                        <i class="far fa-times h-5 w-5 text-lg leading-5 text-center" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="rt-mail-code-dialog__notice" role="note">
                    <i class="far fa-shield-alt h-5 w-5 text-lg leading-5 text-center" aria-hidden="true"></i>
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
                            <i class="far fa-check-circle h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span>Prüfen &amp; als Entwurf speichern</span>
                        </x-ui.buttons.button-basic>
                    </div>
                </footer>
            </form>
        </dialog>
