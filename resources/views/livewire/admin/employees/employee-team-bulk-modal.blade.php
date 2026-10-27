<div data-employee-team-bulk-modal>
    <x-dialog-modal wire:model="showModal" maxWidth="2xl" id="employee-team-bulk-dialog">
        <x-slot:title>Teams bearbeiten</x-slot:title>
        <x-slot:content>
            @if($showModal && $canManage)
                <div class="min-w-0 space-y-4">
                    <details class="rounded-xl border border-rt-border px-3 dark:border-rt-dark-border">
                        <summary class="cursor-pointer py-3 text-sm font-semibold">{{ $employees->count() }} Mitarbeiter ausgewählt</summary>
                        <ul class="scroll-container mb-3 max-h-32 space-y-1 overflow-y-auto text-sm text-rt-muted dark:text-rt-dark-muted" aria-label="Ausgewählte Mitarbeiter">
                            @foreach($employees as $employee)
                                <li class="break-words" wire:key="bulk-team-employee-{{ $employee->id }}">{{ $employee->name }}</li>
                            @endforeach
                        </ul>
                    </details>
                    <x-input-error for="employeeIds" class="mt-2" />
                    <x-input-error for="snapshots" class="mt-2" />
                    <x-input-error for="snapshot" class="mt-2" />
                    <x-admin.employee-bulk-team-fields :teams="$teams" :replacement-teams="$replacementTeams" :replacement-required="$replacementRequired" :operation="$operation" />
                </div>
            @endif
        </x-slot:content>
        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-2">
                <x-ui.buttons.button-basic type="button" wire:click="close" wire:loading.attr="disabled" wire:target="save" class="min-h-11">Abbrechen</x-ui.buttons.button-basic>
                @if($showModal && $canManage)
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="min-h-11">
                        <i class="far fa-spinner fa-spin" wire:loading wire:target="save" aria-hidden="true"></i>
                        Änderungen speichern
                    </x-ui.buttons.button-basic>
                @endif
            </div>
        </x-slot:footer>
    </x-dialog-modal>
</div>
