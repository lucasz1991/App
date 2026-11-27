                    <div class="rt-personnel-management rt-personnel-detail ops-stack">
                        <dl class="ops-meta">
                            <div><dt>Mitarbeiter</dt><dd>{{ $selected->user?->name }}</dd></div>
                            <div><dt>Monat</dt><dd>{{ $selected->month }} · {{ $selected->timezone }}</dd></div>
                            <div><dt>Abschlussstand</dt><dd><x-operations.status :value="$payrollExportOnly ? 'closed' : $selected->status" /></dd></div>
                            <div><dt>Zeitmeldungen</dt><dd>{{ count($closingSnapshot['entries'] ?? []) }} · {{ ($closingSnapshot['complete'] ?? false) ? 'vollständig geprüft' : 'Prüfung offen' }}</dd></div>
                        </dl>
                        @foreach($closingSnapshot['issues'] ?? [] as $issue)<p class="ops-errors">Zeit #{{ $issue['entry_id'] }} · {{ implode(' · ', $issue['issues']) }}</p>@endforeach
                        @if(!($closingSnapshot['completeness']['complete'] ?? true))
                            <p class="ops-muted">{{ count($closingSnapshot['completeness']['missing_shift_times'] ?? []) }} fehlende Dienste · {{ count($closingSnapshot['completeness']['missing_training_times'] ?? []) }} fehlende Schulungszeiten · {{ count($closingSnapshot['completeness']['unfinished_times'] ?? []) }} offene Zeiten</p>
                        @endif
                        @if($payrollExportOnly || $selected->status === 'closed')
                            @can('operations.time.export')
                                <div class="ops-actions">
                                    <x-ui.buttons.button-basic mode="primary" wire:click="exportPayroll(false)" wire:loading.attr="disabled">CSV Lohnvorbereitung</x-ui.buttons.button-basic>
                                    <x-ui.buttons.button-basic wire:click="exportPayroll(true)" wire:loading.attr="disabled">CSV Änderungen</x-ui.buttons.button-basic>
                                </div>
                            @endcan
                            @unless($payrollExportOnly)
                                <details class="rt-employee-form__more" wire:key="open-month-{{ $selected->id }}-{{ $selectedRevision }}">
                                    <summary>Monat kontrolliert öffnen</summary>
                                    <form wire:submit="act('reopen')" class="ops-stack">
                                        <x-operations.field label="Vermerk zur Wiedereröffnung" model="note" type="textarea" required minlength="10" maxlength="2000" />
                                        <p class="ops-muted">Mindestens 10 Zeichen. Die abgeschlossene Revision bleibt im Verlauf verfügbar.</p>
                                        <div class="ops-actions"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Kontrolliert öffnen</x-ui.buttons.button-basic></div>
                                    </form>
                                </details>
                            @endunless
                        @elseif($selected->status === 'prepared')
                            @if(!($closingSnapshot['complete'] ?? false))
                                <p class="ops-muted">Vor dem Abschluss alle offenen Punkte klären und den Monat neu prüfen.</p>
                            @elseif((int) $selected->prepared_by === auth()->id() || (int) $selected->user_id === auth()->id())
                                <p class="ops-muted">Eine zweite berechtigte Person schließt diesen Monat ab.</p>
                            @endif
                            <div class="ops-actions">
                                <x-ui.buttons.button-basic wire:click="act('prepare')" wire:loading.attr="disabled">Neu prüfen</x-ui.buttons.button-basic>
                                <x-ui.buttons.button-basic mode="primary" wire:click="act('close')" wire:loading.attr="disabled"
                                    :disabled="!($closingSnapshot['complete'] ?? false) || (int) $selected->prepared_by === auth()->id() || (int) $selected->user_id === auth()->id()">Vier-Augen-Abschluss</x-ui.buttons.button-basic>
                            </div>
                        @else
                            @if($correctionTimes->isNotEmpty())
                                <h3>Korrekturen</h3>
                                <x-operations.field label="Korrekturgrund für die ausgewählte Meldung" model="note" type="textarea" minlength="10" maxlength="2000" />
                                @foreach($correctionTimes as $time)
                                    <div class="rt-personnel-section-heading" wire:key="payroll-correction-{{ $time->id }}">
                                        <div><strong>{{ $time->plan_snapshot['title'] ?? $time->contextLabel() }}</strong><p>{{ $time->starts_at->format('d.m.Y') }} · Zeit #{{ $time->id }} · Revision {{ $time->revision }}</p></div>
                                        <x-ui.buttons.button-basic wire:click="returnTime({{ $time->id }},{{ $time->revision }})" wire:loading.attr="disabled">Zur Korrektur zurückgeben</x-ui.buttons.button-basic>
                                    </div>
                                @endforeach
                            @endif
                            <div><x-ui.buttons.button-basic mode="primary" wire:click="act('prepare')" wire:loading.attr="disabled">Erneut vorbereiten</x-ui.buttons.button-basic></div>
                        @endif
                        @if($closedVersions->isNotEmpty() && auth()->user()->can('operations.time.export'))
                            <details><summary>Abschlussversionen</summary><div class="ops-actions">
                                @foreach($closedVersions as $version)<x-ui.buttons.button-basic wire:click="exportPayroll(false,{{ $version->revision }})" wire:loading.attr="disabled">CSV Revision {{ $version->revision }}</x-ui.buttons.button-basic>@endforeach
                            </div></details>
                        @endif
                    </div>
