@php
    $inquiry = $proposal->inquiry;
    $shifts = collect($proposal->applied_shift_ids ?? [])->map(fn ($id) => $planningShifts->get($id))->filter();
    $allPublished = $shifts->isNotEmpty() && $shifts->every(fn ($shift) => $shift->published_revision === $shift->revision && !in_array($shift->status->value, ['draft','cancelled','completed'], true));
    $canPlan = $canApprove && $currentProposal && !$selected->paused_at && $selected->status !== 'paused';
    $step = $allPublished ? 3 : ($shifts->isNotEmpty() ? 2 : ($inquiry->order_id ? 1 : 0));
@endphp
<section class="mt-3 space-y-3" aria-label="Weiter zur Schichtbesetzung" data-ai-intake-planning>
    <ol class="rt-disposition-workflow-steps" aria-label="Bearbeitungsstand">
        @foreach([($proposal->payload['business_path'] ?? 'inquiry') === 'direct_order' ? 'Eingang' : 'Anfrage', 'Auftrag', 'Schichten', 'Besetzung'] as $label)
            <li class="rt-disposition-workflow-step" data-state="{{ $loop->index < $step ? 'done' : ($loop->index === $step ? 'current' : 'upcoming') }}" @if($loop->index === $step) aria-current="step" @endif>{{ $label }}</li>
        @endforeach
    </ol>
    @if(!$inquiry->order_id)
        <p class="ops-muted">Die Position ist mit Anfrage {{ $inquiry->number }} verknüpft. Wahlweise direkt als Auftrag annehmen oder im Anfrage- und Angebotsweg fortführen.</p>
        <div class="ops-actions">
            @if($canPlan)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="takeDirectOrder({{ $proposal->id }},{{ $proposal->revision }},{{ $inquiry->revision }})" wire:loading.attr="disabled">Auftrag direkt annehmen</x-ui.buttons.button-basic>@endif
            <x-ui.buttons.button-basic type="button" wire:click="continueInquiry({{ $proposal->id }},{{ $proposal->revision }})" wire:loading.attr="disabled">{{ $inquiry->status === 'accepted' ? 'Auftrag anlegen' : 'Anfrage / Angebot öffnen' }} <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
        </div>
    @elseif($proposal->status !== 'applied')
        <p class="ops-muted">Auftrag bestätigt. Schichtabschnitte und Pausen persönlich prüfen. Daraus werden zunächst unbesetzte Entwürfe erstellt.</p>
        @if($planningProposalId === $proposal->id)
            <form wire:submit="preparePlanning" class="ops-stack" data-ai-intake-planning-editor>
                <p class="ops-muted">{{ $inquiry->required_staff }} Person(en) · {{ $inquiry->timezone }}. Längere Zeiträume in passende Schichten aufteilen.</p>
                @foreach($planningSegments as $index => $segment)
                    <fieldset class="ops-form border-t border-rt-border pt-3" wire:key="planning-{{ $proposal->id }}-{{ $index }}">
                        <legend class="text-sm font-semibold">Schicht {{ $index + 1 }}</legend>
                        <x-operations.field :scope="$this->getId()" label="Beginn" :model="'planningSegments.'.$index.'.starts_at'" type="datetime-local" required />
                        <x-operations.field :scope="$this->getId()" label="Ende" :model="'planningSegments.'.$index.'.ends_at'" type="datetime-local" required />
                        <x-operations.field :scope="$this->getId()" label="Pause (Minuten, auch 0 ausdrücklich angeben)" :model="'planningSegments.'.$index.'.planned_break_minutes'" type="number" min="0" max="1440" required />
                        <x-ui.buttons.button-basic type="button" wire:click="removePlanningSegment({{ $index }})" wire:loading.attr="disabled" :aria-label="'Schicht '.($index + 1).' entfernen'">Entfernen</x-ui.buttons.button-basic>
                    </fieldset>
                @endforeach
                <div class="ops-actions">
                    <x-ui.buttons.button-basic type="button" wire:click="addPlanningSegment" wire:loading.attr="disabled" :disabled="count($planningSegments) >= 50 ? true : null">Schicht hinzufügen</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" wire:click="closePlanning" wire:loading.attr="disabled">Abbrechen</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Schichtentwürfe erstellen</x-ui.buttons.button-basic>
                </div>
            </form>
        @elseif($canPlan)
            <x-ui.buttons.button-basic type="button" mode="primary" wire:click="openPlanning({{ $proposal->id }},{{ $proposal->revision }})" wire:loading.attr="disabled">Schichten prüfen und vorbereiten</x-ui.buttons.button-basic>
        @endif
    @else
        <p class="ops-muted">{{ $allPublished ? 'Schichten sind freigegeben. Mitarbeiter im Schichtplan zuordnen.' : 'Schichtentwürfe erstellt. Einsatzort und Schichtdaten prüfen, anschließend zur Besetzung freigeben.' }}</p>
        @foreach($shifts as $shift)
            <x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases',['view'=>'shifts','section'=>'plan','shift'=>$shift->id,'from'=>$shift->starts_at->setTimezone($shift->timezone)->format('Y-m-d'),'until'=>$shift->starts_at->setTimezone($shift->timezone)->addDays(6)->format('Y-m-d'),'distribution'=>'true'])" data-no-navigate>Schicht {{ $shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i') }} · {{ $shift->published_revision === $shift->revision ? 'Besetzung öffnen' : 'Einsatzort und Entwurf prüfen' }} <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
        @endforeach
        @if(!$allPublished && $canPlan && $shifts->isNotEmpty())
            <x-ui.buttons.button-basic type="button" wire:click="select({{ $selected->id }})" wire:loading.attr="disabled">Prüfstand aktualisieren</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="button" mode="primary" wire:click="publishPlanning({{ $proposal->id }},{{ $proposal->revision }})" wire:loading.attr="disabled" wire:confirm="Diese Schichten nach Prüfung von Zeiten, Pausen und Einsatzort zur Besetzung freigeben?">Zur Besetzung freigeben</x-ui.buttons.button-basic>
        @endif
    @endif
    @if($inquiry->order_id && $canApprove)
        <x-ui.buttons.button-basic mode="link" :href="\App\Support\Operations\OperationsPages::url('cases',['view'=>'orders','section'=>'planning','order'=>$inquiry->order_id])" data-no-navigate>Auftragsplanung öffnen</x-ui.buttons.button-basic>
    @endif
</section>
