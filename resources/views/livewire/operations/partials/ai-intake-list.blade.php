<div class="ai-intake-panel__filters" role="search" aria-label="AI-Eingänge filtern">
    <label class="ai-intake-panel__search"><i class="far fa-search" aria-hidden="true"></i><span class="sr-only">AI-Eingang suchen</span>
        <input type="search" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Eingänge suchen" /></label>
    <label class="sr-only" for="intake-status-{{ $this->getId() }}">Bearbeitungsstand</label>
    <x-ui.forms.select id="intake-status-{{ $this->getId() }}" class="ai-intake-panel__filter" wire:model.live="statusFilter" aria-label="Bearbeitungsstand">
        <option value="all" data-icon-class="far fa-layer-group">Alle Zustände</option>
        @foreach($labels as $value => $label)<option value="{{ $value }}" data-icon-class="{{ ['received'=>'far fa-inbox','analyzing'=>'far fa-cog','review'=>'far fa-search','waiting_customer'=>'far fa-reply','ready'=>'far fa-check-circle','paused'=>'far fa-pause','not_inquiry'=>'far fa-ban','failed'=>'far fa-exclamation-circle','completed'=>'far fa-check'][$value] ?? 'far fa-inbox' }}">{{ $label }}</option>@endforeach
    </x-ui.forms.select>
</div>
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
