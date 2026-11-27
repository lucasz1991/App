<section class="ops-panel ops-stack"><h2>Tätigkeitszuordnungen</h2>
    @if($employee->profile?->position)<p>Firmenposition · {{ $employee->profile->position }}</p>@endif
    <ul>@forelse($assigned as $item)<li class="ops-actions" wire:key="assigned-{{ $item->id }}"><strong>{{ $item->title ?: $item->code }}</strong><span>{{ $item->code }}</span>@if(isset($qualificationState[$item->id]))<small>{{ $qualificationState[$item->id] }}</small>@endif
        @if($canEdit)<x-ui.buttons.button-basic type="button" wire:click="remove({{ $item->id }})" wire:confirm="Zuordnung aufheben?">Zuordnung aufheben</x-ui.buttons.button-basic>@endif</li>
    @empty<li>Keine bestätigte Tätigkeit.</li>@endforelse</ul>
    @if($reportedQualifications->isNotEmpty())<p>Aus Excel gemeldete Qualifikationen · {{ $reportedQualifications->implode(' · ') }}</p>@endif
    @if($canEdit && $available->isNotEmpty())
        @if($suggestions->isNotEmpty())<p>Vorschläge aus der Firmenposition · {{ $suggestions->map(fn ($a) => $a->title ?: $a->code)->implode(', ') }}</p>@endif
        @if($qualified->isNotEmpty())<p>Passend zu den geprüften Nachweisen · {{ $qualified->pluck('title')->implode(', ') }}</p>@endif
        <form wire:submit="assign" class="ops-actions"><x-operations.field label="Tätigkeit" type="select" model="activityId" required><option value="">Auswählen</option>@foreach($available as $item)<option value="{{ $item->id }}">{{ $item->code }} · {{ $item->title ?: 'Titel zu klären' }}</option>@endforeach</x-operations.field><x-ui.buttons.button-basic type="submit">Zuordnung bestätigen</x-ui.buttons.button-basic></form>
    @endif
</section>
