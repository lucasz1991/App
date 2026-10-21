<div class="ai-intake-panel__filters rt-floating-toolbar" role="search" aria-label="AI-Eingänge filtern">
    <x-ui.forms.search context="floating" :id="'ai-intake-search-'.$this->getId()" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Eingänge suchen" :status="$intakes->total()" :status-label="$intakes->total().' '.($intakes->total() === 1 ? 'Eingang' : 'Eingänge')" />
    <x-ui.dropdown.anchor-dropdown align="right" width="64" :offset="6" :dropdown-id="'ai-intake-status-'.$this->getId()" layer-group="ai-intake-controls" content-label="Bearbeitungsstand">
        <x-slot:trigger>
            <button type="button" class="ai-intake-panel__filter-trigger" aria-haspopup="menu" aria-label="AI-Eingänge filtern: {{ $labels[$statusFilter] ?? 'Alle Zustände' }}" title="AI-Eingänge filtern"><i class="far fa-sliders-h" aria-hidden="true"></i></button>
        </x-slot:trigger>
        <x-slot:content>
            @foreach(['all' => 'Alle Zustände', ...$labels] as $value => $label)
                <x-ui.dropdown.dropdown-link role="menuitemradio" aria-checked="{{ $statusFilter === $value ? 'true' : 'false' }}" wire:click="$set('statusFilter', '{{ $value }}')" x-on:click="close()">
                    <i class="{{ ['all'=>'far fa-layer-group','received'=>'far fa-inbox','analyzing'=>'far fa-cog','review'=>'far fa-search','waiting_customer'=>'far fa-reply','ready'=>'far fa-check-circle','paused'=>'far fa-pause','not_inquiry'=>'far fa-ban','failed'=>'far fa-exclamation-circle','completed'=>'far fa-check'][$value] ?? 'far fa-inbox' }}" aria-hidden="true"></i><span>{{ $label }}</span>
                    @if($statusFilter === $value)<i class="far fa-check ml-auto" aria-hidden="true"></i>@endif
                </x-ui.dropdown.dropdown-link>
            @endforeach
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
    <button type="button" class="ai-intake-panel__new" wire:click="openCapture" aria-label="Eingang erfassen" title="Eingang erfassen"><i class="far fa-plus" aria-hidden="true"></i></button>
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
