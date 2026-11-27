<div class="rt-personnel-management space-y-5">
    @php
        $fieldIssues = $fieldIssues ?? [];
        $referenceIssues = $referenceIssues ?? [];
    @endphp
    <p class="ops-muted">Für Lexware lohn+gehalt unter Windows lädt die Abrechnung eine Datei aus dem abgeschlossenen Monat herunter und importiert sie in der Zielfirma.</p>

    @if($notice)
        <p class="text-sm font-medium" role="status">{{ $notice }}</p>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-300 p-3 text-sm" role="alert">
            @foreach(array_unique($errors->all()) as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="space-y-2">
        <x-ui.forms.label for="lexware-employer" value="Arbeitgeberreferenz" />
        @if($employers)
            <x-ui.forms.select id="lexware-employer" action="selectEmployer" :value="$employer" aria-label="Arbeitgeberreferenz auswählen">
                @if($employer === '')<option value="" disabled>Arbeitgeber auswählen</option>@endif
                @foreach($employers as $reference)
                    <option value="{{ $reference }}">{{ $reference }}</option>
                @endforeach
            </x-ui.forms.select>
        @else
            <p class="text-sm font-semibold">Produktivexport deaktiviert</p>
            <p class="ops-muted">Für Ihren Zuständigkeitsbereich ist noch keine Arbeitgeberreferenz hinterlegt.</p>
            @if($canEditReferences)
                <x-ui.buttons.button-basic :href="\App\Support\Operations\OperationsPages::url('payroll', ['section' => 'payroll-references'])" size="sm">Lohnzuordnung öffnen</x-ui.buttons.button-basic>
            @endif
        @endif
    </section>

    @if($employer !== '')
        <div wire:key="lexware-company-{{ $employer }}" class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <strong class="text-sm">Produktivexport {{ $profile?->active ? 'aktiv' : 'deaktiviert' }}</strong>
                @if($profile)
                    <span class="ops-muted">Profilversion {{ $profile->config_version }}</span>
                @endif
            </div>
            @if($profile && $revision !== (int) $profile->revision)
                <div role="status" class="space-y-2">
                    <p>Die Einstellungen wurden zwischenzeitlich geändert. Beim Neuladen werden Ihre offenen Eingaben verworfen.</p>
                    <x-ui.buttons.button-basic type="button" wire:click="selectEmployer(@js($employer))" size="sm">Aktuellen Stand laden</x-ui.buttons.button-basic>
                </div>
            @endif

            <form wire:submit="save" class="space-y-5">
                <fieldset class="min-w-0 space-y-3">
                    <legend class="font-semibold">Zielfirma und Importprofil</legend>
                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach(['company_name' => 'Firmenname in Lexware', 'import_profile' => 'Importprofil in Lexware'] as $key => $label)
                            <div>
                                <x-operations.field :label="$label" :model="'form.'.$key" maxlength="120"
                                    :aria-describedby="$errors->has('form.'.$key) ? \Illuminate\Support\Str::slug('form.'.$key).'-error' : (isset($fieldIssues[$key]) ? 'lexware-hint-'.$key : null)" />
                                @if(isset($fieldIssues[$key]) && ! $errors->has('form.'.$key))
                                    <p id="lexware-hint-{{ $key }}" class="ops-muted">{{ $fieldIssues[$key] }}</p>
                                @endif
                            </div>
                        @endforeach
                        <x-operations.field label="Programmversion (optional)" model="form.product_version" maxlength="80" />
                    </div>
                </fieldset>
                <fieldset class="min-w-0 space-y-3">
                    <legend class="font-semibold">Lohnarten zuordnen</legend>
                    <p class="ops-muted">Den Lohncode aus der RailTime-Vergütungsregel einer Stundenlohnart in der Zielfirma zuordnen. Zuschlagsstunden erhalten eine eigene Lohnart.</p>
                    @if(isset($fieldIssues['mappings']))
                        <p id="lexware-hint-mappings" class="ops-muted">{{ $fieldIssues['mappings'] }}</p>
                    @endif
                    @foreach($form['mappings'] as $index => $mapping)
                        <div wire:key="lexware-mapping-{{ $rowKeys[$index] ?? $index }}" class="space-y-2 rounded-lg border border-rt-border p-3 dark:border-rt-dark-border">
                            <div class="grid items-start gap-3 md:grid-cols-3">
                                @foreach(['source_code' => 'RailTime-Lohncode', 'target_code' => 'Lexware-Lohnartnummer', 'quantity_kind' => 'Mengenart'] as $key => $label)
                                    @php
                                        $field = 'mappings.'.$index.'.'.$key;
                                        $model = 'form.'.$field;
                                        $hintId = isset($fieldIssues[$field]) && ! $errors->has($model) ? 'lexware-hint-'.$index.'-'.$key : null;
                                        $descriptionId = $errors->has($model) ? \Illuminate\Support\Str::slug($model).'-error' : $hintId;
                                    @endphp
                                    <div>
                                        @if($key === 'quantity_kind')
                                            <x-operations.field :label="$label" type="select" :model="$model" :aria-describedby="$descriptionId">
                                                <option value="" disabled>Mengenart auswählen</option>
                                                <option value="base">Grundstunden</option>
                                                <option value="supplement">Zuschlagsstunden</option>
                                            </x-operations.field>
                                        @elseif($key === 'target_code')
                                            <x-operations.field :label="$label" :model="$model" maxlength="4" inputmode="numeric" placeholder="z. B. 0001" :aria-describedby="$descriptionId" />
                                        @else
                                            <x-operations.field :label="$label" :model="$model" maxlength="40" :aria-describedby="$descriptionId" />
                                        @endif
                                        @if($hintId)
                                            <p id="{{ $hintId }}" class="ops-muted">{{ $fieldIssues[$field] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <x-ui.forms.checkbox wire:model="form.mappings.{{ $index }}.disjoint" :id="'lexware-disjoint-'.$index" label="Bei gemeinsamer Ziel-Lohnart sind die Zeitanteile überschneidungsfrei."
                                        :aria-describedby="isset($fieldIssues['mappings.'.$index.'.disjoint']) ? 'lexware-hint-'.$index.'-disjoint' : null" />
                                    @if(isset($fieldIssues['mappings.'.$index.'.disjoint']))
                                        <p id="lexware-hint-{{ $index }}-disjoint" class="ops-muted">{{ $fieldIssues['mappings.'.$index.'.disjoint'] }}</p>
                                    @endif
                                </div>
                                <x-ui.buttons.button-basic type="button" size="sm" wire:click="removeMapping({{ $index }})" :aria-label="'Zuordnung '.($index + 1).' entfernen'">Entfernen</x-ui.buttons.button-basic>
                            </div>
                        </div>
                    @endforeach
                    <x-ui.buttons.button-basic type="button" size="sm" wire:click="addMapping" :aria-describedby="isset($fieldIssues['mappings']) ? 'lexware-hint-mappings' : null"><i class="far fa-plus" aria-hidden="true"></i>Lohnart zuordnen</x-ui.buttons.button-basic>
                </fieldset>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Einstellungen speichern</x-ui.buttons.button-basic>
                    <span class="ops-muted">{{ $profile?->active ? 'Geänderte Einstellungen sperren den Export bis zur erneuten Freigabe.' : 'Sie können den Entwurf auch mit fehlenden Angaben speichern.' }}</span>
                </div>
            </form>
        </div>
    @endif

    <section class="space-y-2 border-t border-rt-border pt-4 dark:border-rt-dark-border">
        <h3 class="font-semibold">Musterimport in der Testfirma</h3>
        <p class="ops-muted">Das Muster verwendet erfundene Daten. 4 Std. 30 Min. Standardarbeitszeit und 3 Std. 30 Min. Dienst ergeben zusammen eine Buchung über 8,00 Stunden.</p>
        <div class="overflow-x-auto rounded-lg border border-rt-border dark:border-rt-dark-border" tabindex="0" role="region" aria-label="Spalten und erwartetes Ergebnis der Musterdatei">
            <table class="w-full whitespace-nowrap text-left text-sm">
                <caption class="sr-only">Erwartetes Ergebnis im November 2026</caption>
                <thead><tr>@foreach(['Jahr', 'Monat', 'Personalnummer', 'Lohnartnummer', 'Wert'] as $column)<th scope="col" class="px-3 py-2">{{ $column }}</th>@endforeach</tr></thead>
                <tbody><tr>@foreach(['2026', '11', '00017', '0001', '8,00'] as $value)<td class="px-3 py-2">{{ $value }}</td>@endforeach</tr></tbody>
            </table>
        </div>
        <p class="ops-muted">Die TXT-Datei enthält eine Kopfzeile und Windows-Zeilenumbrüche (CRLF). Für den Import ANSI (Windows-1252), Semikolon und Dezimalkomma wählen. Die Personalnummer einschließlich führender Nullen prüfen.</p>
        <x-ui.buttons.button-basic type="button" wire:click="downloadSample" wire:loading.attr="disabled"><i class="far fa-download" aria-hidden="true"></i>Musterdatei herunterladen</x-ui.buttons.button-basic>
    </section>

    @if($employer !== '')
        <section class="space-y-3 border-t border-rt-border pt-4 dark:border-rt-dark-border">
            <h3 class="font-semibold">Freigabe für {{ $employer }}</h3>
            @if($referenceIssues)
                <div class="space-y-2 text-sm" role="status">
                    <p class="font-medium">Lohnzuordnung prüfen</p>
                    <ul class="list-disc space-y-1 pl-5">@foreach($referenceIssues as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                    @if($canEditReferences)
                        <x-ui.buttons.button-basic :href="\App\Support\Operations\OperationsPages::url('payroll', ['section' => 'payroll-references'])" size="sm">Lohnzuordnung öffnen</x-ui.buttons.button-basic>
                    @endif
                </div>
            @endif
            @if($profile?->active)
                <p class="ops-muted">Eine Sperre gilt sofort und erfasst auch erneute Downloads.</p>
                <x-ui.buttons.button-basic type="button" wire:click="deactivate" wire:loading.attr="disabled">Produktivexport sperren</x-ui.buttons.button-basic>
            @else
                @if($missing)
                    @if($otherIssues = array_diff($missing, $referenceIssues, $fieldIssues))
                    <div class="space-y-1 text-sm">
                        <p class="font-medium">Für die Freigabe im gespeicherten Profil fehlt noch</p>
                        <ul class="list-disc space-y-1 pl-5">@foreach($otherIssues as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                    </div>
                    @elseif($fieldIssues)
                        <p class="ops-muted">Bitte vervollständigen Sie die gekennzeichneten Felder und speichern Sie den Entwurf.</p>
                    @endif
                @elseif($profile && ! $profile->tested_at)
                    <x-ui.forms.checkbox id="lexware-confirm-test" wire:model.live="testAcknowledged" label="Ich habe die Musterzeile mit 8,00 Stunden in der Testfirma erfolgreich importiert und die hier gespeicherten Ziel-Lohnarten sowie Personalnummern mit der Zielfirma abgeglichen." />
                    <x-ui.buttons.button-basic type="button" wire:click="confirmTest" :disabled="! $testAcknowledged" wire:loading.attr="disabled">Probeimport bestätigen</x-ui.buttons.button-basic>
                @endif
                @if($profile?->tested_at)
                    <p class="text-sm">Probeimport für Profilversion {{ $profile->config_version }} bestätigt.</p>
                @endif
                <div>
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="activate" wire:confirm="Den Download von Echtdaten für diese Zielfirma freigeben?" :disabled="! $profile?->tested_at || count($missing) > 0 || $revision !== (int) $profile?->revision" wire:loading.attr="disabled">Produktivexport aktivieren</x-ui.buttons.button-basic>
                </div>
            @endif
        </section>

        @if($history)
            <details class="border-t border-rt-border pt-4 dark:border-rt-dark-border">
                <summary class="cursor-pointer font-semibold">Änderungsprotokoll</summary>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach($history as $entry)
                        <li><strong>{{ (['saved' => 'Einstellungen gespeichert', 'test_confirmed' => 'Probeimport bestätigt', 'activated' => 'Export aktiviert', 'deactivated' => 'Export gesperrt'])[$entry['event']] ?? $entry['event'] }}</strong>
                            · Profilversion {{ $entry['config_version'] }} · {{ \Illuminate\Support\Carbon::parse($entry['created_at'])->timezone('Europe/Berlin')->format('d.m.Y. H:i') }} Uhr · Benutzer-ID {{ $entry['actor_id'] }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif
</div>
