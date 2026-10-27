@props([
    'teams',
    'replacementTeams',
    'replacementRequired' => 0,
    'operation' => 'add',
])

<div class="min-w-0 space-y-4" data-employee-bulk-team-fields>
    <div>
        <x-ui.forms.label for="employee-bulk-team-operation" value="Aktion" />
        <x-ui.forms.select id="employee-bulk-team-operation" wire:model.live="operation">
            <option value="add">Teams hinzufügen</option>
            <option value="remove">Teams entfernen</option>
        </x-ui.forms.select>
        <x-input-error for="operation" class="mt-1" />
    </div>
    <fieldset class="min-w-0">
        <legend class="text-sm font-semibold">Teams auswählen</legend>
        <p id="employee-bulk-teams-hint" class="mb-3 mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            {{ $operation === 'remove' ? 'Diese Teams aus den Zuordnungen der ausgewählten Mitarbeiter entfernen.' : 'Diese Teams zu den bisherigen Zuordnungen der ausgewählten Mitarbeiter hinzufügen.' }}
        </p>
        <div class="scroll-container max-h-48 space-y-1 overflow-y-auto rounded-xl border border-rt-border bg-rt-surface-muted p-3 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
            @forelse($teams as $team)
                <div class="min-w-0" wire:key="employee-bulk-team-{{ $team->id }}">
                    <x-ui.forms.checkbox :id="'employee-bulk-team-'.$team->id" :value="$team->id" wire:model.live="teamIds" :label="$team->name" aria-describedby="employee-bulk-teams-hint" />
                </div>
            @empty
                <p class="py-3 text-sm text-rt-muted dark:text-rt-dark-muted">Keine bearbeitbaren Teams verfügbar.</p>
            @endforelse
        </div>
        <x-input-error for="teamIds" class="mt-2" />
        <x-input-error for="teamIds.*" class="mt-1" />
    </fieldset>
    @if($replacementRequired > 0)
        <div class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border" data-employee-bulk-primary>
            <x-ui.forms.label for="employee-bulk-primary-team" value="Neues primäres Team" />
            <p id="employee-bulk-primary-hint" class="mb-2 mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Für {{ $replacementRequired }} Mitarbeiter ist ein neues primäres Team erforderlich. Die primären Teams der anderen Mitarbeiter bleiben unverändert.</p>
            @if($replacementTeams->isNotEmpty())
                <x-ui.forms.select id="employee-bulk-primary-team" wire:model="replacementTeamId" placeholder="Verbleibendes Team auswählen" aria-describedby="employee-bulk-primary-hint">
                    @foreach($replacementTeams as $team)
                        <option value="{{ $team->id }}">{{ $team->name }}{{ $team->personal_team ? ' · persönlich' : '' }}</option>
                    @endforeach
                </x-ui.forms.select>
            @else
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Kein gemeinsames verbleibendes Team verfügbar. Zuerst ein gemeinsames Team hinzufügen oder die Mitarbeiter getrennt bearbeiten.</p>
            @endif
            <x-input-error for="replacementTeamId" class="mt-2" />
        </div>
    @endif
    <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Andere Zuordnungen, persönliche Teams und Eigentümerschaften bleiben erhalten.</p>
</div>
