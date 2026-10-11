<section class="rt-disposition rt-unified-inbox" aria-label="Gemeinsamer Eingang" wire:poll.visible.15s>
    <header class="rt-unified-inbox__toolbar">
        <nav class="rt-unified-inbox__statuses" aria-label="Bearbeitungsstand">
            @foreach($this::FILTERS as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" wire:loading.attr="disabled" wire:target="setStatus">
                    {{ $label }} <span>{{ $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0) }}</span>
                </button>
            @endforeach
        </nav>
        <div class="ops-actions">
            @if($mailboxAvailable)<x-ui.buttons.button-basic mode="secondary" type="button" wire:click="openMailbox" wire:loading.attr="disabled" wire:target="openMailbox" aria-label="Dispositionspostfach öffnen"><i class="far fa-envelope" aria-hidden="true"></i><span>Postfach</span></x-ui.buttons.button-basic>@endif
            @if(isset($sections['overview']))<x-ui.buttons.button-basic mode="primary" type="button" wire:click="create" aria-label="Eingang erfassen"><i class="far fa-plus" aria-hidden="true"></i><span>Eingang</span></x-ui.buttons.button-basic>@endif
        </div>
    </header>
    <section class="rt-disposition-panel">
        <div class="rt-unified-inbox__filters">
            <div><h2>Posteingang <span class="rt-disposition-count">{{ $rows->total() }}</span></h2><p class="ops-muted">Eingänge und Anfragen · wichtigste nächste Schritte zuerst</p>@if(isset($sections['ai-intake']) && \App\Support\Operations\AiIntakeSchema::ready())<span class="rt-table-meta"><i class="far fa-sparkles" aria-hidden="true"></i> {{ \App\Support\Operations\AiDispositionSettings::enabled() ? 'AI-Verarbeitung aktiv' : 'AI-Verarbeitung pausiert' }}</span>@endif</div>
            <div class="ops-actions">
                <label class="sr-only" for="unified-channel-{{ $this->getId() }}">Quelle oder Verarbeitung</label>
                <x-ui.forms.select id="unified-channel-{{ $this->getId() }}" class="rt-unified-inbox__select" wire:model.live="channel" aria-label="Quelle oder Verarbeitung">
                    <option value="all" data-icon-class="far fa-layer-group">Alle Quellen</option>
                    <option value="email" data-icon-class="far fa-envelope">E-Mail</option>
                    <option value="portal" data-icon-class="far fa-globe">Portal</option>
                    <option value="phone" data-icon-class="far fa-phone">Telefon</option>
                    <option value="manual" data-icon-class="far fa-pen-to-square">Manuell</option>
                    <option value="ai" data-icon-class="far fa-sparkles">AI-verarbeitet</option>
                    <option value="import" data-icon-class="far fa-file-import">Import</option>
                </x-ui.forms.select>
                @if(isset($sections['ai-intake']) || isset($sections['imports']))
                    <details class="rt-unified-inbox__more" wire:ignore.self x-on:click="if ($event.target.closest('button')) $el.open = false" x-on:click.outside="$el.open = false" x-on:keydown.escape.stop="$el.open = false"><summary aria-label="Weitere Eingangsaktionen"><i class="far fa-ellipsis" aria-hidden="true"></i></summary><div>
                        @if(isset($sections['ai-intake']))<button type="button" wire:click="create('ai')">Text / Datei mit AI erfassen</button>@endif
                        @if(isset($sections['imports']))<button type="button" wire:click="create('import')">CSV / Text prüfen</button>@endif
                    </div></details>
                @endif
            </div>
        </div>
        <div wire:loading.class="opacity-60" wire:target="setStatus,channel,gotoPage,nextPage,previousPage">
            <x-tables.table :columns="[['label'=>'Vorgang / Kunde','key'=>'title','width'=>'minmax(0,2fr)'],['label'=>'Quelle','key'=>'channel','width'=>'minmax(0,.8fr)'],['label'=>'Bearbeitung','key'=>'status','width'=>'minmax(0,1fr)'],['label'=>'Nächster Schritt','key'=>'next','width'=>'minmax(0,1.3fr)']]" :items="$rows" table-key="unified-inbox" selection-action="openRow" detail-action="openRow" row-view="components.tables.rows.operations.unified-inbox-row" :flush-top="true" :empty="$status !== 'all' || $channel !== 'all' ? 'Keine Eingänge für diese Auswahl. Wähle einen anderen Filter.' : 'Keine Eingänge vorhanden. Neue Anfragen und offene Prüfungen erscheinen hier.'" />
        </div>
        <div class="rt-disposition-pagination">{{ $rows->links() }}</div>
    </section>
    @if($mailboxAvailable)<livewire:operations.disposition-mailbox :key="'disposition-mailbox-'.$this->getId()" />@endif
    @if($detail)
        @if($detail['kind'] === 'inquiry')
            <livewire:operations.inquiry-inbox :initial-inquiry-id="$detail['id']" :customer-id="$detail['customer']" :modal-only="true" :key="'unified-detail-'.$detailRevision" />
        @elseif($detail['kind'] === 'ai')
            <livewire:operations.ai-intake-inbox :initial-intake-id="$detail['id']" :customer-id="$detail['customer']" :modal-only="true" :key="'unified-detail-'.$detailRevision" />
        @elseif($detail['kind'] === 'import')
            <livewire:operations.operations-enhancements tab="imports" :initial-record-id="$detail['id']" :embedded="true" :modal-only="true" :key="'unified-detail-'.$detailRevision" />
        @else
            <livewire:operations.customer-portal-management tab="requests" :customer-id="$detail['customer']" :initial-record-id="$detail['id']" :initial-source="$detail['kind']" :embedded="true" :modal-only="true" :key="'unified-detail-'.$detailRevision" />
        @endif
    @endif
</section>
