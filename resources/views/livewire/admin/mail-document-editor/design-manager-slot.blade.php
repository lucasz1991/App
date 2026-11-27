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
                                            @if (\App\Support\Mail\MailDocumentDelivery::available())
                                                <livewire:admin.mail-document-delivery-controls :document-id="$slot->public_id" :key="'editor-delivery-'.$slot->public_id" />
                                            @elseif ($isActiveSlot)
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
                                                <i class="far fa-pen h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                                                <span>Im Editor öffnen</span>
                                            </x-ui.buttons.button-basic>
                                        @endunless
                                        @if (! $isActiveSlot && ! \App\Support\Mail\MailDocumentDelivery::available())
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
                                                <i class="far fa-cloud-upload h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                                                <span>Aktiv veröffentlichen</span>
                                            </x-ui.buttons.button-basic>
                                        @endif
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
                                                    <i class="far fa-check h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
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
                                                <i class="far fa-trash-alt h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
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
