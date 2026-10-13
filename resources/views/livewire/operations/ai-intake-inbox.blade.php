<section @class(['ops-stack min-w-0', 'ai-intake-panel' => $embedded]) data-ai-intake-inbox data-embedded="{{ $embedded ? 'true' : 'false' }}" data-detail-tab="{{ $detailTab }}" data-mode="{{ $captureOpen ? 'capture' : ($detailOpen && $selectedId ? 'detail' : 'list') }}" wire:poll.visible.10s
    x-data="{ panelMode: null }" @if($embedded) x-effect="const next = $wire.captureOpen ? 'capture' : ($wire.detailOpen && $wire.selectedId ? 'detail-' + $wire.selectedId : 'list'); if (next !== panelMode) { const previous = panelMode; panelMode = next; $nextTick(() => { if (next !== 'list') $refs.intakePanelHeading?.focus(); else if (previous?.startsWith('detail-')) $refs.intakeList?.querySelector('[data-intake-id=&quot;' + previous.slice(7) + '&quot;]')?.focus(); }); }" @endif>
    @if($embedded)
        <x-operations.feedback :show-offline="false" />
        <div class="ai-intake-panel__content" wire:loading.attr="inert" wire:target="submit,assignCustomer,reanalyze,pause,createInquiries,saveProposal,approveProposal,retryPreparation,mapProposal,prepareCustomer">
            @if(!$ready)
                <p class="ops-muted" role="status">Die AI-Annahme ist noch nicht eingerichtet.</p>
            @elseif($captureOpen)
                <div class="ai-intake-panel__capture">
                    <header class="ai-intake-panel__toolbar"><button type="button" class="ai-intake-panel__back" wire:click="closeCapture" aria-label="Zurück zu den Eingängen"><i class="far fa-arrow-left" aria-hidden="true"></i></button><h2 class="font-semibold" x-ref="intakePanelHeading" tabindex="-1">Eingang erfassen</h2></header>
                    @include('livewire.operations.partials.ai-intake-composer', ['composerMode' => 'capture', 'maxCharacters' => 20000, 'maxAttachmentCount' => $limits['max_attachment_count'], 'maxAudioKilobytes' => $limits['max_audio_kilobytes']])
                </div>
            @elseif($detailOpen && $selected)
                <div class="ai-intake-panel__detail">
                    @php($headerLabel = $labels[$selected->status] ?? 'Prüfung nötig')
                    @php($headerIcon = ['received'=>'fa-inbox','analyzing'=>'fa-sync-alt','review'=>'fa-exclamation-circle','waiting_customer'=>'fa-comment-alt','ready'=>'fa-check-circle','paused'=>'fa-pause-circle','not_inquiry'=>'fa-ban','failed'=>'fa-exclamation-triangle','completed'=>'fa-check-double'][$selected->status] ?? 'fa-inbox')
                    @php($headerId = 'AI-'.str_pad((string) $selected->id, 6, '0', STR_PAD_LEFT))
                    <header class="ai-intake-panel__toolbar ai-intake-panel__record-header" data-ai-intake-record-header wire:key="intake-record-header-{{ $this->getId() }}-{{ $selected->id }}">
                        <button type="button" class="ai-intake-panel__back" wire:click="closeDetail" aria-label="Zurück zu den Eingängen" title="Zurück zu den Eingängen"><i class="far fa-arrow-left" aria-hidden="true"></i></button>
                        <span class="ai-intake-panel__record-status" data-state="{{ $selected->status }}" role="img" aria-label="Bearbeitung: {{ $headerLabel }}" title="{{ $headerLabel }}"><i class="far {{ $headerIcon }}" aria-hidden="true"></i></span>
                        <span class="ai-intake-panel__record-id" aria-label="{{ $headerId }}">#{{ str_pad((string) $selected->id, 6, '0', STR_PAD_LEFT) }}</span>
                        <h2 class="ai-intake-panel__record-title" x-ref="intakePanelHeading" tabindex="-1" title="{{ $selected->title ?: 'Eingang prüfen' }}">{{ $selected->title ?: 'Eingang prüfen' }}</h2>
                        <x-ui.dropdown.anchor-dropdown align="right" width="80" :open-on-hover="true" :hover-open-delay="220" :hover-close-delay="180" :max-height="360"
                            :dropdown-id="'intake-header-'.$this->getId().'-'.$selected->id" layer-group="ai-intake-header" content-role="dialog" content-label="Eingangsdetails"
                            class="ai-intake-panel__record-info" dropdown-classes="ai-intake-panel__record-popover" content-classes="ai-intake-panel__record-details bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                            <x-slot:trigger>
                                <button type="button" class="ai-intake-panel__record-info-button" aria-label="Titel, Zusammenfassung und Bearbeitungsstand anzeigen" aria-haspopup="dialog" aria-controls="rt-dropdown-intake-header-{{ $this->getId() }}-{{ $selected->id }}-content"
                                    @focus="if ($el.matches(':focus-visible') && !$event.relatedTarget?.closest('[data-rt-dropdown-owner]')) openDropdown(false)"><i class="far fa-info-circle" aria-hidden="true"></i></button>
                            </x-slot:trigger>
                            <x-slot:content>
                                <div data-rt-dropdown-keep-open>
                                    <div class="ai-intake-panel__record-details-head"><strong>{{ $selected->title ?: 'Eingang prüfen' }}</strong><button type="button" class="ai-intake-panel__record-info-button" aria-label="Eingangsdetails schließen" data-dropdown-initial-focus @click.stop="close(true)"><i class="far fa-times" aria-hidden="true"></i></button></div>
                                    <p class="ai-intake-panel__record-details-meta">{{ $headerId }} · {{ $headerLabel }}</p>
                                    @if(trim((string) $selected->summary) !== '')<p class="ai-intake-panel__record-summary">{{ $selected->summary }}</p>@endif
                                </div>
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>
                    </header>
                    @include('livewire.operations.partials.ai-intake-detail')
                </div>
            @else
                <header class="ai-intake-panel__toolbar"><span class="ops-muted">{{ $intakes->total() }} {{ $intakes->total() === 1 ? 'Eingang' : 'Eingänge' }}</span><x-ui.buttons.button-basic type="button" wire:click="openCapture" aria-label="Eingang erfassen" title="Eingang erfassen"><i class="far fa-plus" aria-hidden="true"></i></x-ui.buttons.button-basic></header>
                @include('livewire.operations.partials.ai-intake-list')
            @endif
        </div>
        @if($ready)
            <div class="ai-intake-panel__composer" wire:key="intake-composer-host-{{ $this->getId() }}" @if($detailOpen && $selected) id="intake-{{ $this->getId() }}-panel-assistant" @if($detailTab === 'assistant') role="tabpanel" aria-labelledby="intake-{{ $this->getId() }}-tab-assistant" @else role="region" aria-label="Fragen zu diesem Eingang" @endif @endif @if($captureOpen) hidden @endif>
                <livewire:operations.ai-intake-assistant :initial-intake-id="$detailOpen ? $selectedId : null" :customer-id="$customerId" :overview="true" :host-component-id="$this->getId()" :key="'inbox-assistant-'.$this->getId()" />
            </div>
        @endif
        <p class="ops-muted" wire:loading wire:target="submit,assignCustomer,reanalyze,pause,createInquiries,saveProposal,approveProposal,retryPreparation,mapProposal" role="status" aria-live="polite">Der Eingang wird bearbeitet …</p>
        <p class="ops-errors" wire:offline role="status">Offline · Änderungen können gerade nicht gespeichert werden.</p>
    @else
        @unless($modalOnly)
            <div class="ops-toolbar"><div><h2 class="font-semibold">AI-Annahme</h2><p class="ops-muted">Eingänge, Rückfragen und Planungsvorschläge gemeinsam prüfen.</p></div>@if($ready)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="openCapture"><i class="far fa-plus" aria-hidden="true"></i>Eingang erfassen</x-ui.buttons.button-basic>@endif</div>
            <x-operations.feedback />
            @if(!$ready)
                <p class="ops-muted">Die AI-Annahme ist noch nicht eingerichtet. Der Superadmin richtet die Datenbankerweiterung und das Dispositionspostfach ein.</p>
            @else
                @if(!$enabled)<p class="ops-muted" role="status">Die automatische Verarbeitung ist deaktiviert. Eingänge bleiben zur späteren Auswertung erhalten.</p>@endif
                <x-tables.toolbar title="AI-Eingänge filtern" id="ai-intake-filters" :search-in-header="true">
                    <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="AI-Eingang suchen" /></x-slot:search>
                    <x-tables.filter-field label="Bearbeitung" for="ai-intake-status"><x-ui.forms.select id="ai-intake-status" wire:model.live="statusFilter"><option value="all">Alle Zustände</option>@foreach($labels as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
                </x-tables.toolbar>
                <x-tables.table :columns="[['label'=>'Eingang','key'=>'title','width'=>'minmax(0,1.7fr)'],['label'=>'Kunde','key'=>'customer','width'=>'minmax(0,1fr)'],['label'=>'Bearbeitung','key'=>'status','width'=>'minmax(0,.8fr)'],['label'=>'Erfasst','key'=>'created','width'=>'minmax(0,.8fr)']]" :items="$intakes" table-key="ai-intake" detail-action="select" row-view="components.tables.rows.operations.ai-intake-row" empty="Noch keine AI-Eingänge für diese Auswahl." :flush-top="true" />
                {{ $intakes->links() }}
            @endif
        @endunless
        <x-operations.modal wire:model.live="captureOpen" title="Eingang erfassen" max-width="3xl">
            <x-operations.feedback :show-offline="false" />
            @include('livewire.operations.partials.ai-intake-composer', ['composerMode' => 'capture', 'maxCharacters' => 20000, 'maxAttachmentCount' => $limits['max_attachment_count'], 'maxAudioKilobytes' => $limits['max_audio_kilobytes']])
        </x-operations.modal>
        <x-operations.modal wire:model.live="detailOpen" title="AI-Eingang prüfen" max-width="4xl" variant="drawer">
            @include('livewire.operations.partials.ai-intake-detail')
        </x-operations.modal>
    @endif
    @if($customerDraftOpen && $selectedId)<livewire:admin.operations.customers :embedded="true" :modal-only="true" :start-creating="true" :workspace-revision="$selectedRevision" :draft-intake-id="$selectedId" :key="'ai-customer-draft-'.$selectedId.'-'.$selectedRevision" />@endif
</section>
