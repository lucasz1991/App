{{-- KI-Eingang im Assistenten (eingebettet): Erfassung, Detail oder Liste plus Fragenfeld. --}}
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
