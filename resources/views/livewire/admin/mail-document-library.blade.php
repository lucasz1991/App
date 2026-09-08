@php
    $libraryId = 'mail-library-'.$this->getId();
    $isSignature = $currentKind === \App\Enums\MailDocumentKind::Signature;
    $pendingAction = $pending['action'] ?? '';
    $confirmationTitle = match ($pendingAction) {
        'restore' => 'Version als Entwurf wiederherstellen',
        'default' => 'Outlook-Standard festlegen',
        'withdraw' => 'Outlook-Freigabe zurücknehmen',
        default => $deliveryReady ? 'Stand veröffentlichen' : (($pending['library'] ?? '') === 'true' ? 'Für Mitarbeitende freigeben' : 'Für Systemmails veröffentlichen'),
    };
@endphp

<section
    class="rt-mail-library"
    data-mail-document-library
    aria-label="Mail-Bibliothek"
    x-data="{
        createDialog: $wire.entangle('createOpen').live,
        confirmDialog: $wire.entangle('confirmOpen').live,
        pairingDialog: $wire.entangle('pairingOpen').live,
        previewDialog: false, previewUrl: '', previewName: '', previewVersion: '', previewTimer: null,
        openMailPreview(trigger) {
            clearTimeout(this.previewTimer);
            this.previewUrl = trigger.dataset.previewUrl;
            this.previewName = trigger.dataset.previewName;
            this.previewVersion = trigger.dataset.previewVersion;
            this.previewDialog = true;
        },
        destroy() { clearTimeout(this.previewTimer) }
    }"
