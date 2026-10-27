@props([
    'teams',
    'fixedTeams',
    'primaryTeams',
    'teamIdsModel' => 'teamIds',
    'primaryModel' => 'primaryTeamId',
    'idPrefix' => 'employee-teams',
])

<div class="grid min-w-0 gap-5 md:grid-cols-2" data-employee-team-fields>
    <fieldset class="min-w-0">
        <legend class="text-sm font-semibold">Teams zuweisen</legend>
        <p id="{{ $idPrefix }}-membership-hint" class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Mehrere Teams auswählen oder Häkchen entfernen. Änderungen mit Speichern übernehmen.</p>
        <div class="scroll-container mt-3 max-h-60 space-y-1 overflow-y-auto rounded-xl border border-rt-border bg-rt-surface-muted p-3 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
            @forelse($teams as $team)
                <div class="min-w-0" wire:key="{{ $idPrefix }}-membership-{{ $team->id }}">
                    <x-ui.forms.checkbox :id="$idPrefix.'-team-'.$team->id" :value="$team->id" wire:model.live="{{ $teamIdsModel }}" :label="$team->name" :aria-describedby="$idPrefix.'-membership-hint'" />
                </div>
            @empty
                <p class="py-3 text-sm text-rt-muted dark:text-rt-dark-muted">Keine weiteren Teams verfügbar.</p>
            @endforelse
        </div>
        <x-input-error :for="$teamIdsModel" class="mt-2" />
        <x-input-error :for="$teamIdsModel.'.*'" class="mt-1" />
    </fieldset>

    <div class="min-w-0 space-y-4">
        <div>
            <x-ui.forms.label :for="$idPrefix.'-primary'" value="Primäres Team" />
            <p id="{{ $idPrefix }}-primary-hint" class="mb-2 mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Bestimmt den aktiven Arbeitsbereich. Vor dem Entfernen dieses Teams ein anderes zugewiesenes Team wählen.</p>
            <x-ui.forms.select :id="$idPrefix.'-primary'" wire:model.live="{{ $primaryModel }}" placeholder="Primäres Team auswählen" :aria-describedby="$idPrefix.'-primary-hint'">
                @foreach($primaryTeams as $team)
                    <option value="{{ $team->id }}">{{ $team->name }}{{ $team->personal_team ? ' · persönlich' : '' }}</option>
                @endforeach
            </x-ui.forms.select>
            <x-input-error :for="$primaryModel" class="mt-2" />
        </div>

        @if($fixedTeams->isNotEmpty())
            <div>
                <h4 class="text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">Weitere Mitgliedschaften</h4>
                <div class="mt-2 flex flex-wrap gap-2" data-employee-fixed-teams>
                    @foreach($fixedTeams as $team)
                        <span class="inline-flex min-w-0 items-center gap-2 rounded-lg bg-rt-surface-muted px-3 py-2 text-xs dark:bg-rt-dark-surface-muted">
                            <i class="far fa-lock shrink-0 text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                            <span class="break-words">{{ $team->name }} · {{ $team->personal_team ? 'persönlich' : 'Eigentümer' }}</span>
                        </span>
                    @endforeach
                </div>
                <p class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Persönliche Teams und Eigentümerschaften bleiben erhalten.</p>
            </div>
        @endif
    </div>
</div>
