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
            <x-dialog-modal
                id="mail-design-manager-{{ $currentDocument->kind->value }}"
                show-expression="managerOpen"
                :instant="true"
                description="Arbeitsentwürfe und veröffentlichte Stände verwalten. Systemmail-Standard, Outlook-Standard und Mitarbeiterauswahl werden getrennt zugeordnet."
                icon="far fa-layer-group"
                max-width="6xl"
                close-action="closeManager()"
                body-class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain p-4 [scrollbar-gutter:stable] sm:p-6"
                data-mail-design-manager
                data-page-builder-subdialog
            >
                <x-slot:title>Designs &amp; Versionen</x-slot:title>
                <x-slot:headerActions>
                    <x-ui.badge color="slate">{{ count($documentSlots) }} {{ count($documentSlots) === 1 ? 'Design' : 'Designs' }}</x-ui.badge>
                </x-slot:headerActions>
                <x-slot:content>

                @if (! \Illuminate\Support\Facades\Schema::hasColumn('mail_documents', 'is_active'))
                    <x-ui.feedback.alert type="warning" :dismissible="false">
                        Die Design-Slot-Migration ist noch nicht installiert. Bitte führe zuerst die Datenbankmigrationen aus.
                    </x-ui.feedback.alert>
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
                            <i class="far fa-plus h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
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
                            @include('livewire.admin.mail-document-editor.design-manager-slot')
                        @endforeach
                    </div>
                @endif
                </x-slot:content>
            </x-dialog-modal>
        </div>
