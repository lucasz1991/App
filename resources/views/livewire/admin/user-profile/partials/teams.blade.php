@php
    $primaryTeam = $user->currentTeam;
    $memberships = $user->allTeams()->unique('id');
@endphp
<div class="min-w-0" data-employee-team-profile>
    <section class="min-w-0 rounded-xl border border-rt-border bg-rt-surface p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface" aria-label="Teamzuordnung">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold">Teamzuordnung</h3>
            <x-ui.buttons.button-basic type="button" wire:click="openTeamChange" class="min-h-11">
                <i class="far fa-users" aria-hidden="true"></i>Team wechseln
            </x-ui.buttons.button-basic>
        </div>
        <dl class="mt-4 space-y-3 text-sm">
            <div>
                <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Primäres Team</dt>
                <dd class="mt-1 break-words font-semibold">{{ $primaryTeam?->name ?? 'Kein primäres Team' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">Weitere Mitgliedschaften</dt>
                <dd class="mt-1 flex flex-wrap gap-2">
                    @forelse($memberships->reject(fn ($team) => (int) $team->id === (int) $primaryTeam?->id) as $team)
                        <span class="break-words rounded-lg bg-rt-surface-muted px-2 py-1 dark:bg-rt-dark-surface-muted">{{ $team->name }}{{ $team->personal_team ? ' · persönlich' : '' }}</span>
                    @empty
                        <span class="text-rt-muted dark:text-rt-dark-muted">Keine weiteren Mitgliedschaften</span>
                    @endforelse
                </dd>
            </div>
        </dl>
        <p class="mt-4 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Beim Teamwechsel bleiben weitere Mitgliedschaften erhalten.</p>
    </section>
</div>
