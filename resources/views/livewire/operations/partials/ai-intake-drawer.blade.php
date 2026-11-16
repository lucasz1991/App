@if($selected)
    @php
        $currentProposals = $selected->proposals->where('source_revision', $selected->source_revision)->where('status', '!=', 'stale');
        $customerMailSuppressed = $selected->processing_mode === 'manual' || $selected->proposals->contains(fn ($record) => ($record->payload['review_confirmation']['customer_communication'] ?? null) === 'off');
    @endphp
    <div class="rt-intake-drawer" data-intake-drawer data-active-tab="{{ $detailTab }}"
        x-data="{ editorFocusKey: null }"
        x-effect="const key = $wire.manualEditorOpen ? 'new-' + $wire.selectedId : ($wire.editingProposalId !== null ? 'position-' + $wire.editingProposalId : ($wire.customerEditorOpen ? 'customer-' + $wire.selectedId : ($wire.planningProposalId !== null ? 'planning-' + $wire.planningProposalId : null))); const changed = key !== editorFocusKey; editorFocusKey = key; if (changed &amp;&amp; key &amp;&amp; $wire.detailTab === 'review') $nextTick(() => { const region = key.startsWith('customer-') ? $el.querySelector('.rt-intake-drawer__customer-form') : (key.startsWith('planning-') ? $el.querySelector('[data-ai-intake-planning-editor]') : $el.querySelector('[data-intake-position-form]')); const target = region?.querySelector('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[role=combobox]:not([disabled]),button:not([disabled])'); target?.focus({ preventScroll: true }); target?.scrollIntoView({ block: 'nearest', behavior: 'auto' }); });">
        <header class="rt-intake-drawer__context">
            <p class="rt-intake-drawer__meta">AI-{{ str_pad((string) $selected->id, 6, '0', STR_PAD_LEFT) }} · {{ ['email' => 'E-Mail', 'manual' => 'Formular', 'text' => 'Text', 'file' => 'Datei', 'audio' => 'Sprachaufnahme'][$selected->source_type] ?? 'Eingang' }}@if($selected->created_at) · {{ $selected->created_at->timezone('Europe/Berlin')->format('d.m.Y, H:i') }}@endif</p>
            <div class="rt-intake-drawer__heading">
                <h2 class="rt-intake-drawer__title" tabindex="-1" data-intake-drawer-heading>{{ $selected->title ?: 'Eingang prüfen' }}</h2>
                @if($selected->status !== 'not_inquiry')<x-ui.buttons.button-basic type="button" mode="link" class="rt-intake-drawer__assist" wire:click="openAssist" wire:loading.attr="disabled" title="Mit AI-Assist öffnen" aria-label="Mit AI-Assist öffnen"><i class="far fa-sparkles" aria-hidden="true"></i>AI-Assist</x-ui.buttons.button-basic>@endif
            </div>
            @include('livewire.operations.partials.ai-intake-drawer-customer')
        </header>

        <x-operations.panel.tabs class="rt-intake-drawer__tabs" label="Eingang bearbeiten" :id-prefix="'intake-drawer-'.$this->getId()" action="setDetailTab" model="detailTab" :active="$detailTab" :tabs="['review' => ['label' => 'Bearbeiten'], 'sources' => ['label' => 'Original & Anlagen'], 'communication' => ['label' => 'Verlauf']]" />

        @if($drawerState['stale'])<div class="rt-intake-drawer__notice" role="status">Dieser Eingang oder seine Einstellungen wurden inzwischen geändert. Vor einer Änderung den aktuellen Stand laden.</div>@endif

        <section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-review" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-review" @if($detailTab !== 'review') hidden @endif>
            @if($selected->status === 'not_inquiry')
                <p class="ops-muted" role="status">Als „Keine Anfrage“ abgeschlossen. Zum erneuten Prüfen die Markierung im Postfach zurücksetzen.</p>
            @else
                @if($manualEditorOpen)
                    <section class="rt-intake-drawer__position rt-intake-drawer__form" aria-label="Neue Position erfassen" data-intake-manual-editor>
                        <h3>Position erfassen</h3>
                        @include('livewire.operations.partials.ai-intake-position-form', ['payload' => []])
                    </section>
                @endif

                <section class="rt-intake-drawer__positions" aria-label="Positionen und Planungsvorschläge">
                    @foreach($currentProposals as $proposal)
                        @include('livewire.operations.partials.ai-intake-drawer-position')
                    @endforeach
                    @if($currentProposals->isEmpty() && !$manualEditorOpen)
                        @if($selected->processed_source_revision === $selected->source_revision && is_array($selected->analysis['positions'] ?? null) && !empty($selected->analysis['positions']))
                            @foreach($selected->analysis['positions'] as $index => $position)
                                @continue(!is_array($position))
                                <article class="rt-intake-drawer__position" data-ai-intake-analysis-preview wire:key="drawer-analysis-{{ $selected->id }}-{{ $selected->source_revision }}-{{ $index }}">
                                    <div class="rt-intake-drawer__position-header"><h3>Position {{ $index + 1 }}</h3><span class="rt-intake-drawer__meta">Erkannte Angaben</span></div>
                                    <h4>{{ $position['title'] ?? 'Leistung prüfen' }}</h4>
                                    <x-operations.intake.facts class="rt-intake-drawer__facts" :demand="$position" variant="drawer" :assumptions="$position['assumptions'] ?? []" />
                                </article>
                            @endforeach
                        @else
                            <div class="rt-intake-drawer__empty"><h3>Noch keine Positionen</h3><p class="ops-muted">Angaben direkt per Formular erfassen. AI-Assist kann bei Bedarf unterstützen.</p></div>
                        @endif
                    @endif
                </section>

                @if($manualReady)
                    <x-ui.buttons.button-basic type="button" mode="link" class="rt-intake-drawer__add" wire:click="addManualPosition" wire:loading.attr="disabled" :disabled="!$drawerState['canAdd'] ? true : null"><i class="far fa-plus" aria-hidden="true"></i>Position per Formular erfassen</x-ui.buttons.button-basic>
                @endif

                <details class="rt-intake-drawer__disclosure">
                    <summary>Prüfhinweise und Quellen @if(count($selected->missing_fields ?? []) > 0)<span class="rt-intake-drawer__meta">{{ count($selected->missing_fields) }} offene Angaben</span>@endif</summary>
                    <div class="rt-intake-drawer__disclosure-content">
                        @if($selected->summary)<p>{{ $selected->summary }}</p>@endif
                        @if($selected->missing_fields)<ul>@foreach($selected->missing_fields as $field)@php($fieldKey = is_array($field) ? ($field['field'] ?? '') : $field)<li>{{ is_array($field) && isset($field['question']) ? $field['question'] : ($fieldLabels[$fieldKey] ?? 'Angabe prüfen') }}</li>@endforeach</ul>@endif
                        @if($selected->error_code)<p class="ops-muted" role="status">Die Verarbeitung benötigt eine Prüfung. Quellen und erkannte Angaben bleiben erhalten.</p>@endif
                        @if($selected->proposals->contains(fn ($record) => $record->source_revision !== $selected->source_revision || $record->status === 'stale'))<p class="ops-muted">Ein neuerer Eingang liegt vor. Frühere Angaben erst nach Prüfung der aktuellen Quelle übernehmen.</p>@endif
                        @if(!$enabled && $selected->source_type !== 'email')<p class="ops-muted">Die automatische Auswertung ist aus. Eingang und Anlagen bleiben erhalten.</p>@endif
                        <x-ui.buttons.button-basic type="button" mode="link" wire:click="setDetailTab('sources')">Original und Anlagen öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                        @include('livewire.operations.partials.ai-intake-context-hints', ['showCandidateHints' => false])
                    </div>
                </details>

                @php($historicalReferences = $selected->proposals->filter(fn ($proposal) => ($proposal->source_revision !== $selected->source_revision || $proposal->status === 'stale') && $proposal->inquiry)->unique('inquiry_id'))
                @if($historicalReferences->isNotEmpty())<details class="rt-intake-drawer__disclosure"><summary>Frühere Anfragen</summary><div class="rt-intake-drawer__disclosure-content"><p class="ops-muted">Verknüpfungen aus früheren Prüfständen.</p>@foreach($historicalReferences as $reference)<x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases',['view'=>'inbox','inquiry'=>$reference->inquiry_id])" data-no-navigate>Native Anfrage {{ $reference->inquiry->number }} öffnen</x-ui.buttons.button-basic>@endforeach</div></details>@endif
                <details class="rt-intake-drawer__disclosure"><summary>Weitere Aktionen <span class="rt-intake-drawer__meta">{{ $labels[$selected->status] ?? 'Prüfung nötig' }}</span></summary><div class="rt-intake-drawer__disclosure-content">
                    @if($selected->processing_mode === 'manual')<p class="ops-muted" role="status">Formularbearbeitung · Automatische Übernahme und Kundenkommunikation bleiben ausgeschaltet. AI-Assist bleibt optional.</p>@endif
                    @if(!$manualReady)<p class="ops-muted">Die Formularbearbeitung benötigt noch die Datenbankerweiterung. Der bestehende AI-Assist ist weiterhin verfügbar.</p>@endif
                    <div class="rt-intake-drawer__actions">
                        <x-ui.buttons.button-basic type="button" wire:click="pause" wire:loading.attr="disabled" :disabled="in_array($selected->status, ['paused', 'completed', 'not_inquiry'], true) ? true : null">Pausieren</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic type="button" wire:click="reanalyze" wire:loading.attr="disabled" :disabled="($selected->processing_mode === 'manual' || (!$enabled && $selected->source_type !== 'email') || in_array($selected->status, ['paused', 'completed', 'analyzing', 'not_inquiry'], true)) ? true : null">Erneut prüfen</x-ui.buttons.button-basic>
                    </div>
                </div></details>
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
                    @elseif($delivery->status === 'draft' && !($delivery->metadata['manual_mailbox_test'] ?? false))<p class="ops-muted">Empfänger und Vorlage vor dem Versand im AI-Assistenten persönlich freigeben oder verwerfen.</p><x-ui.buttons.button-basic type="button" mode="link" wire:click="openOutbox" wire:loading.attr="disabled">Kundennachrichten im AI-Assistenten prüfen</x-ui.buttons.button-basic>
                    @elseif($delivery->metadata['manual_mailbox_test'] ?? false)<p class="ops-muted">Dieser Testentwurf bleibt ohne Versand.</p>
                    @elseif($delivery->status === 'unknown')<p class="ops-muted">Der Versand wird nicht automatisch wiederholt. Bitte im Dispositionspostfach prüfen, ob die Nachricht gesendet wurde.</p>@endif
                </div></details>
            @empty<p class="ops-muted">Keine ausgehenden Kundennachrichten hinterlegt.</p>@endforelse
            <h4 class="rt-intake-drawer__section-title">Verarbeitung</h4>
            @forelse($selected->runs as $run)<p class="rt-intake-drawer__run">{{ $run->started_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ ['running'=>'Analyse läuft','completed'=>'Analyse abgeschlossen','succeeded'=>'Verarbeitung abgeschlossen','failed'=>'Analyse prüfen','stale'=>'Stand inzwischen geändert'][$run->status] ?? 'Verarbeitung protokolliert' }}</p>@empty<p class="ops-muted">Noch keine Verarbeitung protokolliert.</p>@endforelse
        </section>
    </div>
@endif
