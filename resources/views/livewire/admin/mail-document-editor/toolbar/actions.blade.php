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
                                Entwurf gespeichert — zugeordnete Verwendungsbereiche nutzen weiterhin die Veröffentlichung vom {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr.
                            @else
                                Veröffentlichter Stand vom {{ $currentDocument->published_at?->translatedFormat('d.m.Y H:i') }} Uhr. Die Verwendung steuerst du oben unter „Verwendung“.
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
                            aria-label="Designs und gespeicherte Versionen verwalten"
                            title="Design-Slots und gespeicherte Versionen verwalten"
                        >
                            <i class="far fa-clock h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                            <span class="rt-mail-studio-toolbar__menu-label">Versionen</span>
                        </x-ui.buttons.button-basic>

                        @if (\App\Support\Mail\MailDocumentDelivery::available())
                            <x-ui.dropdown.anchor-dropdown
                                align="right"
                                width="80"
                                :offset="8"
                                dropdown-id="mail-document-usage-{{ $currentDocument->public_id }}"
                                layer-group="mail-document-editor"
                                content-role="menu"
                                content-label="Verwendung von {{ $currentDocument->name }} ändern"
                                content-classes="bg-rt-surface p-2 text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                                dropdown-classes="shadow-xl"
                                data-mail-toolbar-menu="usage"
                            >
                                <x-slot:trigger>
                                    <x-ui.buttons.button-basic
                                        type="button"
                                        mode="secondary"
                                        size="sm"
                                        class="min-h-11 shrink-0 rounded-lg px-3"
                                        aria-label="Verwendung von {{ $currentDocument->name }} ändern"
                                        title="Systemmail-, Outlook- und Add-in-Verwendung einstellen"
                                        x-on:click="$dispatch('mail-editor-delivery-refresh', { documentId: @js($currentDocument->public_id) })"
                                    >
                                        <i class="far fa-sliders-h h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                                        <span class="rt-mail-studio-toolbar__menu-label">Verwendung</span>
                                        <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i class="far fa-chevron-down h-3.5 w-3.5 text-xs leading-[14px] text-center" aria-hidden="true"></i></span>
                                    </x-ui.buttons.button-basic>
                                </x-slot:trigger>

                                <x-slot:content>
                                    <livewire:admin.mail-document-delivery-controls
                                        :document-id="$currentDocument->public_id"
                                        presentation="menu"
                                        :key="'editor-toolbar-delivery-'.$currentDocument->public_id"
                                    />
                                </x-slot:content>
                            </x-ui.dropdown.anchor-dropdown>
                        @endif

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
                                    <i class="far fa-ellipsis-h h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                                    <span>Werkzeuge</span>
                                    <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true">
                                        <i class="far fa-chevron-down h-3.5 w-3.5 text-xs leading-[14px] text-center" aria-hidden="true"></i>
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
                                        <i class="far fa-paper-plane h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
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
                                        <i class="far fa-code h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
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
                                        <i class="far fa-download h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
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
                                        <i class="far fa-file-plus h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                                        <span class="rt-mail-studio-toolbar__action-label rt-mail-studio-toolbar__utility-label">Import</span>
                                    </x-ui.buttons.button-basic>

                                </div>
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>
                    </div>
                </div>
