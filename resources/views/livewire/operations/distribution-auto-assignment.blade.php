<div class="rt-distribution-auto" data-distribution-auto-content>
    <div class="rt-distribution-auto__body">
        <p class="rt-distribution-auto__period"><i class="far fa-calendar-day" aria-hidden="true"></i>{{ \Carbon\CarbonImmutable::parse($from)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($until)->format('d.m.Y') }}</p>
        <p class="rt-distribution-auto__note">{{ $customerLabel ?? ($customerFilter === 'all' ? 'Alle Kunden' : 'Ausgewählter Kunde') }}</p>
        @error('autoAssignment')<p class="rt-shift-distribution__error" role="alert">{{ $message }}</p>@enderror

        @if(! $available)
            <p class="rt-distribution-auto__intro" role="status">Die automatische Zuordnung ist noch nicht verfügbar.</p>
            <p class="rt-distribution-auto__note">Die Voraussetzungen für Planung, Besetzung und Mitarbeiterwünsche müssen eingerichtet sein. Einzelne Schichten lassen sich weiterhin über die Schichtliste bearbeiten.</p>
        @elseif($outcome !== [])
            <p class="rt-distribution-auto__intro" role="status">{{ $outcome['message'] }}</p>
            <div class="rt-distribution-auto__summary">
                <span data-suggested><strong>{{ $outcome['assigned'] }}</strong>Angefragt</span>
                <span><strong>{{ $outcome['remaining_slots'] }}</strong>Weiter offen</span>
            </div>
            <p class="rt-distribution-auto__note">Die Zuordnungen sind als „Angefragt“ gespeichert. Bestätigung und Veröffentlichung erfolgen weiterhin separat.</p>
        @elseif($prepared)
            <p class="rt-distribution-auto__intro">{{ $preview['summary']['planned_slots'] > 0 ? 'Diese Zuordnungen sind vorbereitet.' : 'Aktuell sind keine geeigneten Zuordnungen möglich.' }}</p>
            <div class="rt-distribution-auto__summary" aria-label="Ergebnis der Vorschau">
                <span><strong>{{ $preview['summary']['open_slots'] }}</strong>Offene Plätze</span>
                <span data-suggested><strong>{{ $preview['summary']['planned_slots'] }}</strong>Vorgeschlagen</span>
                <span><strong>{{ $preview['summary']['remaining_slots'] }}</strong>Weiter offen</span>
            </div>
            <p class="rt-distribution-auto__note">Gesamter gewählter Zeitraum · nicht nur die aktuelle Listenseite. Noch wurde niemand zugeordnet.</p>
            @if($preview['summary']['omitted_shifts'] > 0)
                <p class="rt-distribution-auto__note">{{ $preview['summary']['omitted_shifts'] }} bereits begonnene oder geschlossene Schichten werden nicht automatisch besetzt.</p>
            @endif
            @if(! $preview['valid'] && $preview['rows'] !== [])
                <p class="rt-shift-distribution__error" role="alert">Die Vorschau kann so nicht übernommen werden. Bitte die Hinweise prüfen und neu berechnen.</p>
            @endif
            <ol class="rt-distribution-auto__rows" aria-label="Vorbereitete Zuordnungen">
                @foreach($preview['rows'] as $row)
                    <li wire:key="auto-assignment-{{ $row['shift_id'] }}-{{ $row['user_id'] }}">
                        <strong>{{ $row['title'] }}</strong>
                        <span>{{ $row['period'] }}</span>
                        <b><i class="far fa-user-check" aria-hidden="true"></i> {{ $row['name'] }}</b>
                        @if(filled($row['reasons'] ?? []))<span>{{ collect($row['reasons'])->implode(' · ') }}</span>@endif
                        @foreach(array_merge($row['issues'] ?? [], $row['schedule_issues'] ?? []) as $issue)
                            <span class="rt-shift-distribution__error">{{ is_array($issue) ? ($issue['message'] ?? $issue['label'] ?? 'Planung muss erneut geprüft werden.') : $issue }}</span>
                        @endforeach
                    </li>
                @endforeach
            </ol>
            @if($preview['remaining'] !== [])
                <details class="rt-distribution-auto__remaining" @if($preview['rows'] === []) open @endif>
                    <summary>{{ $preview['summary']['remaining_slots'] }} Plätze bleiben offen</summary>
                    <ul>
                        @foreach($preview['remaining'] as $remaining)
                            <li><strong>{{ $remaining['title'] }}</strong><span>{{ $remaining['period'] }} · {{ $remaining['slots'] }} frei</span><span>{{ $remaining['reason'] }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @else
            <p class="rt-distribution-auto__intro">Offene Plätze gemeinsam besetzen.</p>
            <ul class="rt-distribution-auto__rules">
                <li><i class="far fa-calendar-check" aria-hidden="true"></i><span>Alle zukünftigen offenen Schichten im gewählten Zeitraum und Kundenfilter berücksichtigen.</span></li>
                <li><i class="far fa-user-shield" aria-hidden="true"></i><span>Qualifikationen, Verfügbarkeit, Ruhezeiten, Kapazität und Wünsche prüfen. Bestehende Besetzungen bleiben erhalten.</span></li>
                <li><i class="far fa-check-circle" aria-hidden="true"></i><span>Erst die Vorschau prüfen. Nur mit „Übernehmen“ werden die Zuordnungen als „Angefragt“ gespeichert.</span></li>
            </ul>
            <p class="rt-distribution-auto__note">Ungeeignete Plätze bleiben offen. Es wird nichts automatisch bestätigt oder veröffentlicht.</p>
            <p class="rt-distribution-auto__note">Bis zu 100 offene Schichten und 200 neue Plätze pro Vorschau. Bei größeren Mengen bitte den Zeitraum oder Kunden eingrenzen.</p>
        @endif
    </div>

    @if($available)
        <div class="rt-distribution-auto__actions">
            <p class="rt-distribution-auto__loading" wire:loading.flex wire:target="prepare,apply" role="status" style="display: none"><i class="far fa-spinner fa-spin" aria-hidden="true"></i><span>Zuordnungen werden geprüft …</span></p>
            @if($canApply)
                <x-ui.buttons.button-basic type="button" mode="primary" wire:click="apply" wire:loading.attr="disabled" wire:target="prepare,apply,resetPreview" data-distribution-auto-apply><i class="far fa-check" aria-hidden="true"></i>{{ $preview['summary']['planned_slots'] }} Zuordnungen übernehmen</x-ui.buttons.button-basic>
                <p class="rt-distribution-auto__note">Übernimmt die Vorschau als „Angefragt“. Alle Bedingungen werden erneut geprüft.</p>
            @endif
            <x-ui.buttons.button-basic type="button" :mode="$canApply ? 'secondary' : 'primary'" wire:click="prepare" wire:loading.attr="disabled" wire:target="prepare,apply,resetPreview" data-distribution-auto-prepare><i class="far {{ $prepared || $outcome !== [] ? 'fa-redo' : 'fa-magic' }}" aria-hidden="true"></i>{{ $prepared || $outcome !== [] ? 'Neu berechnen' : 'Vorschläge berechnen' }}</x-ui.buttons.button-basic>
            @if($prepared)
                <x-ui.buttons.button-basic type="button" mode="link" wire:click="resetPreview" wire:loading.attr="disabled" wire:target="prepare,apply,resetPreview">Vorschau verwerfen</x-ui.buttons.button-basic>
            @endif
        </div>
    @endif
</div>
