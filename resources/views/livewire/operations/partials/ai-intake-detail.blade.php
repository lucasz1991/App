        @if($selected)
            @php
                $confirmationState = array_replace(['enabled' => false, 'completed' => false, 'reason' => 'Die erkannte Grundlage zuerst prüfen.'], is_array($confirmation ?? null) ? $confirmation : []);
                if ($editingProposalId !== null) {
                    $confirmationState['enabled'] = false;
                    $confirmationState['reason'] = 'Korrektur zuerst speichern.';
                }
                $openFieldCount = count($selected->missing_fields ?? []);
                $customerMailSuppressed = $selected->processing_mode === 'manual' || $selected->proposals->contains(fn ($record) => ($record->payload['review_confirmation']['customer_communication'] ?? null) === 'off');
            @endphp
            @unless($embedded)<x-operations.feedback :show-offline="false" />@endunless
            @unless($embedded)<header><div class="ops-actions"><x-operations.status :value="$selected->status" :label="$labels[$selected->status] ?? 'Prüfung nötig'" /><span class="ops-muted">AI-{{ str_pad((string)$selected->id,6,'0',STR_PAD_LEFT) }}</span></div><h2 class="mt-2 text-lg font-semibold break-words">{{ $selected->title }}</h2><p class="mt-2 ops-muted break-words">{{ $selected->summary }}</p></header>@endunless
            @if($selected->revision !== $selectedRevision || $selectedSettingsRevision !== $settingsRevision)<div class="ops-actions" role="status"><p class="ops-muted">Dieser Eingang oder seine Einstellungen wurden inzwischen geändert. Vor einer Änderung den aktuellen Stand laden.</p><x-ui.buttons.button-basic type="button" wire:click="select({{ $selected->id }})">Aktualisieren</x-ui.buttons.button-basic></div>@endif
            <div @class(['ai-intake-panel__detail-pane', 'ai-intake-panel__review' => $embedded]) @if($embedded) id="intake-{{ $this->getId() }}-panel-review" role="tabpanel" aria-labelledby="intake-{{ $this->getId() }}-tab-review" @if($detailTab !== 'review') hidden @endif @endif>
            @unless($embedded)
            @if($selected->status !== 'not_inquiry')
                <div class="ops-actions">
                    @if($manualReady)<x-ui.buttons.button-basic type="button" wire:click="addManualPosition" wire:loading.attr="disabled" :disabled="($manualEditorOpen || $editingProposalId !== null) ? true : null"><i class="far fa-plus" aria-hidden="true"></i>Position per Formular erfassen</x-ui.buttons.button-basic>@endif
                    <x-ui.buttons.button-basic type="button" wire:click="openAssist" wire:loading.attr="disabled"><i class="far fa-sparkles" aria-hidden="true"></i>Mit AI-Assist öffnen</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" wire:click="pause" wire:loading.attr="disabled">Pausieren</x-ui.buttons.button-basic>
                </div>
                @if($selected->processing_mode === 'manual')<p class="ops-muted" role="status">Formularbearbeitung · Automatische Übernahme und Kundenkommunikation bleiben ausgeschaltet. AI-Assist ist weiterhin auf Wunsch verfügbar.</p>@endif
                @if(!$manualReady)<p class="ops-muted" role="status">Die Formularbearbeitung benötigt noch die Datenbankerweiterung. Der bestehende AI-Assist ist weiterhin verfügbar.</p>@endif
            @else<p class="ops-muted" role="status">Als „Keine Anfrage“ abgeschlossen und aus der Dispositions-Eingangsliste entfernt. Zum erneuten Prüfen die Markierung im Postfach zurücksetzen.</p>@endif
            @endunless
            @if($embedded && $selected->status === 'not_inquiry')<p class="ops-muted" role="status">Keine Anfrage · Im Postfach kann die Markierung zurückgesetzt werden.</p>@endif
            @if($embedded)<div class="ops-actions"><x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::intakeFormUrl($selected->id, $customerId)" data-no-navigate x-on:click="$dispatch('railtime-assistant-close')"><i class="far fa-file-alt" aria-hidden="true"></i>Im Formular bearbeiten</x-ui.buttons.button-basic></div>@endif
            @if(!$enabled && $selected->source_type !== 'email')<p class="ops-muted" role="status">Die automatische Auswertung ist aus. Dieser Eingang und seine Anlagen bleiben erhalten; erneut analysieren ist nach Aktivierung verfügbar.</p>@endif
            <x-ui.forms.input-error for="intake" />
            @if($selected->error_code && $selected->status !== 'not_inquiry' && (!$embedded || $selected->error_code !== 'mailbox_test_review'))<p class="ops-muted" role="alert">Die Verarbeitung benötigt eine Prüfung. Quellen und erkannte Angaben bleiben erhalten.</p>@endif
            @if($selected->proposals->contains(fn ($record) => $record->source_revision !== $selected->source_revision || $record->status === 'stale'))<p class="ops-muted" role="status">Ein neuerer Eingang liegt vor. Frühere Angaben erst nach Prüfung der aktuellen Quelle übernehmen.</p>@endif
            @if(!$embedded && $selected->missing_fields)
                <div class="ai-intake-panel__accordion ai-intake-panel__missing" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                    <x-ui.accordion.section compact section="missing" :label="count($selected->missing_fields).' offene Angaben'" icon="far fa-exclamation-circle" :id-prefix="'intake-missing-'.$this->getId()">
                        <ul class="space-y-2 text-sm">@foreach($selected->missing_fields as $field)@php($fieldKey = is_array($field) ? ($field['field'] ?? '') : $field)<li>{{ is_array($field) && isset($field['question']) ? $field['question'] : ($fieldLabels[$fieldKey] ?? 'Angabe prüfen') }}</li>@endforeach</ul>
                    </x-ui.accordion.section>
                </div>
            @endif
            @if($selected->status !== 'not_inquiry')<section class="ai-intake-panel__customer border-t border-rt-border pt-3 dark:border-rt-dark-border" x-data="{ editingCustomer: false }" wire:key="intake-customer-{{ $selected->id }}-{{ $selected->revision }}" data-intake-customer-assignment>
                @if($selected->customer)
                    <div class="flex min-w-0 items-center gap-2" x-show="!editingCustomer">
                        <i class="far fa-building text-rt-muted" aria-hidden="true"></i>
                        <div class="min-w-0 flex-1"><h3 class="break-words font-semibold">{{ $selected->customer->company_name }}</h3><p class="ops-muted">{{ $selected->match_method === 'source_context' ? 'Automatisch erkannt und zugeordnet' : 'Kunde zugeordnet' }}@if($selected->contact) · {{ $selected->contact->name }}@endif</p></div>
                        <x-ui.buttons.button-basic type="button" size="sm" x-ref="customerEditTrigger" x-on:click="editingCustomer = true; $nextTick(() => $refs.customerEditor.querySelector('[role=combobox],select,button')?.focus())" aria-label="Kundenzuordnung ändern" title="Kundenzuordnung ändern"><i class="far fa-pen" aria-hidden="true"></i></x-ui.buttons.button-basic>
                    </div>
                @else
                    <h3 class="font-semibold">Kundenzuordnung</h3>
                @endif
                <form wire:submit="assignCustomer" class="ops-form mt-3" x-ref="customerEditor" @if($selected->customer) x-show="editingCustomer" x-cloak @endif>
                <div class="ops-full"><x-ui.forms.label :for="'ai-intake-customer-'.$this->getId()" value="Kunde" /><x-ui.forms.select :id="'ai-intake-customer-'.$this->getId()" wire:model.live="assignCustomerId"><option value="">Bitte prüfen und zuordnen</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select><x-ui.forms.input-error for="assignCustomerId" /></div>
                <div class="ops-full"><x-ui.forms.label :for="'ai-intake-contact-'.$this->getId()" value="Kontakt" /><x-ui.forms.select :id="'ai-intake-contact-'.$this->getId()" wire:model="assignContactId"><option value="">Hauptkontakt des Kunden</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}{{ $contact->email ? ' · '.$contact->email : '' }}</option>@endforeach</x-ui.forms.select><x-ui.forms.input-error for="assignContactId" /></div>
                <div class="ops-actions ops-full"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">{{ $selected->customer ? 'Zuordnung speichern' : 'Zuordnung bestätigen' }}</x-ui.buttons.button-basic>@if($selected->customer)<x-ui.buttons.button-basic type="button" x-on:click="editingCustomer = false; $nextTick(() => $refs.customerEditTrigger?.focus())">Abbrechen</x-ui.buttons.button-basic>@endif</div>
            </form>
            @if($customerSuggestions)<p class="mt-3 ops-muted">Mögliche Kunden anhand von Absender, CC, Inhalt und Signatur. Die Zuordnung bitte bestätigen.</p><ul class="mt-2 space-y-2 text-sm">@foreach($customerSuggestions as $suggestion)@if($suggestedCustomer = $customers->firstWhere('id',$suggestion['customer_id']))<li>{{ $suggestedCustomer->company_name }} · {{ implode(', ',array_map(fn($reason)=>\App\Services\Operations\AiIntakeCustomerContextService::REASON_LABELS[$reason] ?? 'Zuordnung prüfen',$suggestion['reasons'])) }}</li>@endif @endforeach</ul>@endif
            @if(!$selected->customer_id && $canApprove && !$customerId)<div class="mt-3 ops-stack">@if($selected->analysis['customer_draft'] ?? null)<p class="text-sm">Vorbereiteter Kunde: {{ $selected->analysis['customer_draft']['company_name'] ?? 'Firmenname prüfen' }}{{ !empty($selected->analysis['customer_draft']['contact_name']) ? ' · '.$selected->analysis['customer_draft']['contact_name'] : '' }}</p>@endif<x-ui.buttons.button-basic type="button" wire:click="prepareCustomer">Neuen Kunden prüfen und anlegen</x-ui.buttons.button-basic></div>@endif
            </section>
            @unless($embedded) @include('livewire.operations.partials.ai-intake-context-hints', ['showCandidateHints' => false]) @endunless
            @if($manualEditorOpen)
                <section class="ai-intake-panel__position" aria-label="Neue Position erfassen" data-intake-manual-editor>
                    <h3 class="font-semibold">Position erfassen</h3>
                    @include('livewire.operations.partials.ai-intake-position-form', ['payload' => []])
                </section>
            @endif
            <section class="ai-intake-panel__positions" aria-label="Positionen und Planungsvorschläge">
                @php($currentProposals = $selected->proposals->where('source_revision', $selected->source_revision)->where('status', '!=', 'stale'))
                @forelse($currentProposals as $proposal)
                    @php($payload = $proposal->payload ?? [])
                    @php($currentProposal = $proposal->source_revision === $selected->source_revision && $selected->revision === $selectedRevision)
                    @php($canCorrect = $currentProposal && !in_array($proposal->status,['applied','stale'],true) && (!$proposal->inquiry || in_array($proposal->inquiry->status,['new','verified'],true)))
                    <article class="ai-intake-panel__position" wire:key="ai-proposal-{{ $proposal->id }}-{{ $proposal->revision }}" x-data="{ openAccordionSection: @js($loop->first || $editingProposalId === $proposal->id || $planningProposalId === $proposal->id ? 'position-'.$proposal->id : null), toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }" x-effect="if ($wire.editingProposalId === {{ $proposal->id }} || $wire.planningProposalId === {{ $proposal->id }}) openAccordionSection = @js('position-'.$proposal->id)">
                        <x-ui.accordion.section compact class="ai-intake-panel__recognized" :section="'position-'.$proposal->id" :label="$embedded ? 'Erkannte Angaben'.($currentProposals->count() > 1 ? ' · Position '.($proposal->position_index+1) : '') : ($payload['demand']['title'] ?? 'Position '.($proposal->position_index+1))" :description="($embedded ? ($payload['demand']['title'] ?? '').' · ' : '').(['proposed'=>'Vorschlag','approved'=>'Freigegeben','applied'=>'Entwürfe erstellt','stale'=>'Erneut prüfen','failed'=>'Übernahme prüfen'][$proposal->status] ?? 'Prüfung nötig')" icon="far fa-clipboard-list" :id-prefix="'intake-position-'.$this->getId()">
                        <x-operations.intake.facts :demand="$payload['demand'] ?? []" :variant="$embedded ? 'review' : 'grid'" :assumptions="$payload['assumptions'] ?? []" :confirmed="$embedded && (bool) $confirmationState['completed']" />
                        @unless($embedded)<p class="ops-muted">{{ ($payload['provenance']['kind'] ?? '') === 'manual' ? 'Im Formular erfasst' : 'AI-Vorschlag' }} · {{ ($payload['business_path'] ?? 'inquiry') === 'direct_order' ? 'Direktauftrag ohne Angebot / Kundenzusage' : 'Anfrage mit Angebot / Kundenzusage' }}</p>@endunless
                        @if(!$embedded && ($payload['assumptions']['required_staff']['basis'] ?? '') === 'default')<p class="ops-muted" role="status" title="In der Mail fehlt eine Personalanzahl. Bitte bestätigen oder anpassen."><i class="far fa-info-circle" aria-hidden="true"></i> Standardvorschlag: 1 Person · Bitte prüfen</p>@endif
                        @if(!empty($payload['assumptions']['history_review']))<p class="ops-muted" title="Angaben vor der Übernahme mit der aktuellen Mail abgleichen."><i class="far fa-history" aria-hidden="true"></i> Historische Vergleichsdaten verwendet</p>@endif
                        @if($proposal->inquiry)@include('livewire.operations.partials.ai-intake-planning')@endif
                        <div class="ai-intake-panel__accordion mt-2" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                            <x-ui.accordion.section compact section="evidence" label="Prüfhinweise und Quellen" icon="far fa-search" :id-prefix="'intake-evidence-'.$this->getId().'-'.$proposal->id">
                        @if($payload['segments'] ?? [])<p class="mt-2 ops-muted">{{ count($payload['segments']) }} ausdrücklich erkannte Schichtabschnitte.</p>@endif
                        @if($payload['raw_requirements'] ?? [])<p class="mt-2 text-sm"><span class="font-semibold">Erkannte Qualifikationsanforderungen:</span> {{ implode(', ', $payload['raw_requirements']) }}</p>@endif
                        @if($payload['unmapped_requirements'] ?? [])<p class="mt-2 ops-muted" role="status">Noch zu prüfen: {{ implode(', ', $payload['unmapped_requirements']) }}. Passende Qualifikationen auswählen und die Prüfung bestätigen.</p>@endif
                        @if($payload['demand']['qualification_ids'] ?? [])<p class="mt-2 text-sm"><span class="font-semibold">Qualifikationen:</span> {{ $qualifications->whereIn('id',$payload['demand']['qualification_ids'])->pluck('name')->implode(', ') }}</p>@endif
                                <dl class="mt-2 space-y-2 text-sm">@forelse(($payload['evidence'] ?? []) as $field=>$evidence)<div><dt class="font-medium">{{ is_array($evidence) ? ($evidence['field'] ?? $field) : $field }}</dt><dd class="break-words ops-muted">{{ is_array($evidence) ? ($evidence['quote'] ?? 'Quelle im Originaleingang prüfen.') : $evidence }} @if(is_array($evidence) && ($evidence['message_id'] ?? null))<span class="block text-xs">Nachricht {{ $evidence['message_id'] }}</span>@endif</dd></div>@empty<p class="ops-muted">Die ursprünglichen Nachrichten und Anlagen stehen unten zur Prüfung bereit.</p>@endforelse</dl>
                            </x-ui.accordion.section>
                        </div>
                        @if(!$currentProposal)<p class="mt-2 ops-muted">Ein neuerer Eingang liegt vor. Den aktuellen Vorschlag prüfen.</p>@elseif($proposal->error_code === 'reply_position_review')<p class="mt-2 ops-muted">Diese Antwort muss zuerst einer vorhandenen Position zugeordnet werden.</p>@elseif($proposal->error_code === 'native_workflow_active')<p class="mt-2 ops-muted">Diesen Vorgang in der bestehenden Anfrage prüfen; Angebot oder Beauftragung sind bereits angelaufen.</p>@endif
                        @if($currentProposal && $proposal->error_code === 'reply_position_review')<form wire:submit="mapProposal({{ $proposal->id }},{{ $proposal->revision }})" class="ops-stack mt-3"><x-ui.forms.label :for="'ai-map-'.$this->getId().'-'.$proposal->id" value="Vorhandene Anfrage für diese Antwort" /><x-ui.forms.select :id="'ai-map-'.$this->getId().'-'.$proposal->id" :wire:model="'mappingInquiryIds.'.$proposal->id"><option value="">Position ausdrücklich zuordnen</option>@foreach(($payload['candidate_inquiry_ids'] ?? []) as $candidateId)@if($candidate = $mappingInquiries->get($candidateId))<option value="{{ $candidate->id }}">{{ $candidate->number }} · {{ $candidate->title }} · {{ $candidate->location_name }}</option>@endif @endforeach</x-ui.forms.select><x-ui.forms.input-error :for="'mappingInquiryIds.'.$proposal->id" /><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Positionszuordnung bestätigen</x-ui.buttons.button-basic></form>@endif
                        @if($editingProposalId === $proposal->id && $canCorrect)
                            @include('livewire.operations.partials.ai-intake-position-form')
                        @elseif($canCorrect && ($embedded || $manualReady))<div class="ops-actions mt-3"><x-ui.buttons.button-basic type="button" wire:click="editProposal({{ $proposal->id }})">{{ $embedded ? 'Angaben korrigieren' : 'Im Formular bearbeiten' }}</x-ui.buttons.button-basic></div>@endif
                        </x-ui.accordion.section>
                    </article>
                @empty
                    @if($selected->processed_source_revision === $selected->source_revision && is_array($selected->analysis['positions'] ?? null) && !empty($selected->analysis['positions']))
                        @foreach($selected->analysis['positions'] as $index => $position)
                            @continue(!is_array($position))
                            <article class="ai-intake-panel__position" data-ai-intake-analysis-preview wire:key="ai-analysis-{{ $selected->id }}-{{ $selected->source_revision }}-{{ $selected->revision }}-{{ $index }}" x-data="{ openAccordionSection: @js($loop->first ? 'analysis-'.$index : null), toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                                <x-ui.accordion.section compact class="ai-intake-panel__recognized" :section="'analysis-'.$index" :label="$embedded ? 'Erkannte Angaben'.(count($selected->analysis['positions']) > 1 ? ' · Position '.($index + 1) : '') : ($position['title'] ?? 'Position '.($index + 1))" :description="$embedded ? ($position['title'] ?? null) : 'Erkannte Angaben'" icon="far fa-clipboard-list" :id-prefix="'intake-analysis-'.$this->getId()">
                                <x-operations.intake.facts :demand="$position" :variant="$embedded ? 'review' : 'grid'" :assumptions="$position['assumptions'] ?? []" />
                                @if(!$embedded && ($position['assumptions']['required_staff']['basis'] ?? '') === 'default')<p class="ops-muted" role="status" title="In der Mail fehlt eine Personalanzahl. Bitte bestätigen oder anpassen."><i class="far fa-info-circle" aria-hidden="true"></i> Standardvorschlag: 1 Person · Bitte prüfen</p>@endif
                                @if(!empty($position['assumptions']['history_review']))<p class="ops-muted" title="Angaben vor der Übernahme mit der aktuellen Mail abgleichen."><i class="far fa-history" aria-hidden="true"></i> Historische Vergleichsdaten verwendet</p>@endif
                                @php($previewMissing = array_values(array_unique(array_intersect(array_filter((array) ($position['missing_fields'] ?? []), 'is_string'), array_keys($fieldLabels)))))
                                @if($previewMissing)<p class="mt-2 ops-muted">Noch zu prüfen: {{ implode(' · ', array_map(fn ($field) => $fieldLabels[$field], $previewMissing)) }}</p>@endif
                                </x-ui.accordion.section>
                            </article>
                        @endforeach
                    @else<p class="mt-3 ops-muted">Noch keine Positionen vorhanden. Angaben können direkt per Formular erfasst oder mit AI-Assist vorbereitet werden.</p>@endif
                @endforelse
                @if(!$embedded && $manualReady && $currentProposals->isNotEmpty() && $selected->status !== 'not_inquiry')
                    @php($hasDirectOrders = $currentProposals->contains(fn ($record) => ($record->payload['business_path'] ?? 'inquiry') === 'direct_order' && !$record->inquiry?->order_id))
                    @php($manualWorkPending = $selected->processing_mode !== 'manual' || $hasDirectOrders || $currentProposals->contains(fn ($record) => !$record->inquiry || empty($record->payload['review_confirmation']['actor_id']) || ($record->payload['review_confirmation']['source_revision'] ?? null) !== $selected->source_revision || $record->inquiry_revision !== $record->inquiry?->revision || $record->inquiry?->customer_id !== $selected->customer_id))
                    <div class="ops-stack border-t border-rt-border pt-3 dark:border-rt-dark-border" data-intake-manual-confirmation>
                        @if($manualWorkPending)
                        <p class="ops-muted">Kunde, Positionen und Original persönlich prüfen. {{ $hasDirectOrders ? 'Gewählte Direktaufträge werden ohne Angebot und ohne gesonderte Kundenzusage bestätigt.' : 'Bestätigte Positionen stehen anschließend als Anfrage für die weitere Bearbeitung bereit.' }}</p>
                        <div class="ops-actions"><x-ui.buttons.button-basic type="button" mode="primary" wire:click="confirmManual" wire:loading.attr="disabled" :disabled="($manualEditorOpen || $editingProposalId !== null || !$selected->customer_id || ($hasDirectOrders && !$canApprove)) ? true : null">{{ $hasDirectOrders ? 'Bestätigen und Direktaufträge annehmen' : 'Positionen bestätigen und übernehmen' }}</x-ui.buttons.button-basic></div>
                        @else<p class="ops-muted" role="status"><i class="far fa-check" aria-hidden="true"></i>Positionen bestätigt und mit ihren Vorgängen verknüpft. Die weitere Bearbeitung ist direkt an der jeweiligen Position verfügbar.</p>@endif
                    </div>
                @endif
                @php($historicalReferences = $selected->proposals->filter(fn ($proposal) => ($proposal->source_revision !== $selected->source_revision || $proposal->status === 'stale') && $proposal->inquiry)->unique('inquiry_id'))
                @if($historicalReferences->isNotEmpty())<section class="mt-3 border-t border-rt-border pt-3 dark:border-rt-dark-border" aria-label="Frühere native Anfragen"><h4 class="font-semibold">Frühere Anfragen</h4><p class="ops-muted">Verknüpfungen aus früheren Prüfständen.</p>@foreach($historicalReferences as $reference)<x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases',['view'=>'inbox','inquiry'=>$reference->inquiry_id])" data-no-navigate>Native Anfrage {{ $reference->inquiry->number }} öffnen</x-ui.buttons.button-basic>@endforeach</section>@endif
            </section>
            @endif
            @if($embedded)
                @if(!empty($contextHints['history']))
                    @include('livewire.operations.partials.ai-intake-context-hints', ['showCandidateHints' => false, 'historyLabel' => 'Frühere Aufträge'])
                @else
                    <div class="ai-intake-context-hints ai-intake-panel__accordion" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                        <x-ui.accordion.section compact section="history" label="Frühere Aufträge" icon="far fa-history" :id-prefix="'intake-history-'.$this->getId()"><p class="ops-muted">Für diesen Eingang liegen keine aktuell geprüften Vergleichshinweise vor.</p></x-ui.accordion.section>
                    </div>
                @endif
                <div class="ai-intake-panel__accordion ai-intake-panel__hints" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                    <x-ui.accordion.section compact section="hints" label="Hinweise" :description="$openFieldCount > 0 ? $openFieldCount.' offene Angaben' : null" icon="far fa-info-circle" :id-prefix="'intake-hints-'.$this->getId()">
                        @if($selected->error_code === 'mailbox_test_review')<p class="ops-muted">Erkannte Angaben sind zur persönlichen Prüfung vorgemerkt.</p>@endif
                        @if($selected->summary)<p class="break-words text-sm">{{ $selected->summary }}</p>@endif
                        @if($selected->missing_fields)
                            <ul class="space-y-2 text-sm">@foreach($selected->missing_fields as $field)@php($fieldKey = is_array($field) ? ($field['field'] ?? '') : $field)<li>{{ is_array($field) && isset($field['question']) ? $field['question'] : ($fieldLabels[$fieldKey] ?? 'Angabe prüfen') }}</li>@endforeach</ul>
                        @else<p class="ops-muted">Keine weiteren offenen Angaben hinterlegt. Erkannte Angaben und Original vor der Bestätigung prüfen.</p>@endif
                        @if($selected->status !== 'not_inquiry')<x-ui.buttons.button-basic type="button" size="sm" wire:click="pause" wire:loading.attr="disabled"><i class="far fa-pause" aria-hidden="true"></i>Eingang pausieren</x-ui.buttons.button-basic>@endif
                    </x-ui.accordion.section>
                </div>
            @endif
            </div>
            <section class="ai-intake-panel__detail-pane border-t border-rt-border pt-3 dark:border-rt-dark-border" @if($embedded) id="intake-{{ $this->getId() }}-panel-sources" role="tabpanel" aria-labelledby="intake-{{ $this->getId() }}-tab-sources" @if($detailTab !== 'sources') hidden @endif @endif><h3 class="font-semibold">Nachrichten und Anlagen</h3>
                <div class="ai-intake-panel__accordion ops-stack mt-3" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                    @foreach($selected->messages as $message)
                        <x-ui.accordion.section compact :section="'message-'.$message->id" :label="($message->metadata['subject'] ?? 'Originaleingang').' · '.$message->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i')" icon="far fa-envelope" :id-prefix="'intake-source-'.$this->getId()" wire:key="ai-message-{{ $message->id }}">
                            <p class="whitespace-pre-wrap break-words text-sm">{{ $message->body }}</p>
                            @if(($message->metadata['raw_archive_complete'] ?? true) === false || ($message->metadata['text_archive_complete'] ?? true) === false)<p class="ops-muted" role="alert">Dieser Eingang überschreitet die Verarbeitungsgrenzen. Das vollständige Original im Postfach manuell prüfen; hier ist ein begrenzter Ausschnitt archiviert.</p>@endif
                            @if($message->raw_path)<x-ui.buttons.button-basic mode="link" :href="route('operations.ai-intake.original',$message->id)" data-no-navigate>{{ ($message->metadata['raw_archive_complete'] ?? true) ? 'Original herunterladen' : 'Archivierten Ausschnitt herunterladen' }}</x-ui.buttons.button-basic>@endif
                        </x-ui.accordion.section>
                    @endforeach
                </div>
                @foreach($selected->attachments as $attachment)<div class="ops-actions mt-2" wire:key="ai-attachment-{{ $attachment->id }}"><x-ui.buttons.button-basic mode="link" :href="route('operations.ai-intake.attachment',$attachment->id)" data-no-navigate>{{ $attachment->file_name }}</x-ui.buttons.button-basic><span class="ops-muted">{{ number_format($attachment->file_size/1024,0,',','.') }} KB</span></div>@endforeach
            </section>
            <section class="ai-intake-panel__detail-pane border-t border-rt-border pt-3 dark:border-rt-dark-border" @if($embedded) id="intake-{{ $this->getId() }}-panel-communication" role="tabpanel" aria-labelledby="intake-{{ $this->getId() }}-tab-communication" @if($detailTab !== 'communication') hidden @endif @endif><h3 class="font-semibold">Kundennachrichten und Verarbeitung</h3><p class="mt-2 ops-muted">{{ $selected->question_round }} Rückfragerunden.</p>
                @if($customerMailSuppressed)<p class="ops-muted" role="status">Kundenkommunikation ist für diesen manuell übernommenen Vorgang ausgeschaltet. Vorhandene Nachrichten bleiben im Verlauf erhalten.</p>@endif
                <div class="ai-intake-panel__accordion ops-stack mt-2" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
                    @foreach($selected->deliveries as $delivery)
                        <x-ui.accordion.section compact :section="'delivery-'.$delivery->id" :label="(\App\Models\AiIntakeDelivery::TYPE_LABELS[$delivery->message_type ?? 'clarification'] ?? 'Kundennachricht').' · '.(($delivery->metadata['manual_mailbox_test'] ?? false) ? 'Testentwurf · ohne Versand' : (\App\Models\AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen'))" icon="far fa-paper-plane" :id-prefix="'intake-delivery-'.$this->getId()" wire:key="ai-delivery-{{ $delivery->id }}">
                            <p class="whitespace-pre-wrap break-words text-sm">{{ $delivery->body }}</p>
                            @if($customerMailSuppressed && in_array($delivery->status, ['draft', 'pending'], true))<p class="ops-muted">Der Versand ist für diesen Vorgang gesperrt.</p>@elseif($delivery->status === 'draft' && !($delivery->metadata['manual_mailbox_test'] ?? false))<p class="ops-muted">Im AI-Assistenten können Sie Empfänger und Vorlage persönlich freigeben oder verwerfen.</p><x-ui.buttons.button-basic type="button" mode="link" x-on:click="$dispatch('railtime-assistant-open'); $wire.dispatch('operations-assistant-action', { action: 'outbox' })">Kundennachrichten im AI-Assistenten prüfen</x-ui.buttons.button-basic>@elseif($delivery->metadata['manual_mailbox_test'] ?? false)<p class="ops-muted">Dieser Testentwurf bleibt ohne Versand.</p>@elseif($delivery->status === 'unknown')<p class="ops-muted">Der Versand wird nicht automatisch wiederholt. Bitte im Dispositionspostfach prüfen, ob die Nachricht gesendet wurde.</p>@endif
                        </x-ui.accordion.section>
                    @endforeach
                </div>
                @if($selected->runs->isNotEmpty())<div class="ai-intake-panel__accordion mt-2" x-data="{ openAccordionSection: null, toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }"><x-ui.accordion.section compact section="runs" :label="'Verlauf · '.$selected->runs->count().' Prüfungen'" icon="far fa-history" :id-prefix="'intake-runs-'.$this->getId()">
                @foreach($selected->runs as $run)<p class="mt-2 ops-muted">{{ $run->started_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ ['running'=>'Analyse läuft','completed'=>'Analyse abgeschlossen','succeeded'=>'Verarbeitung abgeschlossen','failed'=>'Analyse prüfen','stale'=>'Stand inzwischen geändert'][$run->status] ?? 'Verarbeitung protokolliert' }}</p>@endforeach
                </x-ui.accordion.section></div>@endif
            </section>
            @if($embedded && $detailTab === 'review' && $selected->status !== 'not_inquiry')
                <x-operations.panel.action-bar class="ai-intake-panel__action-bar ai-intake-panel__confirmation" wire:key="intake-actions-{{ $this->getId() }}-{{ $selected->id }}">
                    <x-ui.buttons.button-basic type="button" wire:click="reanalyze" wire:loading.attr="disabled" :disabled="($selected->processing_mode === 'manual' || (!$enabled && $selected->source_type !== 'email') || in_array($selected->status, ['paused', 'completed', 'analyzing', 'not_inquiry'], true)) ? true : null"><i class="far fa-sync-alt" aria-hidden="true"></i>Erneut prüfen</x-ui.buttons.button-basic>
                    @if($selected->processing_mode === 'manual')<x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::intakeFormUrl($selected->id, $customerId)" data-no-navigate>Im Formular prüfen und übernehmen</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic type="button" mode="primary" wire:click="confirmReview" wire:loading.attr="disabled" :disabled="!$confirmationState['enabled'] ? true : null" :title="$confirmationState['reason']" :aria-describedby="$confirmationState['reason'] ? 'intake-confirmation-'.$this->getId() : null"><i class="far fa-check" aria-hidden="true"></i>{{ $confirmationState['completed'] ? 'Bestätigt · Anfrage übernommen' : 'Bestätigen · Anfrage übernehmen' }}</x-ui.buttons.button-basic>@endif
                </x-operations.panel.action-bar>
                @if($confirmationState['reason'])<p class="ops-muted" id="intake-confirmation-{{ $this->getId() }}" role="status">{{ $confirmationState['reason'] }} @if(!$confirmationState['completed'])Kundenkommunikation bleibt bei dieser Übernahme ausgeschaltet.@endif</p>@endif
            @endif
        @endif
