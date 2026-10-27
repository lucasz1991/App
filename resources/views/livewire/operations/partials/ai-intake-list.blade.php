<x-ui.floating.toolbar label="AI-Eingänge filtern" search>
    <x-ui.forms.search context="floating" :id="'ai-intake-search-'.$this->getId()" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Eingänge suchen" :status="$intakes->total()" :status-label="$intakes->total().' '.($intakes->total() === 1 ? 'Eingang' : 'Eingänge')" />
    <x-slot:actions>
    <x-ui.forms.select :id="'ai-intake-status-'.$this->getId()" wire:model.live="statusFilter" variant="icon" icon="far fa-sliders-h"
        aria-label="AI-Eingänge filtern: {{ $labels[$statusFilter] ?? 'Alle Zustände' }}" title="AI-Eingänge filtern">
        @foreach(['all' => 'Alle Zustände', ...$labels] as $value => $label)
            <option value="{{ $value }}" @selected($statusFilter === $value)
                data-icon-class="{{ ['all'=>'far fa-layer-group','received'=>'far fa-inbox','analyzing'=>'far fa-cog','review'=>'far fa-search','waiting_customer'=>'far fa-reply','ready'=>'far fa-check-circle','paused'=>'far fa-pause','not_inquiry'=>'far fa-ban','failed'=>'far fa-exclamation-circle','completed'=>'far fa-check'][$value] ?? 'far fa-inbox' }}">{{ $label }}</option>
        @endforeach
    </x-ui.forms.select>
    <button type="button" class="rt-floating-toolbar__button" wire:click="openCapture" aria-label="Eingang erfassen" title="Eingang erfassen"><i class="far fa-plus" aria-hidden="true"></i></button>
    </x-slot:actions>
</x-ui.floating.toolbar>
<div class="ai-intake-panel__list" x-ref="intakeList">
    @if($intakes->isEmpty())
        <p class="ops-muted" role="status">Noch keine AI-Eingänge für diese Auswahl.</p>
    @else
        <ol>
            @foreach($intakes as $intake)
                <li class="ai-intake-panel__row" wire:key="panel-ai-intake-{{ $intake->id }}">
                    <button type="button" class="ai-intake-panel__item" data-intake-id="{{ $intake->id }}" wire:click="select({{ $intake->id }})"
                        aria-label="{{ ($intake->title ?: 'Eingang #'.$intake->id).' prüfen' }}">
                        <span class="ai-intake-panel__item-copy"><strong>{{ $intake->title ?: 'Eingang #'.$intake->id }}</strong><small>{{ $intake->customer?->company_name ?? 'Kundenzuordnung prüfen' }}</small></span>
                        <span class="ai-intake-panel__item-meta"><x-operations.status :value="$intake->status" :label="$labels[$intake->status] ?? 'Prüfung nötig'" /><small>{{ $intake->created_at->timezone('Europe/Berlin')->format('d.m. H:i') }}</small></span>
                    </button>
                    <button type="button" class="ai-intake-panel__discuss" wire:click="discuss({{ $intake->id }})"
                        aria-label="{{ ($intake->title ?: 'Eingang #'.$intake->id).' mit AI besprechen' }}" title="{{ ($intake->title ?: 'Eingang #'.$intake->id).' mit AI besprechen' }}"><i class="far fa-comment-alt" aria-hidden="true"></i></button>
                </li>
            @endforeach
        </ol>
    @endif
    <div class="ai-intake-panel__pagination">{{ $intakes->links() }}</div>
</div>
