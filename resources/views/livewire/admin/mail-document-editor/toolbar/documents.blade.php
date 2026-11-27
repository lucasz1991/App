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
                                <i class="far fa-file-alt h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Dokument</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true">
                                    <i class="far fa-chevron-down h-3.5 w-3.5 text-xs leading-[14px] text-center" aria-hidden="true"></i>
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
                                <i class="far fa-layer-group h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Inhalt</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i class="far fa-chevron-down h-3.5 w-3.5 text-xs leading-[14px] text-center" aria-hidden="true"></i></span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="grid gap-1">
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="left:blocks" x-on:click="close()" title="Bausteine in der linken Seitenleiste öffnen">
                                    <i class="far fa-th-large h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Bausteine</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="left:layers" x-on:click="close()" title="Ebenen in der linken Seitenleiste öffnen">
                                    <i class="far fa-layer-group h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Ebenen</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-action="assets" x-on:click="close()" title="Medienbibliothek öffnen">
                                    <i class="far fa-image h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Medien</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-action="upload" x-on:click="close()" title="Bild oder GIF hochladen" hidden aria-disabled="true">
                                    <i class="far fa-upload h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Bild / GIF hochladen</span>
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
                                <i class="far fa-pen h-4 w-4 shrink-0 text-sm leading-4 text-center" aria-hidden="true"></i>
                                <span class="rt-mail-studio-toolbar__menu-label">Bearbeiten</span>
                                <span class="inline-flex h-3.5 w-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" aria-hidden="true"><i class="far fa-chevron-down h-3.5 w-3.5 text-xs leading-[14px] text-center" aria-hidden="true"></i></span>
                            </x-ui.buttons.button-basic>
                        </x-slot:trigger>

                        <x-slot:content>
                            <div class="grid grid-cols-2 gap-1 border-b border-rt-border pb-2 dark:border-rt-dark-border">
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 justify-center rounded-lg px-3" data-mail-builder-action="undo" x-on:click="close()" title="Letzte Änderung rückgängig machen">
                                    <i class="far fa-undo h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Zurück</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 justify-center rounded-lg px-3" data-mail-builder-action="redo" x-on:click="close()" title="Änderung wiederholen">
                                    <i class="far fa-redo h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Vor</span>
                                </x-ui.buttons.button-basic>
                            </div>
                            <div class="mt-2 grid gap-1">
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="right:styles" x-on:click="close()" title="Stile in der rechten Seitenleiste öffnen">
                                    <i class="far fa-sliders-h h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Stile</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="right:traits" x-on:click="close()" title="Eigenschaften in der rechten Seitenleiste öffnen">
                                    <i class="far fa-cog h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Eigenschaften</span>
                                </x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic type="button" mode="secondary" size="sm" class="min-h-11 w-full justify-start rounded-lg px-3" data-mail-builder-panel="right:classes" x-on:click="close()" title="Klassen in der rechten Seitenleiste öffnen">
                                    <i class="far fa-tag h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i><span>Klassen</span>
                                </x-ui.buttons.button-basic>
                            </div>
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
                </div>
