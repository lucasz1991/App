<form wire:submit="createTeam" class="space-y-2 border-b border-rt-border/60 px-4 pb-4 dark:border-rt-dark-border/60 sm:px-5" data-team-create-form>
    <x-ui.forms.label for="team-rbac-new-name" value="Neues Team" />
    <div class="flex min-w-0 items-start gap-2">
        <div class="min-w-0 flex-1">
            <x-ui.forms.input id="team-rbac-new-name" type="text" wire:model="newTeamName" maxlength="255" placeholder="Teamname" autocomplete="off" aria-describedby="team-rbac-new-hint" />
            <x-input-error for="newTeamName" class="mt-1" />
        </div>
        <x-ui.buttons.button-basic type="submit" mode="primary" class="h-11 w-11 shrink-0 px-0" wire:loading.attr="disabled" wire:target="createTeam" title="Team erstellen" aria-label="Team erstellen">
            <i class="far fa-plus" wire:loading.remove wire:target="createTeam" aria-hidden="true"></i>
            <i class="far fa-spinner fa-spin" wire:loading wire:target="createTeam" aria-hidden="true"></i>
        </x-ui.buttons.button-basic>
    </div>
    <p id="team-rbac-new-hint" class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Neue Teams starten ohne freigegebene Rechte. Anschließend Rechte auswählen und speichern.</p>
</form>
