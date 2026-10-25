<form wire:submit="{{ $manualEditorOpen ? 'saveManualPosition' : 'saveProposal' }}" class="ops-stack mt-3" data-intake-position-form>
    <div class="ops-form">
        @foreach(['title' => 'Bezeichnung', 'role_name' => 'Tätigkeit', 'location_name' => 'Einsatzort', 'timezone' => 'Zeitzone'] as $key => $label)
            <x-operations.field :scope="$this->getId()" :label="$label" :model="'proposalForm.demand.'.$key" />
        @endforeach
        <x-operations.field :scope="$this->getId()" label="Beginn" model="proposalForm.demand.starts_at" type="datetime-local" />
        <x-operations.field :scope="$this->getId()" label="Ende" model="proposalForm.demand.ends_at" type="datetime-local" />
        <x-operations.field :scope="$this->getId()" label="Personalbedarf" model="proposalForm.demand.required_staff" type="number" min="1" max="999" />
        @unless($embedded)
            <x-operations.field :scope="$this->getId()" label="Übernahme als" model="proposalForm.business_path" type="select">
                <option value="inquiry">Anfrage mit Angebot / Kundenzusage</option>
                @can('operations.manage')<option value="direct_order">Auftrag direkt annehmen</option>@endcan
            </x-operations.field>
        @endunless
        <fieldset class="ops-full">
            <legend class="text-sm font-semibold">Erforderliche Qualifikationen</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @forelse($qualifications as $qualification)
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="proposalForm.demand.qualification_ids" value="{{ $qualification->id }}" class="mt-1 rounded border-rt-border text-rt-red focus:ring-rt-red" /><span>{{ $qualification->name }}</span></label>
                @empty<p class="ops-muted">Keine aktiven Qualifikationen hinterlegt.</p>@endforelse
            </div>
            @if($payload['raw_requirements'] ?? [])<label class="mt-3 flex items-start gap-2 text-sm"><input type="checkbox" wire:model="proposalForm.requirements_reviewed" class="mt-1 rounded border-rt-border text-rt-red focus:ring-rt-red" /><span>Ich habe die erkannten Anforderungen geprüft und den passenden Qualifikationen zugeordnet.</span></label>@endif
        </fieldset>
    </div>
    @foreach(($proposalForm['segments'] ?? []) as $index => $segment)
        <fieldset class="ops-form border-t border-rt-border pt-3 dark:border-rt-dark-border" wire:key="segment-{{ $this->getId() }}-{{ $editingProposalId ?? 'new' }}-{{ $index }}">
            <legend class="text-sm font-semibold">Schichtabschnitt {{ $index + 1 }}</legend>
            <x-operations.field :scope="$this->getId()" label="Beginn" :model="'proposalForm.segments.'.$index.'.starts_at'" type="datetime-local" />
            <x-operations.field :scope="$this->getId()" label="Ende" :model="'proposalForm.segments.'.$index.'.ends_at'" type="datetime-local" />
            <x-operations.field :scope="$this->getId()" label="Zeitzone" :model="'proposalForm.segments.'.$index.'.timezone'" />
            <x-operations.field :scope="$this->getId()" label="Personalbedarf" :model="'proposalForm.segments.'.$index.'.required_staff'" type="number" min="1" max="999" />
            <x-operations.field :scope="$this->getId()" label="Pause (Minuten)" :model="'proposalForm.segments.'.$index.'.planned_break_minutes'" type="number" min="0" />
            <x-ui.buttons.button-basic type="button" wire:click="removeSegment({{ $index }})">Abschnitt entfernen</x-ui.buttons.button-basic>
        </fieldset>
    @endforeach
    <div class="ops-actions">
        <x-ui.buttons.button-basic type="button" wire:click="addSegment">Abschnitt hinzufügen</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic type="button" wire:click="cancelPositionEdit">Abbrechen</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">{{ $embedded ? 'Korrektur speichern' : 'Position speichern' }}</x-ui.buttons.button-basic>
    </div>
    @unless($embedded)<p class="ops-muted">Speichern erhält den Entwurf. Beim anschließenden Bestätigen werden Anfragen übernommen oder die gewählten Direktaufträge angenommen.</p>@endunless
</form>
