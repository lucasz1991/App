@php
    $sourceBreakdown = static function (array $position): string {
        $sources = $position['source_seconds'] ?? [];
        if (count($sources) < 2) {
            return '';
        }

        $labels = [];
        foreach ($sources as $code => $seconds) {
            $duration = sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
            if ($seconds % 60) {
                $duration .= sprintf(':%02d', $seconds % 60);
            }
            $labels[] = $code.' '.$duration;
        }

        return implode(' · ', $labels);
    };
@endphp
<section class="ops-stack rounded-xl border border-rt-border p-4 dark:border-rt-dark-border" aria-label="Lexware für Windows">
    <div>
        <h3 class="font-semibold">Lexware lohn+gehalt für Windows</h3>
        @if($lexware['available'] && $lexware['closing_revision'] === $selectedRevision)
            @if($lexware['run'])
                <p class="ops-muted">Diese Datei wurde bereits erstellt. Vor einem erneuten Import die bisherigen Buchungen in Lexware mit dem Exportverlauf abgleichen.</p>
            @else
                <p class="ops-muted">Die Datei in der angegebenen Windows-Firma importieren und das Ergebnis anschließend hier dokumentieren.</p>
            @endif
        @else
            <p class="ops-muted">{{ $lexware['reason'] ?: 'Die Abschlussrevision hat sich geändert. Bitte die Details erneut öffnen.' }}</p>
        @endif
    </div>

    @if($lexware['available'] && $lexware['closing_revision'] === $selectedRevision)
        <dl class="ops-meta">
            <div><dt>Zielfirma</dt><dd>{{ $lexware['company_name'] }} · {{ $lexware['employer_reference'] }}</dd></div>
            <div><dt>Personalnummer</dt><dd>{{ $lexware['personnel_number'] }}</dd></div>
            <div><dt>Exportgrundlage</dt><dd>Abschluss R{{ $selectedRevision }} · Profil V{{ $lexware['profile_version'] }}</dd></div>
        </dl>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">Vorschau der abgerechneten Stunden je Lexware-Lohnart</caption>
                <thead><tr class="text-left">
                    <th scope="col" class="p-2">Lohnart</th><th scope="col" class="p-2">RailTime-Quelle</th>
                    <th scope="col" class="p-2 text-right">Dauer (h:mm:ss)</th><th scope="col" class="p-2 text-right">Export (Std.)</th><th scope="col" class="p-2 text-right">Rundung (Sek.)</th>
                </tr></thead>
                <tbody>
                    @foreach($lexware['rows'] as $row)
                        <tr class="border-t border-rt-border dark:border-rt-dark-border">
                            <th scope="row" class="p-2 text-left align-top">{{ $row['target_code'] }}<span class="ops-muted block">{{ $row['quantity_kind'] === 'base' ? 'Grundstunden' : 'Zuschlagsstunden' }}</span></th>
                            <td class="p-2">{{ $sourceBreakdown($row) ?: implode(', ', $row['source_codes']) }}</td>
                            <td class="p-2 text-right tabular-nums">{{ sprintf('%d:%02d:%02d', intdiv($row['raw_seconds'], 3600), intdiv($row['raw_seconds'] % 3600, 60), $row['raw_seconds'] % 60) }}</td>
                            <td class="p-2 text-right font-semibold tabular-nums">{{ $row['value'] }}</td>
                            @php($roundingSeconds = ((int) str_replace(',', '', $row['value'])) * 36 - $row['raw_seconds'])
                            <td class="p-2 text-right tabular-nums">{{ $roundingSeconds > 0 ? '+' : '' }}{{ $roundingSeconds }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($lexware['skipped_zero'])
            <p class="ops-muted">Ausgelassene Nullmengen oder unbezahlte Pausen: {{ $lexware['skipped_zero'] }}.</p>
        @endif
        @unless($lexware['run'])
            <x-ui.forms.checkbox wire:model.live="lexwareFirstTransfers.{{ $selectedRevision }}-{{ $lexware['profile_version'] }}"
                label="Zielfirma und Personalnummer sind geprüft. Für diese Person und diesen Monat ist dies die erste Vollübergabe an Lexware." />
        @endunless
        <div class="ops-actions">
            <x-ui.buttons.button-basic type="button" wire:click="downloadLexware({{ $lexware['profile_version'] }})" wire:loading.attr="disabled"
                :disabled="!$lexware['run'] && !($lexwareFirstTransfers[$selectedRevision.'-'.$lexware['profile_version']] ?? false)">
                {{ $lexware['run'] ? 'Identische Datei erneut herunterladen' : 'Lexware-Datei herunterladen' }}
            </x-ui.buttons.button-basic>
        </div>
    @endif

    @if($lexware['runs'])
        <h4 class="font-semibold">Exportverlauf</h4>
        <ul class="divide-y divide-rt-border dark:divide-rt-dark-border">
            @foreach($lexware['runs'] as $run)
                <li class="space-y-2 py-2" wire:key="lexware-run-{{ $run['id'] }}">
                    <p><strong>Lauf {{ $run['id'] }}</strong> · Abschluss R{{ $run['closing_revision'] }} · Profil V{{ $run['profile_version'] }}</p>
                    <p class="text-sm">Datei erstellt am {{ \Illuminate\Support\Carbon::parse($run['created_at'], 'UTC')->timezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} Uhr @if(isset($run['created_by'])) · Benutzer #{{ $run['created_by'] }}@endif</p>
                    <details class="text-sm"><summary>Datei-Prüfsumme (SHA-256)</summary><code class="break-all">{{ $run['sha256'] }}</code></details>
                    @if($run['status'] === 'open')
                        <p class="text-sm">Importbestätigung ausstehend</p>
                        <form wire:submit="recordLexwareResult({{ $run['id'] }})" class="space-y-2">
                            <x-operations.field label="Ergebnis des Windows-Imports" model="lexwareResults.{{ $run['id'] }}" type="select" :live="true" required>
                                <option value="">Ergebnis auswählen</option>
                                <option value="full">Vollständig importiert</option>
                                @if(count($run['rows']) > 1)<option value="partial">Teilweise importiert</option>@endif
                                <option value="rejected">Import abgelehnt</option>
                            </x-operations.field>
                            @if(($lexwareResults[$run['id']] ?? '') === 'partial')
                                <fieldset class="space-y-1">
                                    <legend class="text-sm font-semibold">Welche Positionen hat Lexware importiert?</legend>
                                    @foreach($run['rows'] as $position)
                                        <div><x-ui.forms.checkbox wire:model="lexwareImportedRows.{{ $run['id'] }}.{{ $position['target_code'] }}"
                                            :label="'Lohnart '.$position['target_code'].' · '.$position['value'].' Std.'" /></div>
                                    @endforeach
                                </fieldset>
                            @endif
                            <x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Ergebnis dokumentieren</x-ui.buttons.button-basic>
                        </form>
                    @else
                        <p class="text-sm"><strong>{{ ['full' => 'Vollständig importiert', 'partial' => 'Teilweise importiert', 'rejected' => 'Import abgelehnt'][$run['status']] }}</strong>
                            @if($run['result_at']) · {{ \Illuminate\Support\Carbon::parse($run['result_at'], 'UTC')->timezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} Uhr @endif
                            @if(isset($run['result_by'])) · Erfasst durch Benutzer #{{ $run['result_by'] }} @endif
                        </p>
                        @if($run['status'] === 'partial' || $run['status'] === 'rejected')
                            <p class="ops-muted">Offene Positionen in der Windows-Firma abgleichen, bevor weitere Buchungen erfolgen.</p>
                        @endif
                    @endif
                    <ul class="space-y-1 text-sm" aria-label="Lohnpositionen im Exportlauf">
                        @foreach($run['rows'] as $position)
                            <li>
                                Lohnart {{ $position['target_code'] }} · {{ $position['value'] }} Std.@if($run['status'] !== 'open') · {{ in_array($position['target_code'], $run['imported_codes'], true) ? 'importiert' : 'nicht importiert' }}@endif
                                @if($sources = $sourceBreakdown($position))<span class="ops-muted block">{{ $sources }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    @endif
</section>
