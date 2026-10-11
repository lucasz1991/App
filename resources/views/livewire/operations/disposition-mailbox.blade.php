<div class="rt-disposition-mailbox-host" data-disposition-mailbox>
    @php
        $addressText = static function ($value): string {
            if (is_string($value)) return $value;
            if (!is_array($value)) return '';
            if (isset($value['email'])) return trim(($value['name'] ?? '').' <'.$value['email'].'>');
            return implode(', ', array_map(static function ($address): string {
                if (is_string($address)) return $address;
                return is_array($address) ? trim(($address['name'] ?? '').' <'.($address['email'] ?? '').'>') : '';
            }, $value));
        };
        $dateText = static function ($value): string {
            if (!$value) return 'Datum unbekannt';
            try { return \Carbon\CarbonImmutable::parse($value)->timezone('Europe/Berlin')->format('d.m.Y H:i'); }
            catch (\Throwable) { return 'Datum unbekannt'; }
        };
        $messages = $mailboxPage['messages'] ?? [];
        $total = (int) ($mailboxPage['total'] ?? 0);
        $perPage = max(1, (int) ($mailboxPage['per_page'] ?? 25));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $hasDetail = $selectedMessage !== [];
        $componentId = $this->getId();
    @endphp
    <x-dialog-modal :id="'disposition-mailbox-'.$componentId" wire:model.live="showMailbox" max-width="4xl" :instant="true">
        <x-slot:title>Dispositionspostfach</x-slot:title>
        <x-slot:content>
            <section class="rt-disposition rt-disposition-mailbox ops-stack" aria-label="Dispositionspostfach" x-data="{ focusedMessage: null }" x-effect="const message = $wire.selectedMessage; const next = message.uid || message.id || null; if (next !== focusedMessage) { const previous = focusedMessage; focusedMessage = next; $nextTick(() => { if (next) $refs.mailboxDetail?.focus({ preventScroll: true }); else if (previous) $refs.mailboxList?.querySelector('[data-message-id=&quot;' + previous + '&quot;]')?.focus(); }); }">
                <header class="rt-disposition-mailbox__account">
                    <div class="min-w-0">
                        <p class="rt-disposition-mailbox__address">{{ $connectionState['address'] ?? 'Dispositions-E-Mail-Konto' }}</p>
                        <span class="rt-disposition-mailbox__connection" data-connected="{{ ($connectionState['connected'] ?? false) ? 'true' : 'false' }}"><i class="far {{ ($connectionState['connected'] ?? false) ? 'fa-check-circle' : 'fa-exclamation-circle' }}" aria-hidden="true"></i>{{ ($connectionState['connected'] ?? false) ? 'Postfach verbunden' : 'Verbindung prüfen' }}</span>
                    </div>
                    <x-ui.buttons.button-basic type="button" wire:click="refresh" wire:loading.attr="disabled" aria-label="Postfach aktualisieren" title="Postfach aktualisieren"><i class="far fa-sync-alt" aria-hidden="true"></i><span class="rt-disposition-mailbox__refresh-label">Aktualisieren</span></x-ui.buttons.button-basic>
                </header>

                <nav class="rt-disposition-mailbox__tabs" aria-label="Postfachordner">
                    @foreach($this::TABS as $key => $label)
                        <button type="button" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" wire:loading.attr="disabled" wire:target="setTab,refresh,search,previousPage,nextPage">
                            <i class="far {{ ['inbox'=>'fa-inbox','sent'=>'fa-paper-plane','outbox'=>'fa-arrow-up-from-bracket'][$key] }}" aria-hidden="true"></i><span>{{ $label }}</span>@if($tab === $key && $mailboxPage !== [])<span class="rt-disposition-mailbox__count">{{ $total }}</span>@endif
                        </button>
                    @endforeach
                </nav>

                @if(!empty($connectionState['error']))
                    <div class="rt-disposition-mailbox__feedback" role="alert"><i class="far fa-exclamation-circle" aria-hidden="true"></i><p>{{ $connectionState['error'] }}</p><x-ui.buttons.button-basic type="button" size="sm" wire:click="open" wire:loading.attr="disabled">Erneut verbinden</x-ui.buttons.button-basic></div>
                @endif
                @error('mailbox')@if($message !== ($connectionState['error'] ?? null))<div class="rt-disposition-mailbox__feedback" role="alert"><p>{{ $message }}</p><x-ui.buttons.button-basic type="button" size="sm" wire:click="open" wire:loading.attr="disabled">Erneut laden</x-ui.buttons.button-basic></div>@endif @enderror

                <div class="rt-disposition-mailbox__search">
                    <label class="sr-only" for="disposition-mailbox-search-{{ $componentId }}">{{ $tab === 'outbox' ? 'Betreff suchen' : 'Betreff, Absender oder Empfänger suchen' }}</label>
                    <x-ui.forms.input id="disposition-mailbox-search-{{ $componentId }}" type="search" wire:model.live.debounce.400ms="search" maxlength="180" :placeholder="$tab === 'outbox' ? 'Betreff suchen' : 'Betreff, Absender oder Empfänger suchen'" />
                    <x-ui.forms.input-error for="search" />
                </div>

                @if($tab === 'outbox')<p class="rt-disposition-mailbox__hint">Kundennachrichten aus der AI-Disposition mit Freigabe- und Versandstatus.</p>@elseif($tab === 'sent')<p class="rt-disposition-mailbox__hint">Gesendete Nachrichten aus dem verbundenen Postfach.</p>@else<p class="rt-disposition-mailbox__hint">Alle Nachrichten im Eingangsordner. Anfragen markieren und gezielt zur Prüfung übernehmen.</p>@endif
                <div class="rt-disposition-mailbox__loading" wire:loading.flex wire:target="open,setTab,refresh,search,previousPage,nextPage,selectMessage,selectDelivery,classifyMessage,importSelectedInquiry,openLinkedIntake" role="status" aria-live="polite"><i class="far fa-spinner fa-spin" aria-hidden="true"></i><span>Postfach wird aktualisiert …</span></div>

                <div @class(['rt-disposition-mailbox__workspace', 'rt-disposition-mailbox__workspace--selected' => $hasDetail]) wire:loading.class="rt-disposition-mailbox__workspace--loading" wire:target="setTab,refresh,search,previousPage,nextPage">
                    <section class="rt-disposition-mailbox__list" aria-label="{{ $this::TABS[$tab] }}" x-ref="mailboxList">
                        @if($tab === 'sent' && ($mailboxPage['available'] ?? true) === false)
                            <div class="rt-disposition-mailbox__empty" role="status"><i class="far fa-folder-open" aria-hidden="true"></i><strong>Gesendet-Ordner nicht verfügbar</strong><p>Das Postfach stellt keinen erkennbaren Ordner für gesendete Nachrichten bereit. Der Versandstatus der App steht im Postausgang.</p><x-ui.buttons.button-basic type="button" size="sm" wire:click="setTab('outbox')">Postausgang öffnen</x-ui.buttons.button-basic></div>
                        @elseif($messages === [])
                            <div class="rt-disposition-mailbox__empty" role="status"><i class="far {{ $tab === 'outbox' ? 'fa-paper-plane' : 'fa-inbox' }}" aria-hidden="true"></i><strong>{{ trim($search) !== '' ? 'Keine passenden Nachrichten' : (($connectionState['connected'] ?? false) || $tab === 'outbox' ? 'Keine Nachrichten vorhanden' : 'Postfach noch nicht geladen') }}</strong><p>{{ trim($search) !== '' ? 'Mit einem anderen Betreff, Absender oder Empfänger suchen.' : ($tab === 'outbox' ? 'Vorbereitete und versendete Kundennachrichten erscheinen hier.' : (($connectionState['connected'] ?? false) ? 'Dieser Ordner enthält derzeit keine Nachrichten.' : 'Die Verbindung prüfen und das Postfach erneut laden.')) }}</p></div>
                        @else
                            <ul role="list">
                                @foreach($messages as $mail)
                                    @php
                                        $rowId = (int) ($tab === 'outbox' ? $mail['id'] : $mail['uid']);
                                        $selectedId = (int) ($tab === 'outbox' ? ($selectedMessage['id'] ?? 0) : ($selectedMessage['uid'] ?? 0));
                                        $subject = $mail['subject'] ?? 'Ohne Betreff';
                                        $classification = $mail['classification'] ?? 'unreviewed';
                                    @endphp
                                    <li wire:key="mailbox-{{ $tab }}-{{ $mailboxPage['uid_validity'] ?? 'app' }}-{{ $rowId }}">
                                        <button type="button" class="rt-disposition-mailbox__message" data-message-id="{{ $rowId }}" wire:click="{{ $tab === 'outbox' ? 'selectDelivery' : 'selectMessage' }}({{ $rowId }})" aria-pressed="{{ $hasDetail && $selectedId === $rowId ? 'true' : 'false' }}" aria-label="Nachricht öffnen: {{ $subject }}" wire:loading.attr="disabled" wire:target="selectMessage,selectDelivery,refresh,setTab,search,previousPage,nextPage,classifyMessage,importSelectedInquiry">
                                            <span class="rt-disposition-mailbox__message-top"><span class="rt-disposition-mailbox__sender">{{ $addressText($tab === 'inbox' ? ($mail['from'] ?? '') : ($mail['to'] ?? '')) ?: 'Adresse unbekannt' }}</span><time>{{ $dateText($mail['date'] ?? null) }}</time></span>
                                            <span class="rt-disposition-mailbox__subject">@if($tab === 'inbox' && !($mail['seen'] ?? true))<span class="rt-disposition-mailbox__unread"><span class="sr-only">Ungelesen · </span></span>@endif{{ $subject }}</span>
                                            <span class="rt-disposition-mailbox__badges">
                                                @if($tab === 'inbox')<span class="rt-disposition-mailbox__badge" data-classification="{{ $classification }}">{{ $mail['classification_label'] ?? ['inquiry'=>'Anfrage','not_inquiry'=>'Keine Anfrage','unreviewed'=>'Ungeprüft'][$classification] ?? 'Ungeprüft' }}</span>@if(!empty($mail['intake_status']))<span class="rt-disposition-mailbox__meta">{{ $intakeLabels[$mail['intake_status']] ?? 'Verarbeitung prüfen' }}</span>@endif
                                                @elseif($tab === 'outbox')<span class="rt-disposition-mailbox__badge" data-delivery-status="{{ $mail['status'] ?? 'unknown' }}">{{ $mail['status_label'] ?? 'Versand prüfen' }}</span><span class="rt-disposition-mailbox__meta">{{ $mail['type_label'] ?? 'Kundennachricht' }}</span>@endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($mailboxPage !== [] && $total > 0)
                            <footer class="rt-disposition-mailbox__pagination" aria-label="Nachrichtenseiten">
                                <span>{{ number_format(($page - 1) * $perPage + 1, 0, ',', '.') }}–{{ number_format(min($page * $perPage, $total), 0, ',', '.') }} von {{ number_format($total, 0, ',', '.') }}</span>
                                <div><x-ui.buttons.button-basic type="button" size="sm" wire:click="previousPage" :disabled="$page <= 1 ? true : null" wire:loading.attr="disabled" aria-label="Vorherige Nachrichtenseite"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic><span aria-live="polite">{{ $page }} / {{ $lastPage }}</span><x-ui.buttons.button-basic type="button" size="sm" wire:click="nextPage" :disabled="$page >= $lastPage ? true : null" wire:loading.attr="disabled" aria-label="Nächste Nachrichtenseite"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic></div>
                            </footer>
                        @endif
                    </section>

                    <section class="rt-disposition-mailbox__detail" aria-label="Nachrichtendetails" tabindex="-1" x-ref="mailboxDetail">
                        @if($hasDetail)
                            <div class="rt-disposition-mailbox__detail-back"><x-ui.buttons.button-basic type="button" size="sm" wire:click="clearSelection"><i class="far fa-arrow-left" aria-hidden="true"></i>Nachrichtenliste</x-ui.buttons.button-basic></div>
                            <header><p class="rt-disposition-mailbox__detail-date">{{ $dateText($selectedMessage['date'] ?? null) }}</p><h3>{{ $selectedMessage['subject'] ?? 'Ohne Betreff' }}</h3><dl class="rt-disposition-mailbox__headers"><div><dt>Von</dt><dd>{{ $addressText($selectedMessage['from'] ?? '') ?: 'Adresse unbekannt' }}</dd></div><div><dt>An</dt><dd>{{ $addressText($selectedMessage['to'] ?? '') ?: 'Adresse unbekannt' }}</dd></div></dl></header>
                            @if($tab === 'inbox')
                                @php($classification = $selectedMessage['classification'] ?? 'unreviewed')
                                <div class="rt-disposition-mailbox__classification">
                                    <span class="rt-disposition-mailbox__badge" data-classification="{{ $classification }}">{{ $selectedMessage['classification_label'] ?? ['inquiry'=>'Anfrage','not_inquiry'=>'Keine Anfrage','unreviewed'=>'Ungeprüft'][$classification] ?? 'Ungeprüft' }}</span>
                                    @if(!empty($selectedMessage['intake_status']))<span class="rt-disposition-mailbox__meta">{{ $intakeLabels[$selectedMessage['intake_status']] ?? 'Verarbeitung prüfen' }}</span>@endif
                                    <div class="rt-disposition-mailbox__classify-actions"><x-ui.buttons.button-basic type="button" size="sm" wire:click="classifyMessage('inquiry')" :disabled="$classification === 'inquiry' ? true : null" wire:loading.attr="disabled">Anfrage</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" size="sm" wire:click="classifyMessage('not_inquiry')" :disabled="$classification === 'not_inquiry' ? true : null" wire:loading.attr="disabled">Keine Anfrage</x-ui.buttons.button-basic>@if($classification !== 'unreviewed')<x-ui.buttons.button-basic type="button" mode="link" size="sm" wire:click="classifyMessage('unreviewed')" wire:loading.attr="disabled">Zurücksetzen</x-ui.buttons.button-basic>@endif</div>
                                </div>
                            @elseif($tab === 'outbox')
                                <div class="rt-disposition-mailbox__classification"><span class="rt-disposition-mailbox__badge" data-delivery-status="{{ $selectedMessage['status'] ?? 'unknown' }}">{{ $selectedMessage['status_label'] ?? 'Versand prüfen' }}</span><span class="rt-disposition-mailbox__meta">{{ $selectedMessage['type_label'] ?? 'Kundennachricht' }}</span></div>
                                @if(($selectedMessage['status'] ?? '') === 'unknown')<p class="rt-disposition-mailbox__hint" role="status">Der Versand ist unklar. Vor einer weiteren Aktion im Gesendet-Ordner prüfen, ob die Nachricht zugestellt wurde.</p>@elseif(($selectedMessage['status'] ?? '') === 'draft')<p class="rt-disposition-mailbox__hint">Empfänger und Nachricht persönlich im AI-Assistenten freigeben oder verwerfen.</p>@endif
                            @endif
                            @php($messageText = (string) ($selectedMessage['text'] ?? $selectedMessage['body'] ?? ''))
                            <div class="rt-disposition-mailbox__body">{{ trim($messageText) !== '' ? $messageText : 'Kein lesbarer Nachrichtentext vorhanden.' }}</div>
                            @if(!empty($selectedMessage['attachments']))
                                <section class="rt-disposition-mailbox__attachments" aria-label="Nachrichtenanlagen"><h4>Anlagen <span>{{ count($selectedMessage['attachments']) }}</span></h4><ul role="list">@foreach($selectedMessage['attachments'] as $attachment)<li><i class="far fa-paperclip" aria-hidden="true"></i><span>{{ is_array($attachment) ? ($attachment['file_name'] ?? $attachment['name'] ?? 'Anlage') : $attachment }}</span></li>@endforeach</ul>@if($tab === 'inbox')<p class="rt-disposition-mailbox__hint">Anlagen werden bei der Übernahme im ursprünglichen Eingang archiviert.</p>@endif</section>
                            @endif
                            @if($tab === 'inbox' || $tab === 'outbox')
                                <footer class="rt-disposition-mailbox__detail-actions">
                                    @if(!empty($selectedMessage['intake_id']))<x-ui.buttons.button-basic type="button" mode="primary" wire:click="openLinkedIntake({{ (int) $selectedMessage['intake_id'] }})" wire:loading.attr="disabled"><i class="far fa-external-link-alt" aria-hidden="true"></i>Anfrage prüfen</x-ui.buttons.button-basic>
                                    @elseif($tab === 'inbox')<x-ui.buttons.button-basic type="button" mode="primary" wire:click="importSelectedInquiry" wire:loading.attr="disabled"><i class="far fa-inbox" aria-hidden="true"></i>Als Anfrage übernehmen</x-ui.buttons.button-basic>@endif
                                    @if($tab === 'outbox' && ($selectedMessage['status'] ?? '') === 'draft')<x-ui.buttons.button-basic type="button" x-on:click="$wire.close().then(() => { $dispatch('railtime-assistant-open'); $wire.dispatch('operations-assistant-action', { action: 'outbox' }); })">Nachricht freigeben / verwerfen</x-ui.buttons.button-basic>@endif
                                    @if($tab === 'inbox' && empty($selectedMessage['intake_id']))<p class="rt-disposition-mailbox__hint">Die Übernahme erstellt einen Eingang zur Prüfung. Weitere Schritte erfolgen im Anfrage-Dialog.</p>@endif
                                </footer>
                            @endif
                        @else
                            <div class="rt-disposition-mailbox__empty rt-disposition-mailbox__empty--detail"><i class="far fa-envelope-open" aria-hidden="true"></i><strong>Nachricht auswählen</strong><p>{{ $tab === 'inbox' ? 'Inhalt prüfen, als Anfrage kennzeichnen und bei Bedarf übernehmen.' : 'Inhalt und Versandstatus einer Nachricht prüfen.' }}</p></div>
                        @endif
                    </section>
                </div>
            </section>
        </x-slot:content>
        <x-slot:footer><x-ui.buttons.button-basic type="button" wire:click="close">Schließen</x-ui.buttons.button-basic></x-slot:footer>
    </x-dialog-modal>
    @if($selectedIntakeId)
        <livewire:operations.ai-intake-inbox :initial-intake-id="$selectedIntakeId" :modal-only="true" :key="'mailbox-intake-'.$selectedIntakeId.'-'.$intakeRevision" />
    @endif
</div>
