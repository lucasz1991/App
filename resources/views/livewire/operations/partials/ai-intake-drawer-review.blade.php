{{-- Formularbearbeitung, Reiter „Bearbeiten“: Kunde, Positionen und Planungsvorschläge. --}}
<section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-review" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-review" @if($detailTab !== 'review') hidden @endif>
    @if($selected->status === 'not_inquiry')
        <p class="ops-muted" role="status">Als „Keine Anfrage“ abgeschlossen. Zum erneuten Prüfen die Markierung im Postfach zurücksetzen.</p>
    @else
        @if($customerEditorOpen || !$selected->customer)
            @include('livewire.operations.partials.ai-intake-drawer-customer')
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
                        <x-operations.intake.facts :activities="$activityCatalog ?? []" class="rt-intake-drawer__facts" :demand="$payload['demand'] ?? []" variant="drawer" :assumptions="$payload['assumptions'] ?? []" :confirmed="$positionConfirmed" />
                    @endif

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
                            <x-operations.intake.facts :activities="$activityCatalog ?? []" class="rt-intake-drawer__facts" :demand="$position" variant="drawer" :assumptions="$position['assumptions'] ?? []" />
                        </article>
                    @endforeach
                @else
                    <div class="rt-intake-drawer__empty"><h3>Noch keine Positionen</h3><p class="ops-muted">Angaben direkt per Formular erfassen. AI-Assist kann bei Bedarf unterstützen.</p></div>
                @endif
            @endif
        </section>

        @php $historicalReferences = $selected->proposals->filter(fn ($proposal) => ($proposal->source_revision !== $selected->source_revision || $proposal->status === 'stale') && $proposal->inquiry)->unique('inquiry_id'); @endphp
        @if($historicalReferences->isNotEmpty())<details class="rt-intake-drawer__disclosure"><summary>Frühere Anfragen</summary><div class="rt-intake-drawer__disclosure-content"><p class="ops-muted">Verknüpfungen aus früheren Prüfständen.</p>@foreach($historicalReferences as $reference)<x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases',['view'=>'inbox','inquiry'=>$reference->inquiry_id])" data-no-navigate>Native Anfrage {{ $reference->inquiry->number }} öffnen</x-ui.buttons.button-basic>@endforeach</div></details>@endif
        @endif
    @endif
</section>
