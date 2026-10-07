<section class="ops-stack" aria-label="AI-Besetzungsvorschlag">
    <p class="ops-muted text-sm">Die AI vergleicht geprüfte Kandidaten und erklärt Alternativen. Jede Besetzung wird von Ihnen freigegeben.</p>
    @if(!$available)
        <p class="ops-muted" role="status">Die Dispositions-AI ist deaktiviert oder noch nicht eingerichtet. Die normale Besetzung bleibt verfügbar.</p>
    @else
        <div class="ops-actions">
            <x-ui.buttons.button-basic type="button" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze,saveVariant,applyDraft,confirmPublished"><i class="far fa-sparkles" aria-hidden="true"></i> {{ $proposal ? 'Neu analysieren' : 'Besetzung analysieren' }}</x-ui.buttons.button-basic>
            <span class="ops-muted text-xs" wire:loading wire:target="analyze" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i> Vorschläge werden geprüft …</span>
        </div>
    @endif
    @error('aiPlanning')<p class="rt-staffing-alert" role="alert">{{ $message }}</p>@enderror
    @if($proposal)
        <p class="text-sm text-rt-text dark:text-rt-dark-text">{{ $proposal['summary'] }}</p>
        @foreach($proposal['warnings'] as $warning)<p class="ops-muted text-sm">{{ $warning }}</p>@endforeach
        @forelse($proposal['recommendations'] as $index => $row)
            <div class="border-b border-rt-border dark:border-rt-dark-border py-3 ops-stack" wire:key="ai-planning-row-{{ $proposal['token'] }}-{{ $index }}">
                @if($shiftId === null)<div><strong class="text-sm">{{ $row['shift_title'] }}</strong><p class="ops-muted text-xs">{{ $row['period'] }} · {{ $row['published'] ? 'Veröffentlicht' : 'Entwurf' }}</p></div>@endif
                <p class="ops-muted text-sm">{{ $row['reason'] }}</p>
                @forelse($row['people'] as $person)
                    <div class="ops-actions justify-between">
                        <div class="min-w-0"><strong class="text-sm break-words">{{ $person['name'] }}</strong><p class="ops-muted text-xs">{{ implode(' · ', $person['notes']) }}</p></div>
                        @if($shiftId !== null)
                            <x-ui.buttons.button-basic type="button" size="sm" wire:click="choose({{ $person['id'] }})" wire:loading.attr="disabled">Auswahl prüfen</x-ui.buttons.button-basic>
                        @elseif($row['published'])
                            @if(isset($publishedApplied[$row['shift_id'].':'.$person['id']]))<span class="ops-muted text-xs" role="status">Besetzung angefragt</span>@else
                                <x-ui.buttons.button-basic type="button" size="sm" wire:click="confirmPublished({{ $row['shift_id'] }}, {{ $person['id'] }})" wire:confirm="Diese Person zusätzlich für die veröffentlichte Schicht anfragen? Bestehende Besetzungen bleiben erhalten." wire:loading.attr="disabled">Besetzung anfragen</x-ui.buttons.button-basic>
                            @endif
                        @else<span class="ops-muted text-xs">{{ $draftApplied ? 'Besetzung angefragt' : 'Zur Entwurfsvariante' }}</span>@endif
                    </div>
                @empty<p class="ops-muted text-xs">Der Platz bleibt zur manuellen Klärung offen.</p>@endforelse
            </div>
        @empty<p class="ops-muted">Keine zusätzlichen Besetzungen vorgeschlagen.</p>@endforelse
        @if($shiftId === null && collect($proposal['recommendations'])->contains(fn ($row) => !$row['published'] && count($row['user_ids']) > 0))
            <p class="ops-muted text-sm">Es werden nur die oben genannten Personen zusätzlich angefragt. Vorhandene Reservierungen, Schichtzeiten und Veröffentlichungen bleiben erhalten.</p>
            <div class="ops-actions">
                @if($draftApplied)<p class="text-sm" role="status">Zusätzliche Besetzungen angefragt. Die Entwürfe wurden nicht veröffentlicht.</p>
                @elseif($variantId)
                    <span class="ops-muted text-xs">Entwurfsvariante #{{ $variantId }} gespeichert</span>
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="applyDraft" wire:confirm="Die angezeigten zusätzlichen Personen für die Entwurfsdienste anfragen? Bestehende Besetzungen bleiben erhalten. Es wird nichts veröffentlicht." wire:loading.attr="disabled">Freigeben &amp; Besetzung anfragen</x-ui.buttons.button-basic>
                @else
                    <x-ui.buttons.button-basic type="button" wire:click="saveVariant" wire:loading.attr="disabled">Entwurfsvariante speichern &amp; prüfen</x-ui.buttons.button-basic>
                @endif
            </div>
        @endif
    @endif
</section>
