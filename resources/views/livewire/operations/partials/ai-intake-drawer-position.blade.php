@php
    $payload = $proposal->payload ?? [];
    $currentProposal = $proposal->source_revision === $selected->source_revision && $selected->revision === $selectedRevision;
    $canCorrect = $currentProposal && !in_array($proposal->status, ['applied', 'stale'], true) && (!$proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true));
    $positionConfirmed = !empty($payload['review_confirmation']['actor_id']) && ($payload['review_confirmation']['source_revision'] ?? null) === $selected->source_revision;
    $directPath = ($payload['business_path'] ?? 'inquiry') === 'direct_order';
@endphp
<article class="rt-intake-drawer__position" wire:key="drawer-position-{{ $proposal->id }}-{{ $proposal->revision }}" data-intake-drawer-position="{{ $proposal->id }}">
    <div class="rt-intake-drawer__position-header"><h3>Position {{ $proposal->position_index + 1 }}</h3>@if($canCorrect && $manualReady && $editingProposalId !== $proposal->id)<x-ui.buttons.button-basic type="button" mode="link" wire:click="editProposal({{ $proposal->id }})" wire:loading.attr="disabled" aria-label="Position {{ $proposal->position_index + 1 }} im Formular bearbeiten"><i class="far fa-pen" aria-hidden="true"></i>Bearbeiten</x-ui.buttons.button-basic>@endif</div>
    <h4>{{ $payload['demand']['title'] ?? 'Leistung prüfen' }}</h4>
    <p class="rt-intake-drawer__meta">{{ ($payload['provenance']['kind'] ?? '') === 'manual' ? 'Im Formular erfasst' : (($payload['provenance']['kind'] ?? '') === 'native_inquiry' ? 'Aus bestehender Anfrage' : 'AI-Vorschlag') }} · {{ $positionConfirmed ? ($proposal->inquiry?->order_id ? 'Auftrag angelegt' : 'Als Anfrage übernommen') : 'noch nicht übernommen' }}</p>

    @if($editingProposalId === $proposal->id && $canCorrect)
        <div class="rt-intake-drawer__form">@include('livewire.operations.partials.ai-intake-position-form')</div>
    @else
        <x-operations.intake.facts class="rt-intake-drawer__facts" :demand="$payload['demand'] ?? []" variant="drawer" :assumptions="$payload['assumptions'] ?? []" :confirmed="$positionConfirmed" />
        <div class="rt-intake-drawer__business-path" data-business-path="{{ $directPath ? 'direct_order' : 'inquiry' }}"><span class="rt-intake-drawer__meta">Übernahme als</span><div>
            @if($canCorrect && $manualReady)<x-ui.buttons.button-basic type="button" wire:click="editProposal({{ $proposal->id }})" wire:loading.attr="disabled" :aria-label="'Übernahmeweg für Position '.($proposal->position_index + 1).' im Formular ändern'">{{ $directPath ? 'Direktauftrag' : 'Anfrage' }}<i class="far fa-chevron-down" aria-hidden="true"></i></x-ui.buttons.button-basic>@else<strong>{{ $directPath ? 'Direktauftrag' : 'Anfrage' }}</strong>@endif
            <p class="rt-intake-drawer__meta">{{ $directPath ? 'Ohne Angebot und gesonderte Kundenzusage' : 'Angebot und Kundenzusage folgen' }}</p>
        </div></div>
    @endif

    <details class="rt-intake-drawer__disclosure"><summary>Qualifikationen & Schichtabschnitte</summary><div class="rt-intake-drawer__disclosure-content">
        @if($payload['raw_requirements'] ?? [])<p><strong>Erkannte Anforderungen:</strong> {{ implode(', ', $payload['raw_requirements']) }}</p>@endif
        @if($payload['unmapped_requirements'] ?? [])<p class="ops-muted" role="status">Noch zu prüfen: {{ implode(', ', $payload['unmapped_requirements']) }}. Passende Qualifikationen auswählen und die Prüfung bestätigen.</p>@endif
        @if($payload['demand']['qualification_ids'] ?? [])<p><strong>Qualifikationen:</strong> {{ $qualifications->whereIn('id', $payload['demand']['qualification_ids'])->pluck('name')->implode(', ') }}</p>@else<p class="ops-muted">Keine Qualifikationen zugeordnet. Anforderungen im Original prüfen.</p>@endif
        @if($payload['segments'] ?? [])
            <p class="ops-muted">{{ count($payload['segments']) }} ausdrücklich erfasste Schichtabschnitte.</p>
            <ul>
                @foreach($payload['segments'] as $segment)
                    <li>{{ $segment['starts_at'] ?? 'Beginn offen' }} bis {{ $segment['ends_at'] ?? 'Ende offen' }} · {{ $segment['timezone'] ?? 'Zeitzone offen' }}
                        @if(isset($segment['planned_break_minutes']))
                            · {{ $segment['planned_break_minutes'] }} Min. Pause
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="ops-muted">Noch keine einzelnen Schichtabschnitte hinterlegt.</p>
        @endif
    </div></details>

    <details class="rt-intake-drawer__disclosure"><summary>Belege zu dieser Position</summary><div class="rt-intake-drawer__disclosure-content">
        @if(($payload['assumptions']['required_staff']['basis'] ?? '') === 'default')<p class="ops-muted" role="status">Die Personalanzahl ist im Original nicht genannt. Standardvorschlag: {{ $payload['demand']['required_staff'] ?? 'offen' }} Person(en) · {{ $positionConfirmed ? 'Persönlich bestätigt.' : 'Bitte bestätigen oder anpassen.' }}</p>@endif
        @if(!empty($payload['assumptions']['history_review']))<p class="ops-muted">Historische Vergleichsdaten verwendet. Diese sind keine Belege für die aktuelle Quelle.</p>@endif
        <dl>@forelse(($payload['evidence'] ?? []) as $field => $evidence)<div><dt>{{ is_array($evidence) ? ($evidence['field'] ?? $field) : $field }}</dt><dd>{{ is_array($evidence) ? ($evidence['quote'] ?? 'Quelle im Originaleingang prüfen.') : $evidence }}@if(is_array($evidence) && ($evidence['message_id'] ?? null))<span class="rt-intake-drawer__meta"> · Nachricht {{ $evidence['message_id'] }}</span>@endif</dd></div>@empty<p class="ops-muted">Originalnachrichten und Anlagen stehen unter „Original & Anlagen“ zur Prüfung bereit.</p>@endforelse</dl>
    </div></details>

    @if(!$currentProposal)<p class="rt-intake-drawer__notice" role="status">Ein neuerer Eingang liegt vor. Den aktuellen Vorschlag prüfen.</p>
    @elseif($proposal->error_code === 'reply_position_review')<p class="ops-muted">Diese Antwort muss zuerst einer vorhandenen Position zugeordnet werden.</p>
    @elseif($proposal->error_code === 'native_workflow_active')<p class="ops-muted">Diesen Vorgang in der bestehenden Anfrage prüfen; Angebot oder Beauftragung sind bereits angelaufen.</p>@endif
    @if($currentProposal && $proposal->error_code === 'reply_position_review')
        <form wire:submit="mapProposal({{ $proposal->id }},{{ $proposal->revision }})" class="ops-stack rt-intake-drawer__mapping"><x-ui.forms.label :for="'drawer-map-'.$this->getId().'-'.$proposal->id" value="Vorhandene Anfrage für diese Antwort" /><x-ui.forms.select :id="'drawer-map-'.$this->getId().'-'.$proposal->id" :wire:model="'mappingInquiryIds.'.$proposal->id"><option value="">Position ausdrücklich zuordnen</option>@foreach(($payload['candidate_inquiry_ids'] ?? []) as $candidateId)@if($candidate = $mappingInquiries->get($candidateId))<option value="{{ $candidate->id }}">{{ $candidate->number }} · {{ $candidate->title }} · {{ $candidate->location_name }}</option>@endif @endforeach</x-ui.forms.select><x-ui.forms.input-error :for="'mappingInquiryIds.'.$proposal->id" /><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Positionszuordnung bestätigen</x-ui.buttons.button-basic></form>
    @endif
    @if($proposal->inquiry)@include('livewire.operations.partials.ai-intake-planning')@endif
    @if($currentProposal && $canApprove && $selected->processing_mode !== 'manual' && in_array($proposal->status, ['approved', 'failed'], true))
        <details class="rt-intake-drawer__disclosure"><summary>Freigegebene Vorbereitung prüfen</summary><div class="rt-intake-drawer__disclosure-content"><x-ui.buttons.button-basic type="button" wire:click="retryPreparation({{ $proposal->id }},{{ $proposal->revision }})" wire:loading.attr="disabled">Vorbereitung erneut prüfen</x-ui.buttons.button-basic></div></details>
    @endif
</article>
