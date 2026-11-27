<section class="ops-stack rounded-xl border border-rt-border p-4 dark:border-rt-dark-border" aria-label="Lexware für Windows">
    <div>
        <h3 class="font-semibold">Lexware lohn+gehalt für Windows</h3>
        @if($lexware['available'] && $lexware['closing_revision'] === $selectedRevision)
            <p class="ops-muted">Die Datei in der angegebenen Windows-Firma importieren und das Ergebnis anschließend hier dokumentieren.</p>
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
                    <th scope="col" class="p-2 text-right">Dauer (h:mm:ss)</th><th scope="col" class="p-2 text-right">Export (Std.)</th>
                </tr></thead>
                <tbody>
                    @foreach($lexware['rows'] as $row)
                        <tr class="border-t border-rt-border dark:border-rt-dark-border">
                            <th scope="row" class="p-2 text-left align-top">{{ $row['target_code'] }}<span class="ops-muted block">{{ $row['quantity_kind'] === 'base' ? 'Grundstunden' : 'Zuschlagsstunden' }}</span></th>
                            <td class="p-2">{{ implode(', ', $row['source_codes']) }}</td>
                            <td class="p-2 text-right tabular-nums">{{ sprintf('%d:%02d:%02d', intdiv($row['raw_seconds'], 3600), intdiv($row['raw_seconds'] % 3600, 60), $row['raw_seconds'] % 60) }}</td>
                            <td class="p-2 text-right font-semibold tabular-nums">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($lexware['skipped_zero'])
            <p class="ops-muted">Ausgelassene Nullmengen oder unbezahlte Pausen: {{ $lexware['skipped_zero'] }}.</p>
        @endif
        <div class="ops-actions">
            <x-ui.buttons.button-basic type="button" wire:click="downloadLexware({{ $lexware['profile_version'] }})" wire:loading.attr="disabled">
                {{ $lexware['run'] ? 'Identische Datei erneut herunterladen' : 'Lexware-Datei herunterladen' }}
            </x-ui.buttons.button-basic>
        </div>
    @endif

    @if($lexware['runs'])
        <h4 class="font-semibold">Exportverlauf</h4>
        <ul class="divide-y divide-rt-border dark:divide-rt-dark-border">
            @foreach($lexware['runs'] as $run)
                <li class="space-y-2 py-2" wire:key="lexware-run-{{ $run['id'] }}">
                    <p><strong>Abschluss R{{ $run['closing_revision'] }} · Profil V{{ $run['profile_version'] }}</strong></p>
                    <p class="text-sm">Datei erstellt am {{ \Illuminate\Support\Carbon::parse($run['created_at'], 'UTC')->timezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} Uhr</p>
                    <details class="text-sm"><summary>Datei-Prüfsumme (SHA-256)</summary><code class="break-all">{{ $run['sha256'] }}</code></details>
                    @if($run['status'] === 'open')
                        <form wire:submit="recordLexwareResult({{ $run['id'] }})" class="flex flex-wrap items-end gap-2">
                            <x-operations.field label="Ergebnis des Windows-Imports" model="lexwareResults.{{ $run['id'] }}" type="select" required>
                                <option value="">Ergebnis auswählen</option>
                                <option value="full">Vollständig importiert</option>
                                <option value="partial">Teilweise importiert</option>
                                <option value="rejected">Import abgelehnt</option>
                            </x-operations.field>
                            <x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Ergebnis dokumentieren</x-ui.buttons.button-basic>
                        </form>
                    @else
                        <p class="text-sm"><strong>{{ ['full' => 'Vollständig importiert', 'partial' => 'Teilweise importiert', 'rejected' => 'Import abgelehnt'][$run['status']] }}</strong>
                            @if($run['result_at']) · {{ \Illuminate\Support\Carbon::parse($run['result_at'], 'UTC')->timezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} Uhr @endif
                        </p>
                        @if($run['status'] === 'partial' || $run['status'] === 'rejected')
                            <p class="ops-muted">Offene Positionen in der Windows-Firma abgleichen, bevor weitere Buchungen erfolgen.</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
