<x-ui.dropdown.anchor-dropdown align="left" width="80" offset="6" :max-height="740"
    dropdown-id="timeline-filters-{{ $this->getId() }}" layer-group="operations-shift-plan"
    content-role="dialog" content-label="Zeitleiste filtern" dropdown-classes="rt-timeline-filter-dropdown"
    content-classes="rt-timeline-filters bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
    <x-slot:trigger>
        <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-timeline-filter-trigger"
            data-timeline-filter-trigger data-active="{{ $timelineFilterCount > 0 ? 'true' : 'false' }}"
            aria-label="Zeitleiste filtern{{ $timelineFilterCount ? ': '.$timelineFilterCount.' aktiv' : '' }}" title="Mitarbeiter und Schichten filtern">
            <i class="far fa-filter" aria-hidden="true"></i>
            <span class="rt-timeline-filter-trigger__label">Filter</span>
            @if($timelineFilterCount > 0)<span class="rt-timeline-filter-count" aria-hidden="true">{{ $timelineFilterCount }}</span>@endif
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <header class="rt-timeline-filters__header">
            <strong>Zeitleiste filtern</strong>
            <button type="button" class="rt-timeline-filters__close" x-on:click="close(true)" aria-label="Filter schließen"><i class="far fa-times" aria-hidden="true"></i></button>
        </header>
        <div class="rt-timeline-filters__body">
            <fieldset class="rt-timeline-filters__group">
                <legend><i class="far fa-users" aria-hidden="true"></i> Mitarbeiter</legend>
                <label for="timeline-filter-search-{{ $this->getId() }}">Name suchen</label>
                <x-ui.forms.input type="search" id="timeline-filter-search-{{ $this->getId() }}" wire:model.live.debounce.300ms="search"
                    maxlength="100" autocomplete="off" placeholder="Name eingeben …" aria-label="Mitarbeiter nach Name filtern" />
                <label for="timeline-filter-presence-{{ $this->getId() }}">Einteilung im gewählten Zeitraum</label>
                <x-ui.forms.select id="timeline-filter-presence-{{ $this->getId() }}" wire:model.live="staffPresence" aria-label="Mitarbeiter nach Einteilung filtern">
                    <option value="all">Alle Mitarbeiter</option>
                    <option value="assigned">Mit eingeplanten Schichten</option>
                    <option value="unassigned">Ohne eingeplante Schichten</option>
                </x-ui.forms.select>
            </fieldset>
            <fieldset class="rt-timeline-filters__group">
                <legend><i class="far fa-clock" aria-hidden="true"></i> Schichten</legend>
                <label for="timeline-filter-role-{{ $this->getId() }}">Schichtart / Funktion</label>
                <x-ui.forms.select id="timeline-filter-role-{{ $this->getId() }}" wire:model.live="shiftRole" aria-label="Schichten nach Funktion filtern">
                    <option value="all">Alle Funktionen</option>
                    @foreach($timelineRoleOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                </x-ui.forms.select>
                <div class="rt-timeline-filters__columns">
                    <div>
                        <label for="timeline-filter-status-{{ $this->getId() }}">Schichtstatus</label>
                        <x-ui.forms.select id="timeline-filter-status-{{ $this->getId() }}" wire:model.live="shiftStatus" aria-label="Schichten nach Status filtern">
                            <option value="all">Alle</option>
                            @foreach($timelineShiftStatusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                        </x-ui.forms.select>
                    </div>
                    <div>
                        <label for="timeline-filter-assignment-{{ $this->getId() }}">Zuweisung</label>
                        <x-ui.forms.select id="timeline-filter-assignment-{{ $this->getId() }}" wire:model.live="assignmentStatusFilter" aria-label="Schichten nach Zuweisung filtern">
                            <option value="all">Alle</option>
                            @foreach($timelineAssignmentStatusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                        </x-ui.forms.select>
                    </div>
                </div>
            </fieldset>
            <p class="rt-timeline-filters__hint">Filter ändern nur die Anzeige. Abwesenheiten und die vollständige Arbeitszeit bleiben sichtbar.</p>
            @if($errors->hasAny(['staffPresence', 'shiftRole', 'shiftStatus', 'assignmentStatusFilter']))
                <p class="text-xs text-rt-red dark:text-rt-dark-accent" role="alert">Dieser Filter ist nicht mehr verfügbar. Bitte eine andere Option wählen.</p>
            @endif
        </div>
        <footer class="rt-timeline-filters__footer">
            <x-ui.buttons.button-basic type="button" size="sm" mode="link" wire:click="resetTimelineFilters" wire:loading.attr="disabled" wire:target="resetTimelineFilters" :disabled="$timelineFilterCount === 0 ? true : null">
                <i class="far fa-rotate-left" aria-hidden="true"></i> Zurücksetzen
            </x-ui.buttons.button-basic>
            <span class="rt-timeline-filters__feedback" role="status" aria-live="polite">
                <span wire:loading.remove wire:target="search,staffPresence,shiftRole,shiftStatus,assignmentStatusFilter,resetTimelineFilters">{{ $timelineFilterCount ? $timelineFilterCount.' aktiv' : 'Keine Filter' }}</span>
                <span wire:loading wire:target="search,staffPresence,shiftRole,shiftStatus,assignmentStatusFilter,resetTimelineFilters">Wird gefiltert …</span>
            </span>
        </footer>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
