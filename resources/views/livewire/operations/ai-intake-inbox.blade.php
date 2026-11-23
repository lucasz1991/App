<section @class(['ops-stack min-w-0', 'ai-intake-panel' => $embedded]) data-ai-intake-inbox data-embedded="{{ $embedded ? 'true' : 'false' }}" data-detail-tab="{{ $detailTab }}" data-mode="{{ $captureOpen ? 'capture' : ($detailOpen && $selectedId ? 'detail' : 'list') }}" wire:poll.visible.10s
    x-data="{ panelMode: null }" @if($embedded) x-effect="const next = $wire.captureOpen ? 'capture' : ($wire.detailOpen && $wire.selectedId ? 'detail-' + $wire.selectedId : 'list'); if (next !== panelMode) { const previous = panelMode; panelMode = next; $nextTick(() => { if (next !== 'list') $refs.intakePanelHeading?.focus(); else if (previous?.startsWith('detail-')) $refs.intakeList?.querySelector('[data-intake-id=&quot;' + previous.slice(7) + '&quot;]')?.focus(); }); }" @endif>
    @if($embedded)
        <x-operations.feedback :show-offline="false" />
        <div class="ai-intake-panel__content" wire:loading.attr="inert" wire:target="submit,assignCustomer,reanalyze,pause,createInquiries,confirmReview,saveProposal,approveProposal,retryPreparation,mapProposal,prepareCustomer,continueInquiry,openPlanning,preparePlanning,publishPlanning">
            @if(!$ready)
                <p class="ops-muted" role="status">Die AI-Annahme ist noch nicht eingerichtet.</p>
            @elseif($captureOpen)
                <div class="ai-intake-panel__capture">
                    <header class="ai-intake-panel__toolbar"><button type="button" class="ai-intake-panel__back" wire:click="closeCapture" aria-label="Zurück zu den Eingängen"><i class="far fa-arrow-left" aria-hidden="true"></i></button><h2 class="font-semibold" x-ref="intakePanelHeading" tabindex="-1">Eingang erfassen</h2></header>
                    @include('livewire.operations.partials.ai-intake-composer', ['composerMode' => 'capture', 'maxCharacters' => 20000, 'maxAttachmentCount' => $limits['max_attachment_count'], 'maxAudioKilobytes' => $limits['max_audio_kilobytes']])
                </div>
            @elseif($detailOpen && $selected)
                <div class="ai-intake-panel__detail">
                    <p class="ai-intake-panel__section-title">AI-Annahme</p>
                    <x-operations.intake.record-navigation :record="$selected" :labels="$labels" :component-id="$this->getId()" :active-tab="$detailTab" />
                    @include('livewire.operations.partials.ai-intake-detail')
                </div>
            @else
                @include('livewire.operations.partials.ai-intake-list')
            @endif
        </div>
        @if($ready)
            <div class="ai-intake-panel__composer" wire:key="intake-composer-host-{{ $this->getId() }}" @if($detailOpen && $selected) id="intake-{{ $this->getId() }}-panel-assistant" @if($detailTab === 'assistant') role="tabpanel" aria-labelledby="intake-{{ $this->getId() }}-tab-assistant" @else role="region" aria-label="Fragen zu diesem Eingang" @endif @endif @if($captureOpen) hidden @endif>
                <livewire:operations.ai-intake-assistant :initial-intake-id="$detailOpen ? $selectedId : null" :customer-id="$customerId" :overview="true" :host-component-id="$this->getId()" :key="'inbox-assistant-'.$this->getId()" />
            </div>
        @endif
        <p class="ops-muted" wire:loading wire:target="submit,assignCustomer,reanalyze,pause,createInquiries,confirmReview,saveProposal,approveProposal,retryPreparation,mapProposal" role="status" aria-live="polite">Der Eingang wird bearbeitet …</p>
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
        <x-operations.modal wire:model.live="detailOpen" title="Eingang bearbeiten" max-width="4xl" variant="drawer">
            @if($selected)
                @php
                    $currentProposals = $selected->proposals->where('source_revision', $selected->source_revision)->where('status', '!=', 'stale');
                    $customerMailSuppressed = $selected->processing_mode === 'manual' || $selected->proposals->contains(fn ($record) => ($record->payload['review_confirmation']['customer_communication'] ?? null) === 'off');
                @endphp
                <div class="rt-intake-drawer" data-intake-drawer data-active-tab="{{ $detailTab }}"
                    x-data="{ editorFocusKey: null }"
                    x-effect="const key = $wire.manualEditorOpen ? 'new-' + $wire.selectedId : ($wire.editingProposalId !== null ? 'position-' + $wire.editingProposalId : ($wire.customerEditorOpen ? 'customer-' + $wire.selectedId : ($wire.planningProposalId !== null ? 'planning-' + $wire.planningProposalId : null))); const changed = key !== editorFocusKey; editorFocusKey = key; if (changed &amp;&amp; key &amp;&amp; $wire.detailTab === 'review') $nextTick(() => { const region = key.startsWith('customer-') ? $el.querySelector('.rt-intake-drawer__customer-form') : (key.startsWith('planning-') ? $el.querySelector('[data-ai-intake-planning-editor]') : $el.querySelector('[data-intake-position-form]')); const target = region?.querySelector('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[role=combobox]:not([disabled]),button:not([disabled])'); target?.focus({ preventScroll: true }); target?.scrollIntoView({ block: 'nearest', behavior: 'auto' }); });">
                    <div class="rt-intake-drawer__navigation">
                        <header class="rt-intake-drawer__context">
                            <p class="rt-intake-drawer__meta">AI-{{ str_pad((string) $selected->id, 6, '0', STR_PAD_LEFT) }} · {{ ['email' => 'E-Mail', 'manual' => 'Formular', 'text' => 'Text', 'file' => 'Datei', 'audio' => 'Sprachaufnahme'][$selected->source_type] ?? 'Eingang' }}@if($selected->created_at) · {{ $selected->created_at->timezone('Europe/Berlin')->format('d.m.Y, H:i') }}@endif</p>
                            <h2 class="rt-intake-drawer__title" tabindex="-1" data-intake-drawer-heading>{{ $selected->title ?: 'Eingang prüfen' }}</h2>
                            <section class="rt-intake-drawer__customer" data-intake-customer-assignment aria-label="Kunde">
                                <span class="rt-intake-drawer__customer-avatar" aria-hidden="true"><i class="far fa-building"></i></span>
                                <div class="rt-intake-drawer__customer-identity"><span>Kunde</span><strong>{{ $selected->customer?->company_name ?: 'Noch nicht zugeordnet' }}</strong></div>
                            </section>
                        </header>
                        <x-operations.panel.tabs class="rt-intake-drawer__tabs" label="Eingang bearbeiten" :id-prefix="'intake-drawer-'.$this->getId()" action="setDetailTab" model="detailTab" :active="$detailTab" :tabs="['review' => ['label' => 'Bearbeiten', 'icon' => 'far fa-pen'], 'sources' => ['label' => 'Original & Anlagen', 'icon' => 'far fa-paperclip'], 'communication' => ['label' => 'Verlauf', 'icon' => 'far fa-history']]" />
                    </div>

                    @if($drawerState['stale'])<div class="rt-intake-drawer__notice" role="status">Dieser Eingang oder seine Einstellungen wurden inzwischen geändert. Vor einer Änderung den aktuellen Stand laden.</div>@endif

                    <section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-review" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-review" @if($detailTab !== 'review') hidden @endif>
                        @if($selected->status === 'not_inquiry')
                            <p class="ops-muted" role="status">Als „Keine Anfrage“ abgeschlossen. Zum erneuten Prüfen die Markierung im Postfach zurücksetzen.</p>
                        @else
                            @if($customerEditorOpen || !$selected->customer)
                                <section class="rt-intake-drawer__customer-editor" aria-label="Kundenzuordnung bearbeiten">
    <form wire:submit="assignCustomer" class="ops-form rt-intake-drawer__customer-form" id="intake-drawer-customer-{{ $this->getId() }}">
        <x-operations.field :id="'drawer-customer-'.$this->getId()" label="Kunde" model="assignCustomerId" type="select" :live="true" :wide="true"><option value="">Bitte prüfen und zuordnen</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-operations.field>
        <x-operations.field :id="'drawer-contact-'.$this->getId()" label="Kontakt" model="assignContactId" type="select" :wide="true"><option value="">Hauptkontakt des Kunden</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}{{ $contact->email ? ' · '.$contact->email : '' }}</option>@endforeach</x-operations.field>
        @if($customerSuggestions)<div class="ops-full"><p class="ops-muted">Mögliche Kunden anhand von Absender, CC, Inhalt und Signatur. Die Zuordnung bitte bestätigen.</p><ul>@foreach($customerSuggestions as $suggestion)@if($suggestedCustomer = $customers->firstWhere('id', $suggestion['customer_id']))<li>{{ $suggestedCustomer->company_name }} · {{ implode(', ', array_map(fn ($reason) => \App\Services\Operations\AiIntakeCustomerContextService::REASON_LABELS[$reason] ?? 'Zuordnung prüfen', $suggestion['reasons'])) }}</li>@endif @endforeach</ul></div>@endif
        @if(!$selected->customer_id && $canApprove && !$customerId)<div class="ops-full">@if($selected->analysis['customer_draft'] ?? null)<p class="ops-muted">Vorbereiteter Kunde: {{ $selected->analysis['customer_draft']['company_name'] ?? 'Firmenname prüfen' }}{{ !empty($selected->analysis['customer_draft']['contact_name']) ? ' · '.$selected->analysis['customer_draft']['contact_name'] : '' }}</p>@endif</div>@endif
    </form>
                                </section>
                            @endif
                            @if($manualEditorOpen)
                                <section class="rt-intake-drawer__position rt-intake-drawer__form" aria-label="Neue Position erfassen" data-intake-manual-editor>
                                    <h3>Position erfassen</h3>
                                    @include('livewire.operations.partials.ai-intake-position-form', ['payload' => []])
                                </section>
                            @endif

                            @if(!$manualEditorOpen && !$customerEditorOpen)
                            @if($currentProposals->count() > 1)
                                <div class="rt-intake-drawer__position-picker">
                                    <x-ui.forms.label :for="'intake-drawer-position-'.$this->getId()" value="Leistung" />
                                    <x-ui.forms.select :id="'intake-drawer-position-'.$this->getId()" wire:change="selectDrawerPosition($event.target.value)" :disabled="$editingProposalId !== null || $planningProposalId !== null ? true : null">
                                        @foreach($currentProposals as $option)<option value="{{ $option->id }}" @selected($drawerState['activeProposalId'] === $option->id)>{{ $option->position_index + 1 }} · {{ $option->payload['demand']['title'] ?? 'Leistung prüfen' }}</option>@endforeach
                                    </x-ui.forms.select>
                                </div>
                            @endif
                            <section class="rt-intake-drawer__positions" aria-label="Positionen und Planungsvorschläge">
                                @foreach($currentProposals as $proposal)
                                    @continue($drawerState['activeProposalId'] !== $proposal->id)
                                    @php
                                        $payload = $proposal->payload ?? [];
                                        $currentProposal = $proposal->source_revision === $selected->source_revision && $selected->revision === $selectedRevision;
                                        $canCorrect = $currentProposal && !in_array($proposal->status, ['applied', 'stale'], true) && (!$proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true));
                                        $positionConfirmed = !empty($payload['review_confirmation']['actor_id']) && ($payload['review_confirmation']['source_revision'] ?? null) === $selected->source_revision;
                                        $directPath = ($payload['business_path'] ?? 'inquiry') === 'direct_order';
                                    @endphp
                                    <article class="rt-intake-drawer__position" wire:key="drawer-position-{{ $proposal->id }}-{{ $proposal->revision }}" data-intake-drawer-position="{{ $proposal->id }}">
                                        <div class="rt-intake-drawer__position-header"><h3>Leistung {{ $proposal->position_index + 1 }}</h3></div>
                                        <h4>{{ $payload['demand']['title'] ?? 'Leistung prüfen' }}</h4>

                                        @if($editingProposalId === $proposal->id && $canCorrect)
                                            <div class="rt-intake-drawer__form">@include('livewire.operations.partials.ai-intake-position-form')</div>
                                        @else
                                            <x-operations.intake.visuals :demand="$payload['demand'] ?? []" :order="$proposal->inquiry?->order" :identity="'proposal-'.$proposal->id" />
                                            <x-operations.intake.facts class="rt-intake-drawer__facts" :demand="$payload['demand'] ?? []" variant="drawer" :assumptions="$payload['assumptions'] ?? []" :confirmed="$positionConfirmed" />
                                        @endif

                                        <details class="rt-intake-drawer__disclosure"><summary>Qualifikationen & Schichtabschnitte</summary><div class="rt-intake-drawer__disclosure-content">
                                            @if($payload['raw_requirements'] ?? [])<p><strong>Erkannte Anforderungen:</strong> {{ implode(', ', $payload['raw_requirements']) }}</p>@endif
                                            @if($payload['unmapped_requirements'] ?? [])<p class="ops-muted" role="status">Noch zu prüfen: {{ implode(', ', $payload['unmapped_requirements']) }}. Passende Qualifikationen auswählen und die Prüfung bestätigen.</p>@endif
                                            @if($payload['demand']['qualification_ids'] ?? [])<p><strong>Qualifikationen:</strong> {{ $qualifications->whereIn('id', $payload['demand']['qualification_ids'])->pluck('name')->implode(', ') }}</p>@else<p class="ops-muted">Keine Qualifikationen zugeordnet. Anforderungen im Original prüfen.</p>@endif
                                            @if($payload['segments'] ?? [])
                                                <p class="ops-muted">{{ count($payload['segments']) }} ausdrücklich erfasste Schichtabschnitte.</p>
                                                <ul>
                                                    @foreach($payload['segments'] as $segment)
                                                        <li>{{ $segment['starts_at'] ?? 'Beginn offen' }} bis {{ $segment['ends_at'] ?? 'Ende offen' }} · {{ $segment['timezone'] ?? 'Zeitzone offen' }}
                                                            @if(isset($segment['planned_break_minutes']))
                                                                · {{ $segment['planned_break_minutes'] }} Min. Pause
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <p class="ops-muted">Noch keine einzelnen Schichtabschnitte hinterlegt.</p>
                                            @endif
                                        </div></details>

                                        <details class="rt-intake-drawer__disclosure"><summary>Quellen und Prüfhinweise</summary><div class="rt-intake-drawer__disclosure-content">
                                            @if(($payload['assumptions']['required_staff']['basis'] ?? '') === 'default')<p class="ops-muted" role="status">Die Personalanzahl ist im Original nicht genannt. Standardvorschlag: {{ $payload['demand']['required_staff'] ?? 'offen' }} Person(en) · {{ $positionConfirmed ? 'Persönlich bestätigt.' : 'Bitte bestätigen oder anpassen.' }}</p>@endif
                                            @if(!empty($payload['assumptions']['history_review']))<p class="ops-muted">Historische Vergleichsdaten verwendet. Diese sind keine Belege für die aktuelle Quelle.</p>@endif
                                    @if($selected->summary)<p>{{ $selected->summary }}</p>@endif
                                    @if($selected->missing_fields)<ul>@foreach($selected->missing_fields as $field)@php($fieldKey = is_array($field) ? ($field['field'] ?? '') : $field)<li>{{ is_array($field) && isset($field['question']) ? $field['question'] : ($fieldLabels[$fieldKey] ?? 'Angabe prüfen') }}</li>@endforeach</ul>@endif
                                    @if($selected->error_code)<p class="ops-muted" role="status">Die Verarbeitung benötigt eine Prüfung. Quellen und erkannte Angaben bleiben erhalten.</p>@endif
                                    @if($selected->proposals->contains(fn ($record) => $record->source_revision !== $selected->source_revision || $record->status === 'stale'))<p class="ops-muted">Ein neuerer Eingang liegt vor. Frühere Angaben erst nach Prüfung der aktuellen Quelle übernehmen.</p>@endif
                                    @if(!$enabled && $selected->source_type !== 'email')<p class="ops-muted">Die automatische Auswertung ist aus. Eingang und Anlagen bleiben erhalten.</p>@endif
                                    @include('livewire.operations.partials.ai-intake-context-hints', ['showCandidateHints' => false])
                                            @if($selected->contact)<p><strong>Kontakt:</strong> {{ $selected->contact->name }}</p>@endif
                                            <p class="ops-muted">{{ $payload['demand']['timezone'] ?? 'Europe/Berlin' }} · {{ ($payload['provenance']['kind'] ?? '') === 'manual' ? 'Im Formular erfasst' : 'Aus dem Eingang übernommen' }} · {{ $directPath ? 'Direktauftrag' : 'Anfrage' }}</p>
                                            <dl>@forelse(($payload['evidence'] ?? []) as $field => $evidence)<div><dt>{{ is_array($evidence) ? ($evidence['field'] ?? $field) : $field }}</dt><dd>{{ is_array($evidence) ? ($evidence['quote'] ?? 'Quelle im Originaleingang prüfen.') : $evidence }}@if(is_array($evidence) && ($evidence['message_id'] ?? null))<span class="rt-intake-drawer__meta"> · Nachricht {{ $evidence['message_id'] }}</span>@endif</dd></div>@empty<p class="ops-muted">Originalnachrichten und Anlagen stehen unter „Original & Anlagen“ zur Prüfung bereit.</p>@endforelse</dl>
                                        </div></details>

                                        @if(!$currentProposal)<p class="rt-intake-drawer__notice" role="status">Ein neuerer Eingang liegt vor. Den aktuellen Vorschlag prüfen.</p>
                                        @elseif($proposal->error_code === 'reply_position_review')<p class="ops-muted">Diese Antwort muss zuerst einer vorhandenen Position zugeordnet werden.</p>
                                        @elseif($proposal->error_code === 'native_workflow_active')<p class="ops-muted">Diesen Vorgang in der bestehenden Anfrage prüfen; Angebot oder Beauftragung sind bereits angelaufen.</p>@endif
                                        @if($currentProposal && $proposal->error_code === 'reply_position_review')
                                            <form wire:submit="mapProposal({{ $proposal->id }},{{ $proposal->revision }})" class="ops-stack rt-intake-drawer__mapping"><x-operations.field :id="'drawer-map-'.$this->getId().'-'.$proposal->id" label="Vorhandene Anfrage für diese Antwort" :model="'mappingInquiryIds.'.$proposal->id" type="select"><option value="">Position ausdrücklich zuordnen</option>@foreach(($payload['candidate_inquiry_ids'] ?? []) as $candidateId)@if($candidate = $mappingInquiries->get($candidateId))<option value="{{ $candidate->id }}">{{ $candidate->number }} · {{ $candidate->title }} · {{ $candidate->location_name }}</option>@endif @endforeach</x-operations.field></form>
                                        @endif
                                        @if($proposal->inquiry && $planningProposalId === $proposal->id)@include('livewire.operations.partials.ai-intake-planning')@endif

                                    </article>
                                @endforeach
                                @if($currentProposals->isEmpty() && !$manualEditorOpen)
                                    @if($selected->processed_source_revision === $selected->source_revision && is_array($selected->analysis['positions'] ?? null) && !empty($selected->analysis['positions']))
                                        @foreach($selected->analysis['positions'] as $index => $position)
                                            @continue(!is_array($position))
                                            <article class="rt-intake-drawer__position" data-ai-intake-analysis-preview wire:key="drawer-analysis-{{ $selected->id }}-{{ $selected->source_revision }}-{{ $index }}">
                                                <div class="rt-intake-drawer__position-header"><h3>Position {{ $index + 1 }}</h3><span class="rt-intake-drawer__meta">Erkannte Angaben</span></div>
                                                <h4>{{ $position['title'] ?? 'Leistung prüfen' }}</h4>
                                                <x-operations.intake.visuals :demand="$position" :identity="'analysis-'.$selected->id.'-'.$index" />
                                                <x-operations.intake.facts class="rt-intake-drawer__facts" :demand="$position" variant="drawer" :assumptions="$position['assumptions'] ?? []" />
                                            </article>
                                        @endforeach
                                    @else
                                        <div class="rt-intake-drawer__empty"><h3>Noch keine Positionen</h3><p class="ops-muted">Angaben direkt per Formular erfassen. AI-Assist kann bei Bedarf unterstützen.</p></div>
                                    @endif
                                @endif
                            </section>




                            @if($currentProposals->isEmpty())
                                <details class="rt-intake-drawer__disclosure"><summary>Quellen und Prüfhinweise</summary><div class="rt-intake-drawer__disclosure-content">
                                    @if($selected->summary)<p>{{ $selected->summary }}</p>@endif
                                    @if($selected->missing_fields)<ul>@foreach($selected->missing_fields as $field)@php($fieldKey = is_array($field) ? ($field['field'] ?? '') : $field)<li>{{ is_array($field) && isset($field['question']) ? $field['question'] : ($fieldLabels[$fieldKey] ?? 'Angabe prüfen') }}</li>@endforeach</ul>@endif
                                    @if($selected->error_code)<p class="ops-muted" role="status">Die Verarbeitung benötigt eine Prüfung. Quellen und erkannte Angaben bleiben erhalten.</p>@endif
                                    @include('livewire.operations.partials.ai-intake-context-hints', ['showCandidateHints' => false])
                                </div></details>
                            @endif
                            @php($historicalReferences = $selected->proposals->filter(fn ($proposal) => ($proposal->source_revision !== $selected->source_revision || $proposal->status === 'stale') && $proposal->inquiry)->unique('inquiry_id'))
                            @if($historicalReferences->isNotEmpty())<details class="rt-intake-drawer__disclosure"><summary>Frühere Anfragen</summary><div class="rt-intake-drawer__disclosure-content"><p class="ops-muted">Verknüpfungen aus früheren Prüfständen.</p>@foreach($historicalReferences as $reference)<x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases',['view'=>'inbox','inquiry'=>$reference->inquiry_id])" data-no-navigate>Native Anfrage {{ $reference->inquiry->number }} öffnen</x-ui.buttons.button-basic>@endforeach</div></details>@endif
                            @endif
                        @endif
                    </section>

                    <section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-sources" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-sources" @if($detailTab !== 'sources') hidden @endif>
                        <h3>Nachrichten und Anlagen</h3>
                        @forelse($selected->messages as $message)
                            <details class="rt-intake-drawer__disclosure" @if($loop->first) open @endif wire:key="drawer-message-{{ $message->id }}">
                                <summary>{{ $message->metadata['subject'] ?? 'Originaleingang' }} <span class="rt-intake-drawer__meta">{{ $message->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span></summary>
                                <div class="rt-intake-drawer__disclosure-content">
                                    <p class="rt-intake-drawer__original">{{ $message->body }}</p>
                                    @if(($message->metadata['raw_archive_complete'] ?? true) === false || ($message->metadata['text_archive_complete'] ?? true) === false)<p class="ops-muted" role="alert">Dieser Eingang überschreitet die Verarbeitungsgrenzen. Das vollständige Original im Postfach manuell prüfen; hier ist ein begrenzter Ausschnitt archiviert.</p>@endif
                                    @if($message->raw_path)<x-ui.buttons.button-basic mode="link" :href="route('operations.ai-intake.original', $message->id)" data-no-navigate>{{ ($message->metadata['raw_archive_complete'] ?? true) ? 'Original herunterladen' : 'Archivierten Ausschnitt herunterladen' }}</x-ui.buttons.button-basic>@endif
                                </div>
                            </details>
                        @empty<p class="ops-muted">Keine Nachrichten hinterlegt.</p>@endforelse
                        @foreach($selected->attachments as $attachment)<div class="rt-intake-drawer__attachment" wire:key="drawer-attachment-{{ $attachment->id }}"><x-ui.buttons.button-basic mode="link" :href="route('operations.ai-intake.attachment', $attachment->id)" data-no-navigate><i class="far fa-paperclip" aria-hidden="true"></i>{{ $attachment->file_name }}</x-ui.buttons.button-basic><span class="rt-intake-drawer__meta">{{ number_format($attachment->file_size / 1024, 0, ',', '.') }} KB</span></div>@endforeach
                    </section>

                    <section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-communication" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-communication" @if($detailTab !== 'communication') hidden @endif>
                        <h3>Kundennachrichten und Verarbeitung</h3><p class="ops-muted">{{ $selected->question_round }} Rückfragerunden.</p>
                        @if($customerMailSuppressed)<p class="rt-intake-drawer__notice" role="status">Kundenkommunikation ist für diesen manuell übernommenen Vorgang ausgeschaltet. Vorhandene Nachrichten bleiben im Verlauf erhalten.</p>@endif
                        @forelse($selected->deliveries as $delivery)
                            <details class="rt-intake-drawer__disclosure" wire:key="drawer-delivery-{{ $delivery->id }}"><summary>{{ \App\Models\AiIntakeDelivery::TYPE_LABELS[$delivery->message_type ?? 'clarification'] ?? 'Kundennachricht' }} <span class="rt-intake-drawer__meta">{{ ($delivery->metadata['manual_mailbox_test'] ?? false) ? 'Testentwurf · ohne Versand' : (\App\Models\AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen') }}</span></summary><div class="rt-intake-drawer__disclosure-content">
                                <p class="rt-intake-drawer__original">{{ $delivery->body }}</p>
                                @if($customerMailSuppressed && in_array($delivery->status, ['draft', 'pending'], true))<p class="ops-muted">Der Versand ist für diesen Vorgang gesperrt.</p>
                                @elseif($delivery->status === 'draft' && !($delivery->metadata['manual_mailbox_test'] ?? false))<p class="ops-muted">Empfänger und Vorlage vor dem Versand im AI-Assistenten persönlich freigeben oder verwerfen.</p>
                                @elseif($delivery->metadata['manual_mailbox_test'] ?? false)<p class="ops-muted">Dieser Testentwurf bleibt ohne Versand.</p>
                                @elseif($delivery->status === 'unknown')<p class="ops-muted">Der Versand wird nicht automatisch wiederholt. Bitte im Dispositionspostfach prüfen, ob die Nachricht gesendet wurde.</p>@endif
                            </div></details>
                        @empty<p class="ops-muted">Keine ausgehenden Kundennachrichten hinterlegt.</p>@endforelse
                        <h4 class="rt-intake-drawer__section-title">Verarbeitung</h4>
                        @forelse($selected->runs as $run)<p class="rt-intake-drawer__run">{{ $run->started_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ ['running'=>'Analyse läuft','completed'=>'Analyse abgeschlossen','succeeded'=>'Verarbeitung abgeschlossen','failed'=>'Analyse prüfen','stale'=>'Stand inzwischen geändert'][$run->status] ?? 'Verarbeitung protokolliert' }}</p>@empty<p class="ops-muted">Noch keine Verarbeitung protokolliert.</p>@endforelse
                    </section>
                </div>
            @endif
            <x-slot:footer>
                @if($selected)
                    <div class="rt-intake-drawer__footer" data-intake-drawer-footer>
                        @if($drawerState['reason'])<span class="sr-only" id="intake-drawer-footer-reason-{{ $this->getId() }}">{{ $drawerState['reason'] }}</span>@endif
                        <x-ui.dropdown.anchor-dropdown align="top" width="80" :dropdown-id="'intake-actions-'.$this->getId()" content-label="Weitere Aktionen für diesen Eingang" class="rt-intake-drawer__more">
                            <x-slot:trigger><x-ui.buttons.button-basic type="button" aria-label="Weitere Aktionen" aria-haspopup="menu" x-bind:aria-expanded="open"><span>Mehr</span><i class="far fa-ellipsis-h" aria-hidden="true"></i></x-ui.buttons.button-basic></x-slot:trigger>
                            <x-slot:content>
                                @foreach($drawerState['more'] as $footerAction)
                                    <x-ui.dropdown.dropdown-link :as="$footerAction['href'] ? 'a' : 'button'" :href="$footerAction['href']" role="menuitem" wire:key="intake-more-{{ $footerAction['key'] }}"
                                        :wire:click="$footerAction['action'] ?: null" :wire:confirm="$footerAction['confirm'] ?: null" :disabled="$footerAction['disabled'] ? true : null" wire:loading.attr="disabled" data-no-navigate>{{ $footerAction['label'] }}</x-ui.dropdown.dropdown-link>
                                @endforeach
                            </x-slot:content>
                        </x-ui.dropdown.anchor-dropdown>
                        <div class="rt-intake-drawer__footer-actions">
                            @if($drawerState['cancelAction'])<x-ui.buttons.button-basic type="button" wire:click="{{ $drawerState['cancelAction'] }}" wire:loading.attr="disabled">Abbrechen</x-ui.buttons.button-basic>@endif
                            @foreach(['primary', 'secondary'] as $footerGroup)
                                @php($footerAction = $drawerState[$footerGroup])
                                @if($footerAction)
                                    @if($footerAction['action'] === 'save-position')
                                        <x-ui.buttons.button-basic type="submit" :form="'intake-drawer-position-form-'.$this->getId()" mode="primary" wire:loading.attr="disabled">{{ $footerAction['label'] }}</x-ui.buttons.button-basic>
                                    @else
                                        <x-ui.buttons.button-basic type="button" :mode="$footerGroup === 'primary' ? 'primary' : 'secondary'" :href="$footerAction['href']" :wire:click="$footerAction['action'] ?: null" :wire:confirm="$footerAction['confirm'] ?: null" :disabled="$footerAction['disabled'] ? true : null" :aria-describedby="$drawerState['reason'] ? 'intake-drawer-footer-reason-'.$this->getId() : null" wire:loading.attr="disabled" data-no-navigate>{{ $footerAction['label'] }}</x-ui.buttons.button-basic>
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-slot:footer>
        </x-operations.modal>
    @endif
    @if($customerDraftOpen && $selectedId)<livewire:admin.operations.customers :embedded="true" :modal-only="true" :start-creating="true" :workspace-revision="$selectedRevision" :draft-intake-id="$selectedId" :key="'ai-customer-draft-'.$selectedId.'-'.$selectedRevision" />@endif
</section>
