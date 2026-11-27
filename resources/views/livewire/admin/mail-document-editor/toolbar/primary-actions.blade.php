                <div class="rt-mail-studio-toolbar__primary-actions" role="group" aria-label="Entwurf speichern und veröffentlichen">
                        <x-ui.buttons.button-basic
                            type="button"
                            mode="secondary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-document-save
                            title="Aktuellen Arbeitsstand als Entwurf speichern"
                        >
                            <i class="far fa-save h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label">Speichern</span>
                        </x-ui.buttons.button-basic>

                        <x-ui.buttons.button-basic
                            type="button"
                            mode="primary"
                            size="sm"
                            class="min-h-11 shrink-0 rounded-lg px-3"
                            data-mail-document-publish
                            aria-label="Entwurf speichern und veröffentlichen"
                            title="Aktuellen Entwurf speichern und für Mail-Notifications sowie Systemmails veröffentlichen"
                        >
                            <i class="far fa-cloud-upload h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__action-label">Veröffentlichen</span>
                        </x-ui.buttons.button-basic>
                </div>
