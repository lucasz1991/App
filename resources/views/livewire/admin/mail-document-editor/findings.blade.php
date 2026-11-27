            <div data-mail-document-findings hidden>
                <h3 data-mail-document-findings-title>Prüfstatus</h3>
                <p class="mt-1 leading-6" data-mail-document-check-summary hidden></p>
                <ul class="mt-1 list-disc space-y-1 pl-5 leading-6" data-mail-document-findings-list></ul>
                <details class="mt-2 text-xs leading-5" data-mail-document-format-rules>
                    <summary class="cursor-pointer font-semibold">Mailregeln: HTML, OFT und MSG</summary>
                    <p class="mt-2">OFT speichert eine Vorlage, MSG eine Nachricht. Beide Dateiformate umgehen keine Darstellungsgrenzen von Outlook. Die Browser-Vorschau ist kein Nachweis für einen Mailclient.</p>
                    <p class="mt-1">Grundlayout: Tabellen, Abstände in Tabellenzellen, Systemschriften und Bilder mit erhaltenen Proportionen. Der Zug bleibt ein echtes IMG mit eingebettetem Medium. Negative Margins und positionierte Ebenen bleiben bearbeitbar, sind aber kein verlässliches clientübergreifendes Grundlayout.</p>
                    <p class="mt-1">Systemmail und Outlook-Add-in werden getrennt geprüft: Kritische Mail-Styles stehen inline; für die Einfügung über Office.js gelten zusätzliche API-Grenzen. Ein automatischer Prüferfolg bestätigt weder die Einfügung noch die empfangene Darstellung.</p>
                    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                        <a class="underline underline-offset-2" href="https://support.microsoft.com/en-us/outlook/download-add-and-share-templates-as-oft-files-in-outlook" target="_blank" rel="noopener noreferrer">Microsoft: OFT</a>
                        <a class="underline underline-offset-2" href="https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxmsg/1a69e000-f391-4c03-9d43-32d5f554bca7" target="_blank" rel="noopener noreferrer">Microsoft: MSG</a>
                        <a class="underline underline-offset-2" href="https://learn.microsoft.com/en-us/troubleshoot/dynamics-365/customer-insights/journeys/email/email-troubleshoot-rendering" target="_blank" rel="noopener noreferrer">Microsoft: Maildarstellung</a>
                        <a class="underline underline-offset-2" href="https://learn.microsoft.com/en-us/javascript/api/outlook/office.body?view=outlook-js-preview" target="_blank" rel="noopener noreferrer">Microsoft: Add-in-Grenzen</a>
                    </p>
                </details>
            </div>
