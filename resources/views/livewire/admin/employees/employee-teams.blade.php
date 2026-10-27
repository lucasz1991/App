<section class="min-w-0 rounded-xl border border-rt-border bg-rt-surface p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface" aria-label="Teamzuordnung" data-employee-team-memberships>
    @if($canManage)
        <form wire:submit="save" class="space-y-5">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold">Teamzuordnung</h3>
                    <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Teams für {{ $employee->name }} verwalten.</p>
                </div>
                <i class="far fa-users text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
            </header>
            <x-input-error for="snapshot" class="mt-2" />
            <x-admin.employee-team-fields :teams="$teams" :fixed-teams="$fixedTeams" :primary-teams="$primaryTeams" :id-prefix="'employee-profile-teams-'.$userId" />
            <div class="flex flex-wrap justify-end gap-2 border-t border-rt-border pt-4 dark:border-rt-dark-border">
                <x-ui.buttons.button-basic type="button" wire:click="reload" wire:loading.attr="disabled" wire:target="save,reload" class="min-h-11">Zurücksetzen</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled" wire:target="save,reload" class="min-h-11">
                    <i class="far fa-check" wire:loading.remove wire:target="save" aria-hidden="true"></i>
                    <i class="far fa-spinner fa-spin" wire:loading wire:target="save" aria-hidden="true"></i>
                    Teams speichern
                </x-ui.buttons.button-basic>
            </div>
        </form>
    @else
        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Keine Berechtigung zur Teamzuordnung.</p>
    @endif
</section>
