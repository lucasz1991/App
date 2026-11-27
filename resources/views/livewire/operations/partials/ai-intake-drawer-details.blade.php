{{-- Formularbearbeitung, Reiter „Details“: Qualifikationen, Schichtabschnitte, Quellen und Prüfhinweise. --}}
<section class="rt-intake-drawer__body" id="intake-drawer-{{ $this->getId() }}-panel-details" role="tabpanel" aria-labelledby="intake-drawer-{{ $this->getId() }}-tab-details" @if($detailTab !== 'details') hidden @endif>
    @if($proposal = $currentProposals->firstWhere('id', $drawerState['activeProposalId']))
        @php
            $payload = $proposal->payload ?? [];
            $positionConfirmed = !empty($payload['review_confirmation']['actor_id']) && ($payload['review_confirmation']['source_revision'] ?? null) === $selected->source_revision;
            $directPath = ($payload['business_path'] ?? 'inquiry') === 'direct_order';
        @endphp
        <h3>{{ $payload['demand']['title'] ?? 'Leistung '.($proposal->position_index + 1) }}</h3>
        @if($catalogItem = ($activityCatalog ?? collect())->get(mb_strtolower(trim($payload['demand']['role_name'] ?? ''))))
            <dl class="ops-meta">
                <div><dt>Dienstnachweis</dt><dd>{{ $catalogItem->proof_type }}</dd></div>
                @if($catalogItem->bundle)<div><dt>Qualifikationsbündel</dt><dd>{{ $catalogItem->bundle->name }} · Version {{ $catalogItem->bundle->version }}</dd></div>@endif
            </dl>
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

        <details class="rt-intake-drawer__disclosure"><summary>Quellen und Prüfhinweise</summary><div class="rt-intake-drawer__disclosure-content">
            @if(($payload['assumptions']['required_staff']['basis'] ?? '') === 'default')<p class="ops-muted" role="status">Die Personalanzahl ist im Original nicht genannt. Standardvorschlag: {{ $payload['demand']['required_staff'] ?? 'offen' }} Person(en) · {{ $positionConfirmed ? 'Persönlich bestätigt.' : 'Bitte bestätigen oder anpassen.' }}</p>@endif
            @if(!empty($payload['assumptions']['history_review']))<p class="ops-muted">Historische Vergleichsdaten verwendet. Diese sind keine Belege für die aktuelle Quelle.</p>@endif
            @include('livewire.operations.partials.ai-intake-drawer-source-notes')
            @if($selected->contact)<p><strong>Kontakt:</strong> {{ $selected->contact->name }}</p>@endif
            <p class="ops-muted">{{ $payload['demand']['timezone'] ?? 'Europe/Berlin' }} · {{ ($payload['provenance']['kind'] ?? '') === 'manual' ? 'Im Formular erfasst' : 'Aus dem Eingang übernommen' }} · {{ $directPath ? 'Direktauftrag' : 'Anfrage' }}</p>
            <dl>@forelse(($payload['evidence'] ?? []) as $field => $evidence)<div><dt>{{ is_array($evidence) ? ($evidence['field'] ?? $field) : $field }}</dt><dd>{{ is_array($evidence) ? ($evidence['quote'] ?? 'Quelle im Originaleingang prüfen.') : $evidence }}@if(is_array($evidence) && ($evidence['message_id'] ?? null))<span class="rt-intake-drawer__meta"> · Nachricht {{ $evidence['message_id'] }}</span>@endif</dd></div>@empty<p class="ops-muted">Originalnachrichten und Anlagen stehen unter „Original & Anlagen“ zur Prüfung bereit.</p>@endforelse</dl>
        </div></details>
    @else
        <details class="rt-intake-drawer__disclosure"><summary>Quellen und Prüfhinweise</summary><div class="rt-intake-drawer__disclosure-content">
            @include('livewire.operations.partials.ai-intake-drawer-source-notes')
        </div></details>
    @endif
</section>
