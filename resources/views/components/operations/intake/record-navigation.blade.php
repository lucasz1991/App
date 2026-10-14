@props(['record', 'labels' => [], 'componentId', 'activeTab' => 'review', 'livewire' => true])
@php
    $statusLabel = $labels[$record->status] ?? 'Prüfung nötig';
    $statusIcon = ['received'=>'fa-inbox','analyzing'=>'fa-sync-alt','review'=>'fa-exclamation-circle','waiting_customer'=>'fa-comment-alt','ready'=>'fa-check-circle','paused'=>'fa-pause-circle','not_inquiry'=>'fa-ban','failed'=>'fa-exclamation-triangle','completed'=>'fa-check-double'][$record->status] ?? 'fa-inbox';
    $recordLabel = 'AI-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT);
@endphp
<div {{ $attributes->class('ai-intake-panel__record-navigation') }}>
    <header class="ai-intake-panel__toolbar ai-intake-panel__record-header" data-ai-intake-record-header wire:key="intake-record-header-{{ $componentId }}-{{ $record->id }}">
        <button type="button" class="ai-intake-panel__back" @if($livewire) wire:click="closeDetail" @endif aria-label="Zurück zu den Eingängen" title="Zurück zu den Eingängen"><i class="far fa-arrow-left" aria-hidden="true"></i></button>
        <span class="ai-intake-panel__record-status" data-state="{{ $record->status }}" role="img" aria-label="Bearbeitung: {{ $statusLabel }}" title="{{ $statusLabel }}"><i class="far {{ $statusIcon }}" aria-hidden="true"></i></span>
        <span class="ai-intake-panel__record-id" aria-label="{{ $recordLabel }}">#{{ str_pad((string) $record->id, 6, '0', STR_PAD_LEFT) }}</span>
        <h2 class="ai-intake-panel__record-title" x-ref="intakePanelHeading" tabindex="-1" title="{{ $record->title ?: 'Eingang prüfen' }}">{{ $record->title ?: 'Eingang prüfen' }}</h2>
        <x-ui.dropdown.anchor-dropdown align="right" width="80" :open-on-hover="true" :hover-open-delay="220" :hover-close-delay="180" :max-height="360"
            :dropdown-id="'intake-header-'.$componentId.'-'.$record->id" layer-group="ai-intake-header" content-role="dialog" content-label="Eingangsdetails"
            class="ai-intake-panel__record-info" dropdown-classes="ai-intake-panel__record-popover" content-classes="ai-intake-panel__record-details bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
            <x-slot:trigger>
                <button type="button" class="ai-intake-panel__record-info-button" aria-label="Titel, Zusammenfassung und Bearbeitungsstand anzeigen" aria-haspopup="dialog" aria-controls="rt-dropdown-intake-header-{{ $componentId }}-{{ $record->id }}-content"
                    @focus="if ($el.matches(':focus-visible') && !$event.relatedTarget?.closest('[data-rt-dropdown-owner]')) openDropdown(false)"><i class="far fa-info-circle" aria-hidden="true"></i></button>
            </x-slot:trigger>
            <x-slot:content>
                <div data-rt-dropdown-keep-open>
                    <div class="ai-intake-panel__record-details-head"><strong>{{ $record->title ?: 'Eingang prüfen' }}</strong><button type="button" class="ai-intake-panel__record-info-button" aria-label="Eingangsdetails schließen" data-dropdown-initial-focus @click.stop="close(true)"><i class="far fa-times" aria-hidden="true"></i></button></div>
                    <p class="ai-intake-panel__record-details-meta">{{ $recordLabel }} · {{ $statusLabel }}</p>
                    @if(trim((string) $record->summary) !== '')<p class="ai-intake-panel__record-summary">{{ $record->summary }}</p>@endif
                </div>
            </x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
    </header>
    <x-operations.panel.tabs class="ai-intake-panel__detail-tabs" label="Eingang prüfen" :id-prefix="'intake-'.$componentId" :action="$livewire ? 'setDetailTab' : null" model="detailTab" :active="$activeTab"
        :tabs="['review'=>['label'=>'Prüfen','icon'=>'far fa-tasks'],'sources'=>['label'=>'Original','icon'=>'far fa-file-alt'],'communication'=>['label'=>'Postausgang','icon'=>'far fa-paper-plane'],'assistant'=>['label'=>'AI-Fragen','icon'=>'far fa-comment-alt']]" />
</div>