>
    <div class="rt-mail-library__folders" role="group" aria-label="Dokumentart">
        @foreach (['template' => ['Vorlagen', 'fa-folder-open'], 'signature' => ['Signaturen', 'fa-signature']] as $kindValue => [$kindLabel, $kindIcon])
            <button type="button" wire:click="selectKind('{{ $kindValue }}')" class="rt-mail-library__folder" aria-pressed="{{ $currentKind->value === $kindValue ? 'true' : 'false' }}" data-mail-library-kind="{{ $kindValue }}">
                <i class="far {{ $kindIcon }}" aria-hidden="true"></i>
                <span>{{ $kindLabel }}</span>
                <span class="rt-mail-library__count">{{ $kindCounts[$kindValue] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    @if (! $ready)
        <div class="rt-mail-library__empty" role="status">
            <i class="far fa-folder" aria-hidden="true"></i>
            <h3>Die Mail-Bibliothek ist noch nicht eingerichtet.</h3>
            <p>Die erforderliche Datenbankstruktur fehlt. Bestehende Systemmails werden dadurch nicht verändert.</p>
        </div>
    @else
        <div class="rt-mail-library__tools">
            <label class="rt-mail-library__search" for="{{ $libraryId }}-search">
                <i class="far fa-search" aria-hidden="true"></i>
                <span class="sr-only">{{ $isSignature ? 'Signaturen' : 'Vorlagen' }} durchsuchen</span>
                <input id="{{ $libraryId }}-search" type="search" wire:model.live.debounce.250ms="search" maxlength="120" placeholder="{{ $isSignature ? 'Signatur suchen …' : 'Vorlage suchen …' }}" autocomplete="off">
            </label>
            <x-ui.dropdown.anchor-dropdown align="right" width="56" dropdown-id="{{ $libraryId }}-filter" layer-group="mail-document-library" content-label="Bibliothek filtern" content-classes="p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                <x-slot:trigger>
                    <button type="button" class="rt-mail-library__button rt-mail-library__button--filter">
                        <i class="far fa-filter" aria-hidden="true"></i>
                        {{ $filterLabel }}
                        <i class="far fa-chevron-down rt-mail-library__chevron" aria-hidden="true"></i>
                    </button>
                </x-slot:trigger>
                <x-slot:content>
                    @foreach (['all' => 'Alle Stände', 'draft' => 'Mit Entwurf', 'released' => 'Veröffentlicht', 'system' => 'Systemmail-Standard', 'outlook' => 'Outlook-Standard', 'available' => 'Im Add-in verfügbar', 'hidden' => 'Im Add-in ausgeblendet'] as $filterValue => $label)
                        <button type="button" role="menuitemradio" aria-checked="{{ $filter === $filterValue ? 'true' : 'false' }}" wire:click="selectFilter('{{ $filterValue }}')" x-on:click="close()" class="rt-mail-library-menu__item">
                            <span>{{ $label }}</span>
                            @if ($filter === $filterValue)<i class="far fa-check" aria-hidden="true"></i>@endif
                        </button>
                    @endforeach
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
        </div>

        @if ($notice !== '')
            <p class="rt-mail-library__notice" role="status"><i class="far fa-check-circle" aria-hidden="true"></i>{{ $notice }}</p>
        @endif
        @error('operation')
            @unless ($confirmOpen)<p class="rt-mail-library__error" role="alert">{{ $message }}</p>@endunless
        @enderror
        @if (! $libraryReady && ! $isSignature)
            <p class="rt-mail-library__hint">Neue Outlook-Vorlagen stehen nach der Bibliotheksmigration bereit. Vorhandene Designs bleiben bearbeitbar.</p>
        @endif

        <div class="rt-mail-library__list" aria-label="{{ $isSignature ? 'Signaturen' : 'E-Mail-Vorlagen' }}" wire:loading.class="rt-mail-library__list--updating" wire:target="search,selectKind,selectFilter">
            <div class="rt-mail-library__columns" aria-hidden="true"><span>Dokument</span><span>Stand</span><span>Zuletzt bearbeitet</span><span></span></div>
            @forelse ($documents as $document)
                <article class="rt-mail-library__entry" wire:key="mail-library-document-{{ $document['id'] }}" data-mail-library-document="{{ $document['id'] }}">
                    <div class="rt-mail-library__row">
                        <div class="rt-mail-library__document">
                            <x-ui.page-builder.mail-thumbnail :url="$document['preview_url']" :name="$document['name']" :version="$document['version']" />
                            <div class="rt-mail-library__name">
                                <a href="{{ $document['editor_url'] }}" data-mail-library-edit>{{ $document['name'] }}</a>
                                <small>{{ $isSignature ? 'Signatur' : 'Vorlage' }}<span aria-hidden="true"> · </span>Version {{ $document['version'] }}</small>
                                @if (! $isSignature && $pairingReady)
                                    <small class="rt-mail-library__pairing">
                                        <i class="far fa-link" aria-hidden="true"></i>
                                        {{ $document['signature_name'] ?: 'Kanal-Standardsignatur' }}
                                        @if ($document['signature_name'] && ! $document['signature_has_release'])
                                            <span>nur Entwurf</span>
                                        @endif
                                    </small>
                                @endif
                            </div>
                        </div>
                        <div class="rt-mail-library__statuses">
                            @if ($deliveryReady)
                                <livewire:admin.mail-document-delivery-controls :document-id="$document['id']" presentation="badges" :key="'list-delivery-'.$document['id']" />
                            @else
                            @if ($document['is_default'])
                                <span class="rt-mail-library__status rt-mail-library__status--default"><i class="far fa-star" aria-hidden="true"></i>{{ $document['library'] ? 'Outlook-Standard' : 'Systemstandard' }}</span>
                            @elseif ($document['released'])
                                <span class="rt-mail-library__status rt-mail-library__status--released">{{ $document['library'] ? 'Für Mitarbeitende' : 'Freigabe vorhanden' }}</span>
                            @endif
                            @if ($document['has_changes'])
                                <span class="rt-mail-library__status rt-mail-library__status--draft">{{ $document['released'] ? 'Neuer Entwurf' : 'Entwurf' }}</span>
                            @endif
                            @endif
                        </div>
                        <div class="rt-mail-library__updated"><span>{{ $document['updated'] ?? 'Noch nicht bearbeitet' }}</span><small>{{ $document['updater'] ?? '—' }}</small></div>
                        <div class="rt-mail-library__row-actions">
                            @if ($historyReady)
                                <button type="button" wire:click="toggleHistory('{{ $document['id'] }}')" class="rt-mail-library__icon-button" aria-label="Versionen von {{ $document['name'] }} anzeigen" aria-expanded="{{ $historyId === $document['id'] ? 'true' : 'false' }}" aria-controls="{{ $libraryId }}-history-{{ $document['id'] }}" title="{{ $document['versions_count'] }} Versionen" data-mail-library-history>
                                    <i class="far fa-history" aria-hidden="true"></i>
                                </button>
                            @endif
                            <x-ui.dropdown.anchor-dropdown align="right" width="72" dropdown-id="{{ $libraryId }}-actions-{{ $document['id'] }}" layer-group="mail-document-library" content-label="Aktionen für {{ $document['name'] }}" content-classes="p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                                <x-slot:trigger><button type="button" class="rt-mail-library__icon-button" aria-label="Aktionen für {{ $document['name'] }}"><i class="far fa-ellipsis-h" aria-hidden="true"></i></button></x-slot:trigger>
                                <x-slot:content>
                                    <a href="{{ $document['editor_url'] }}" role="menuitem" class="rt-mail-library-menu__item"><i class="far fa-pen" aria-hidden="true"></i>Im Editor öffnen</a>
                                    <a href="{{ $document['preview_url'] }}" role="menuitem" class="rt-mail-library-menu__item"><i class="far fa-eye" aria-hidden="true"></i>Vorschau öffnen</a>
                                    @if ($deliveryReady)
                                        <div class="rt-mail-library-menu__divider" role="separator"></div>
                                        <livewire:admin.mail-document-delivery-controls :document-id="$document['id']" presentation="menu" :key="'list-delivery-menu-'.$document['id']" />
                                    @endif
                                    @if ($libraryReady)
                                        @if (! $isSignature && $pairingReady)
                                            <button type="button" role="menuitem" wire:click="openPairing('{{ $document['id'] }}', '{{ $document['hash'] }}')" x-on:click="close()" class="rt-mail-library-menu__item"><i class="far fa-link" aria-hidden="true"></i>Signatur zuordnen</button>
                                        @endif
                                        <button type="button" role="menuitem" wire:click="openCreate('{{ $document['id'] }}', '{{ $document['hash'] }}')" x-on:click="close()" class="rt-mail-library-menu__item"><i class="far fa-copy" aria-hidden="true"></i>Als Entwurf duplizieren</button>
                                        <div class="rt-mail-library-menu__divider" role="separator"></div>
                                        <button type="button" role="menuitem" wire:click="prepareAction('publish', '{{ $document['id'] }}', '{{ $document['hash'] }}')" x-on:click="close()" class="rt-mail-library-menu__item" data-mail-library-publish><i class="far fa-cloud-upload" aria-hidden="true"></i>{{ $deliveryReady ? 'Stand veröffentlichen' : ($document['library'] ? 'Für Mitarbeitende freigeben' : ($isSignature ? 'Systemsignatur veröffentlichen' : 'Systemvorlage veröffentlichen')) }}</button>
                                        @if (! $deliveryReady && $document['library'] && $document['released'] && ! $document['is_default'])
                                            <button type="button" role="menuitem" wire:click="prepareAction('default', '{{ $document['id'] }}', '{{ $document['hash'] }}')" x-on:click="close()" class="rt-mail-library-menu__item" data-mail-library-default><i class="far fa-star" aria-hidden="true"></i>Als Outlook-Standard</button>
                                        @endif
                                        @if (! $deliveryReady && $document['library'] && $document['released'])
                                            <button type="button" role="menuitem" wire:click="prepareAction('withdraw', '{{ $document['id'] }}', '{{ $document['hash'] }}')" x-on:click="close()" class="rt-mail-library-menu__item"><i class="far fa-eye-slash" aria-hidden="true"></i>Freigabe zurücknehmen</button>
                                        @endif
                                    @endif
                                </x-slot:content>
                            </x-ui.dropdown.anchor-dropdown>
                        </div>
                    </div>

                    @if ($historyId === $document['id'])
                        <section class="rt-mail-library__history" id="{{ $libraryId }}-history-{{ $document['id'] }}" aria-label="Versionsverlauf für {{ $document['name'] }}">
                            <div class="rt-mail-library__history-heading"><h3>Versionsverlauf</h3><p>Wiederherstellen ändert nur den Entwurf, niemals die aktuelle Freigabe.</p></div>
                            <ol class="rt-mail-library__versions">
                                @forelse ($history as $version)
                                    @php
                                        $versionLabel = match ($version->action) {
                                            'delivery_system' => 'Systemmail-Standard festgelegt',
                                            'system_replaced' => 'Systemmail-Standard abgelöst',
                                            'delivery_outlook' => 'Outlook-Standard festgelegt',
                                            'outlook_replaced', 'delivery_outlook-off' => 'Outlook-Standard aufgehoben',
                                            'delivery_offer' => 'Im Add-in verfügbar',
                                            'delivery_hide' => 'Im Add-in ausgeblendet',
                                            'imported' => 'Importiert', 'published' => 'Veröffentlicht', 'restored' => 'Wiederhergestellt', 'duplicated' => 'Dupliziert', 'created' => 'Angelegt', 'signature_assigned' => 'Signatur zugeordnet', 'signature_cleared' => 'Signaturzuordnung entfernt', 'outlook_default' => 'Outlook-Standard festgelegt', 'withdrawn' => 'Freigabe zurückgenommen', default => 'Gespeichert',
                                        };
                                        $isCurrent = hash_equals($document['hash'], (string) $version->content_hash);
                                    @endphp
                                    <li wire:key="mail-library-version-{{ $version->public_id }}">
                                        <span class="rt-mail-library__revision">{{ $version->revision }}</span>
                                        <div class="rt-mail-library__version-copy"><strong>{{ $versionLabel }}</strong><small>{{ $version->created_at?->format('d.m.Y · H:i') }}@if ($version->creator) · {{ $version->creator->name }}@endif</small></div>
                                        @if ($isCurrent)
                                            <span class="rt-mail-library__status">Aktueller Entwurf</span>
                                        @elseif ($libraryReady)
                                            <button type="button" class="rt-mail-library__button rt-mail-library__button--subtle" wire:click="prepareAction('restore', '{{ $document['id'] }}', '{{ $document['hash'] }}', '{{ $version->public_id }}')" data-mail-library-restore><i class="far fa-undo" aria-hidden="true"></i>Wiederherstellen</button>
                                        @endif
                                    </li>
                                @empty
                                    <li class="rt-mail-library__history-empty">Noch keine gespeicherten Versionen vorhanden.</li>
                                @endforelse
                            </ol>
                            @if ($document['versions_count'] > 40)<p class="rt-mail-library__hint">Die letzten 40 von {{ $document['versions_count'] }} Versionen werden angezeigt.</p>@endif
                        </section>
                    @elseif ($historyReady)
                        <div id="{{ $libraryId }}-history-{{ $document['id'] }}" hidden></div>
                    @endif
                </article>
            @empty
                <div class="rt-mail-library__empty" role="status">
                    <i class="far {{ $search !== '' || $filter !== 'all' ? 'fa-search' : 'fa-folder-open' }}" aria-hidden="true"></i>
                    <h3>{{ $search !== '' || $filter !== 'all' ? 'Keine passenden Dokumente.' : ($isSignature ? 'Noch keine Signatur vorhanden.' : 'Hier beginnt deine Vorlagenbibliothek.') }}</h3>
                    <p>{{ $search !== '' || $filter !== 'all' ? 'Passe die Suche oder den Statusfilter an.' : ($isSignature ? 'Vorhandene Signaturen erscheinen hier mit ihrem Versionsverlauf.' : 'Lege einen Entwurf an. Erst nach deiner Freigabe erscheint er für Mitarbeitende in Outlook.') }}</p>
                </div>
            @endforelse
        </div>
        <p class="rt-mail-library__footnote"><i class="far fa-info-circle" aria-hidden="true"></i>{{ $isSignature ? 'Die Systemsignatur bleibt aktiv, während du an einem neuen Entwurf arbeitest.' : 'Outlook-Freigaben und Systemstandards sind getrennt. Eine neue Outlook-Vorlage verändert keine Systemmail.' }}</p>
    @endif

    <x-ui.state-modal :id="$libraryId.'-create'" state="createDialog" :title="isset($source['id']) ? 'Entwurf duplizieren' : 'Neue Outlook-Vorlage'" description="Der neue Stand bleibt privat im Entwurf, bis du ihn ausdrücklich freigibst." icon="far fa-file-plus" max-width="2xl">
        <form wire:submit="createDraft" class="rt-mail-library-form" id="{{ $libraryId }}-create-form">
            @if (isset($source['name']))<p class="rt-mail-library-form__context">Ausgangsdesign: <strong>{{ $source['name'] }}</strong></p>@endif
            <label for="{{ $libraryId }}-name">Name des Entwurfs</label>
            <input id="{{ $libraryId }}-name" wire:model="name" type="text" maxlength="80" required autocomplete="off" placeholder="Zum Beispiel: Angebotsanschreiben" aria-describedby="{{ $libraryId }}-name-help" @error('name') aria-invalid="true" @enderror>
            <p id="{{ $libraryId }}-name-help">Ein kurzer, eindeutiger Name hilft Mitarbeitenden bei der Auswahl.</p>
            @error('name')<p class="rt-mail-library__error" role="alert">{{ $message }}</p>@enderror
            @if ($pairingReady && $currentKind === \App\Enums\MailDocumentKind::Template)
                <label for="{{ $libraryId }}-create-signature">Signatur dieses Entwurfs</label>
                <x-ui.forms.select id="{{ $libraryId }}-create-signature" wire:model="createSignatureId">
                    <option value="">Jeweiligen Kanalstandard verwenden</option>
                    @foreach ($signatures as $signature)
                        <option value="{{ $signature->public_id }}">{{ $signature->name ?: 'Signatur' }}{{ $signature->published_at && trim((string) $signature->published_html) !== '' ? '' : ' (nur Entwurf)' }}</option>
                    @endforeach
                </x-ui.forms.select>
                <p>Die Zuordnung gilt zunächst nur für den neuen Entwurf und verändert keine aktive Signatur.</p>
                @error('signature')<p class="rt-mail-library__error" role="alert">{{ $message }}</p>@enderror
            @endif
        </form>
        <x-slot:footer>
            <button type="button" class="rt-mail-library__button" x-on:click="createDialog = false">Abbrechen</button>
            <button type="submit" form="{{ $libraryId }}-create-form" class="rt-mail-library__button rt-mail-library__button--primary" wire:loading.attr="disabled" wire:target="createDraft"><span wire:loading.remove wire:target="createDraft">Entwurf anlegen</span><span wire:loading wire:target="createDraft">Wird angelegt …</span></button>
        </x-slot:footer>
    </x-ui.state-modal>

    <x-ui.state-modal :id="$libraryId.'-pairing'" state="pairingDialog" title="Signatur zuordnen" description="Vorlage und Signatur bleiben getrennte Entwürfe und werden nur gemeinsam angezeigt." icon="far fa-link" max-width="2xl">
        <form wire:submit="savePairing" class="rt-mail-library-form" id="{{ $libraryId }}-pairing-form">
            <p class="rt-mail-library-form__document">{{ $pairing['name'] ?? '' }}</p>
            <label for="{{ $libraryId }}-signature">Signatur für diesen Vorlagenentwurf</label>
            <x-ui.forms.select id="{{ $libraryId }}-signature" wire:model="pairingSignatureId">
                <option value="">Jeweiligen Kanalstandard verwenden</option>
                @foreach ($signatures as $signature)
                    <option value="{{ $signature->public_id }}">{{ $signature->name ?: 'Signatur' }}{{ $signature->published_at && trim((string) $signature->published_html) !== '' ? '' : ' (nur Entwurf)' }}</option>
                @endforeach
            </x-ui.forms.select>
            <p>Die Vorschau verwendet sofort den zugeordneten Signaturentwurf. Eine Veröffentlichung ist erst möglich, nachdem auch diese Signatur einen freigegebenen Stand besitzt.</p>
            @error('signature')<p class="rt-mail-library__error" role="alert">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            <button type="button" class="rt-mail-library__button" x-on:click="pairingDialog = false">Abbrechen</button>
            <button type="submit" form="{{ $libraryId }}-pairing-form" class="rt-mail-library__button rt-mail-library__button--primary" wire:loading.attr="disabled" wire:target="savePairing"><span wire:loading.remove wire:target="savePairing">Zuordnung speichern</span><span wire:loading wire:target="savePairing">Wird gespeichert …</span></button>
        </x-slot:footer>
    </x-ui.state-modal>

    <x-ui.state-modal :id="$libraryId.'-confirm'" state="confirmDialog" :title="$confirmationTitle" icon="far fa-file-check" max-width="2xl">
        <div class="rt-mail-library-form">
            <p class="rt-mail-library-form__document">{{ $pending['name'] ?? '' }}</p>
            <p class="rt-mail-library-form__context">
                @if ($pendingAction === 'restore')
                    Version {{ $pending['revision'] ?? '' }} ersetzt den aktuellen Arbeitsentwurf. Die veröffentlichte Fassung bleibt bestehen. Der Versionsverlauf bleibt erhalten.
                @elseif ($pendingAction === 'default')
                    Diese freigegebene Vorlage wird bei neuen E-Mails, Antworten und Weiterleitungen in unterstützten Outlook-Clients automatisch oberhalb eingefügt. Vorhandener Text, zitierte Nachrichten und der Systemmail-Standard bleiben unverändert. Mobile Clients verwenden weiterhin die automatische Signatur.
                @elseif ($pendingAction === 'withdraw')
                    Mitarbeitende können diese Vorlage anschließend nicht mehr neu auswählen. Bereits eingefügte Inhalte und der gespeicherte Entwurf bleiben erhalten.
                @elseif ($deliveryReady)
                    Der gespeicherte Entwurf wird geprüft und als veröffentlichter Stand gespeichert. Bestehende Verwendungen erhalten diesen Stand. Es wird kein neuer Standard gewählt und keine neue Mitarbeitervorlage freigeschaltet. Diese Zuordnungen steuerst du getrennt über die Statussymbole oder das Aktionsmenü der Zeile.
                @elseif (($pending['library'] ?? '') === 'true')
                    Der gespeicherte Entwurf wird vollständig geprüft und danach in der Outlook-Auswahl für Mitarbeitende bereitgestellt. Der Systemmail-Standard wird nicht verändert.
                @else
                    Der gespeicherte Entwurf wird geprüft und anschließend als neuer Standard für Systemmails verwendet. Die bisher aktive {{ ($pending['kind'] ?? '') === 'signature' ? 'Signatur' : 'Systemvorlage' }} wird dadurch abgelöst.
                @endif
            </p>
            @error('operation')<p class="rt-mail-library__error" role="alert">{{ $message }}</p>@enderror
        </div>
        <x-slot:footer>
            <button type="button" class="rt-mail-library__button" x-on:click="confirmDialog = false">Abbrechen</button>
            <button type="button" wire:click="confirmAction" class="rt-mail-library__button rt-mail-library__button--primary" wire:loading.attr="disabled" wire:target="confirmAction"><span wire:loading.remove wire:target="confirmAction">{{ $pendingAction === 'restore' ? 'Als Entwurf wiederherstellen' : ($pendingAction === 'default' ? 'Standard festlegen' : ($pendingAction === 'withdraw' ? 'Freigabe zurücknehmen' : 'Prüfen und veröffentlichen')) }}</span><span wire:loading wire:target="confirmAction">Wird geprüft …</span></button>
        </x-slot:footer>
    </x-ui.state-modal>
    <x-ui.state-modal :id="$libraryId.'-preview'" state="previewDialog" title="Designvorschau" description="Aktuell gespeicherter Entwurf · Browserdarstellung, kein Mailclient-Nachweis." icon="far fa-eye" max-width="6xl">
        <p class="mb-3 text-sm font-semibold"><span x-text="previewName"></span> · Version <span x-text="previewVersion"></span></p>
        <template x-if="previewDialog">
            <x-ui.preview.frame title="Designvorschau des gespeicherten Entwurfs" x-bind:src="previewUrl" style="width:100%;height:65vh;min-height:260px" />
        </template>
    </x-ui.state-modal>
</section>
