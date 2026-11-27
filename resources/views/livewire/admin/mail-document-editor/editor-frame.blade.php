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
