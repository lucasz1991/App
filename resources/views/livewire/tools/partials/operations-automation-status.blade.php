@if(!empty($automation))
    @if($automation['messages']['available'])
        <p class="rt-ai-assist__note">Kundennachrichten: {{ $automation['messages']['drafts'] }} zur persönlichen Freigabe · {{ $automation['messages']['unknown'] }} Versandstände zu prüfen.</p>
    @endif
    @if($automation['staffing']['available'])
        @php
            $staffingState = match ($automation['staffing']['status'] ?? '') {
                'supervisor_not_authorized' => 'Verantwortliche Disposition nicht berechtigt',
                'schema_missing' => 'Datenbankerweiterung fehlt',
                default => ['off' => 'ausgeschaltet', 'assisted' => 'assistierter Modus konfiguriert', 'automatic' => 'automatische Anfragen konfiguriert'][$automation['staffing']['mode']] ?? 'Stand prüfen',
            };
            $requestCounts = $automation['staffing']['counts'] ?? [];
        @endphp
        <p class="rt-ai-assist__note">Personal-Anfragen: {{ $staffingState }}. {{ ($requestCounts['soliciting'] ?? 0) + ($requestCounts['awaiting_confirmation'] ?? 0) }} offene Anfragevorgänge · {{ $requestCounts['review'] ?? 0 }} zur Prüfung. Anfragen reservieren keinen Platz; Antworten und Einteilungen brauchen die native Freigabe.</p>
    @endif
@endif
