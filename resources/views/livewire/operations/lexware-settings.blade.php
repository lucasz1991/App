<div class="rt-personnel-management space-y-5">
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
                        <x-operations.field label="Firmenname in Lexware" model="form.company_name" maxlength="120" />
                        <x-operations.field label="Importprofil in Lexware" model="form.import_profile" maxlength="120" />
                        <x-operations.field label="Programmversion (optional)" model="form.product_version" maxlength="80" />
                    </div>
                </fieldset>
                <fieldset class="min-w-0 space-y-3">
                    <legend class="font-semibold">Lohnarten zuordnen</legend>
                    <p class="ops-muted">Den Lohncode aus der RailTime-Vergütungsregel einer Stundenlohnart in der Zielfirma zuordnen. Zuschlagsstunden erhalten eine eigene Lohnart.</p>
                    @foreach($form['mappings'] as $index => $mapping)
                        <div wire:key="lexware-mapping-{{ $rowKeys[$index] ?? $index }}" class="space-y-2 rounded-lg border border-rt-border p-3 dark:border-rt-dark-border">
                            <div class="grid items-end gap-3 md:grid-cols-3">
                                <x-operations.field label="RailTime-Lohncode" :model="'form.mappings.'.$index.'.source_code'" maxlength="40" />
                                <x-operations.field label="Lexware-Lohnartnummer" :model="'form.mappings.'.$index.'.target_code'" maxlength="4" inputmode="numeric" placeholder="z. B. 0001" />
                                <x-operations.field label="Mengenart" type="select" :model="'form.mappings.'.$index.'.quantity_kind'">
                                    <option value="base">Grundstunden</option>
                                    <option value="supplement">Zuschlagsstunden</option>
                                </x-operations.field>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <x-ui.forms.checkbox wire:model="form.mappings.{{ $index }}.disjoint" :id="'lexware-disjoint-'.$index" label="Bei gemeinsamer Ziel-Lohnart sind die Zeitanteile überschneidungsfrei." />
                                <x-ui.buttons.button-basic type="button" size="sm" wire:click="removeMapping({{ $index }})" :aria-label="'Zuordnung '.($index + 1).' entfernen'">Entfernen</x-ui.buttons.button-basic>
                            </div>
                        </div>
                    @endforeach
                    <x-ui.buttons.button-basic type="button" size="sm" wire:click="addMapping"><i class="far fa-plus" aria-hidden="true"></i>Lohnart zuordnen</x-ui.buttons.button-basic>
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
        <p class="ops-muted">Im Windows-Importprofil Semikolon als Trennzeichen und Dezimalkomma wählen. Die Personalnummer einschließlich führender Nullen prüfen.</p>
        <x-ui.buttons.button-basic type="button" wire:click="downloadSample" wire:loading.attr="disabled"><i class="far fa-download" aria-hidden="true"></i>Musterdatei herunterladen</x-ui.buttons.button-basic>
    </section>

    @if($employer !== '')
        <section class="space-y-3 border-t border-rt-border pt-4 dark:border-rt-dark-border">
            <h3 class="font-semibold">Freigabe für {{ $employer }}</h3>
            @if($profile?->active)
                <p class="ops-muted">Die Abrechnung kann Lexware-Dateien für diese Firma herunterladen. Eine Sperre gilt sofort und erfasst auch erneute Downloads.</p>
                <x-ui.buttons.button-basic type="button" wire:click="deactivate" wire:loading.attr="disabled">Produktivexport sperren</x-ui.buttons.button-basic>
            @else
                @if($missing)
                    <div class="space-y-1 text-sm">
                        <p class="font-medium">Für die Freigabe im gespeicherten Profil fehlt noch</p>
                        <ul class="list-disc space-y-1 pl-5">@foreach($missing as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                    </div>
                @elseif($profile && ! $profile->tested_at)
                    <x-ui.forms.checkbox id="lexware-confirm-test" wire:model.live="testAcknowledged" label="In der Lexware-Testfirma habe ich das Muster mit diesem Importprofil eingelesen und Personalnummer, Lohnart und 8,00 Stunden geprüft." />
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
