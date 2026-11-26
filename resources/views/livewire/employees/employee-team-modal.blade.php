<div {{ $isBulk ? 'data-employee-team-bulk-modal' : 'data-employee-team-modal' }}>
    <x-dialog-modal wire:model="showModal" :maxWidth="$isBulk ? '2xl' : 'lg'" :id="$isBulk ? 'employee-team-bulk-dialog' : 'employee-team-change-dialog'">
        <x-slot:title>{{ $isBulk ? 'Teams bearbeiten' : 'Team wechseln' }}</x-slot:title>
        <x-slot:content>
            @if($showModal && $canManage)
                @if($isBulk)
                <div class="min-w-0 space-y-4">
                    <details class="rounded-xl border border-rt-border px-3 dark:border-rt-dark-border">
                        <summary class="cursor-pointer py-3 text-sm font-semibold">{{ $employees->count() }} Mitarbeiter ausgewählt</summary>
                        <ul class="scroll-container mb-3 max-h-32 space-y-1 overflow-y-auto text-sm text-rt-muted dark:text-rt-dark-muted" aria-label="Ausgewählte Mitarbeiter">
                            @foreach($employees as $employee)
                                <li class="break-words" wire:key="bulk-team-employee-{{ $employee->id }}">{{ $employee->name }}</li>
                            @endforeach
                        </ul>
                    </details>
                    <x-ui.forms.input-error for="employeeIds" class="mt-2" />
                    <x-ui.forms.input-error for="snapshots" class="mt-2" />
                    <x-ui.forms.input-error for="snapshot" class="mt-2" />
                    <x-admin.employee-bulk-team-fields :teams="$teams" :replacement-teams="$replacementTeams" :replacement-required="$replacementRequired" :operation="$operation" />
                </div>
                @else
                <div class="space-y-4">
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted">
                        Primäres Team für {{ $employees->count() }} Mitarbeiter ändern.
                        Die Teamrechte des neuen Teams gelten nach dem Speichern.
                    </p>
                    <ul class="max-h-48 divide-y divide-rt-border overflow-y-auto rounded-xl border border-rt-border dark:divide-rt-dark-border dark:border-rt-dark-border" aria-label="Ausgewählte Mitarbeiter">
                        @foreach($employees as $employee)
                            <li class="flex min-w-0 flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="team-change-employee-{{ $employee->id }}">
                                <span class="min-w-0 break-words text-sm font-semibold">{{ $employee->name }}</span>
                                <span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $employee->currentTeam?->name ?? 'Kein primäres Team' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div>
                        <x-ui.forms.label for="employee-destination-team" value="Neues primäres Team" />
                        <x-ui.forms.select id="employee-destination-team" wire:model="teamId" placeholder="Team auswählen" class="mt-1" aria-describedby="employee-team-change-hint">
                            @foreach($teams as $team)
                                <option value="{{ $team->id }}">{{ $team->name }}</option>
                            @endforeach
                        </x-ui.forms.select>
                        <x-ui.forms.input-error for="teamId" class="mt-1" />
                    </div>
                    <p id="employee-team-change-hint" class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        Die bisherige primäre Teammitgliedschaft wird ersetzt. Weitere und persönliche Mitgliedschaften bleiben bestehen.
                        Globale Benutzerrolle und Kontostatus bleiben unverändert.
                    </p>
                    <x-ui.forms.input-error for="employeeIds" class="mt-1" />
                    <x-ui.forms.input-error for="teamChange" class="mt-1" />
                </div>
                @endif
            @endif
        </x-slot:content>
        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-2">
                @if($isBulk)
                <x-ui.buttons.button-basic type="button" wire:click="close" wire:loading.attr="disabled" wire:target="save" class="min-h-11">Abbrechen</x-ui.buttons.button-basic>
                @else
                <x-ui.buttons.button-basic type="button" wire:click="close" class="min-h-11">Abbrechen</x-ui.buttons.button-basic>
                @endif
                @if($showModal && $canManage)
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="min-h-11">
                        <x-ui.loading.spinner wire:loading wire:target="save" />
                        {{ $isBulk ? 'Änderungen speichern' : 'Teamwechsel speichern' }}
                    </x-ui.buttons.button-basic>
                @endif
            </div>
        </x-slot:footer>
    </x-dialog-modal>
</div>
