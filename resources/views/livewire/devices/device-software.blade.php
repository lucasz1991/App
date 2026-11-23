<x-ui.page title="Software" eyebrow="IT & Betrieb" description="Programme festlegen, die auf den Geräten der Mitarbeiter vorhanden sein sollen, und sehen, wo sie installiert sind." page-key="device-software" :count="$packages->count()">
    <x-slot:actions>
        {{-- Auf dem Telefon ist im Seitenkopf nur Platz für die Hauptaktion; zurück führt der Pfeil. --}}
        <a href="{{ route('devices.desktop-clients') }}" class="hidden min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white sm:inline-flex">Desktopclients</a>
        @if($canManageDirectory)
            <x-ui.buttons.button-basic type="button" mode="primary" wire:click="openCreate" class="min-h-11" aria-label="Programm hinzufügen">
                <i class="far fa-plus" aria-hidden="true"></i>
                <span class="sm:hidden">Hinzufügen</span>
                <span class="hidden sm:inline">Programm hinzufügen</span>
            </x-ui.buttons.button-basic>
        @endif
    </x-slot:actions>

    <div class="space-y-4">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/20 dark:text-amber-100">
            <strong>{{ $commandsEnabled ? 'Installationen sind freigegeben.' : 'Installationen sind zurzeit gesperrt.' }}</strong>
            @if($commandsEnabled)
                Ein Programm wird automatisch installiert, wenn auf dem Gerät die RailTime-App verbunden ist und dort „Softwareinstallation“ und „Automatische Installation“ erlaubt sind.
            @else
                Verzeichnis und Zuweisungen lassen sich pflegen. Installiert wird erst, wenn der globale Aktionsschalter der Geräteverwaltung freigegeben ist.
            @endif
            Privatgeräte erhalten nur Programme aus ihrem bestätigten Arbeitsplatzprofil und fragen am Gerät nach.
        </div>
        @if($notice)<p role="status" class="rounded-xl border border-emerald-200 p-3 text-sm">{{ $notice }}</p>@endif

        <section aria-labelledby="device-software-directory">
            <h2 id="device-software-directory" class="text-base font-semibold text-rt-text dark:text-white">Programmverzeichnis</h2>
            <x-tables.table
                label="Programmverzeichnis"
                :columns="[
                    ['label' => 'Programm', 'key' => 'package', 'width' => '32%', 'hideOn' => 'none'],
                    ['label' => 'Version', 'key' => 'version', 'width' => '20%', 'hideOn' => 'md'],
                    ['label' => 'Zugewiesen an', 'key' => 'targets', 'width' => '28%', 'hideOn' => 'none'],
                    ['label' => 'Stand', 'key' => 'state', 'width' => '20%', 'hideOn' => 'md'],
                ]"
                :items="$packages"
                row-view="components.tables.rows.devices.software-package-row"
                actions-view="components.tables.rows.devices.software-package-actions"
                empty="Noch kein Programm im Verzeichnis. Ein Programm wird mit seiner WinGet-Paket-ID und einer festen Version aufgenommen."
            />
        </section>

        <section aria-labelledby="device-software-states">
            <h2 id="device-software-states" class="text-base font-semibold text-rt-text dark:text-white">Stand je Gerät</h2>
            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Die Angaben stammen aus der Meldung der RailTime-App auf dem Gerät. Ein vorhandenes Programm gilt in jeder Version als erfüllt; es wird nicht überschrieben. Nach {{ $maxAttempts }} erfolglosen Versuchen wartet das Gerät auf eine Freigabe.</p>
            <x-tables.table
                label="Stand je Gerät"
                :columns="[
                    ['label' => 'Gerät', 'key' => 'device', 'width' => '25%', 'hideOn' => 'none'],
                    ['label' => 'Programm', 'key' => 'package', 'width' => '25%', 'hideOn' => 'none'],
                    ['label' => 'Stand', 'key' => 'state', 'width' => '22%', 'hideOn' => 'none'],
                    ['label' => 'Letzter Auftrag', 'key' => 'job', 'width' => '28%', 'hideOn' => 'md'],
                ]"
                :items="$states"
                row-view="components.tables.rows.devices.software-state-row"
                empty="Noch keine Meldung. Ein Gerät meldet den Stand, sobald ihm ein Programm zugewiesen ist und die RailTime-App dort läuft."
            />
        </section>
    </div>

    <x-dialog-modal wire:model="showPackageDialog" maxWidth="lg" id="device-software-package-dialog">
        <x-slot:title>{{ $packagePublicId ? 'Programm bearbeiten' : 'Programm hinzufügen' }}</x-slot:title>
        <x-slot:content>
            @if($showPackageDialog && $canManageDirectory)
                <form wire:submit="savePackage" id="device-software-package-form" class="space-y-4">
                    <div>
                        <x-ui.forms.label for="device-software-name" value="Name" />
                        <x-ui.forms.input id="device-software-name" wire:model="name" maxlength="120" class="mt-1" placeholder="Zum Beispiel Adobe Acrobat Reader" />
                        <x-ui.forms.input-error for="name" class="mt-1" />
                    </div>
                    <div>
                        <x-ui.forms.label for="device-software-package-id" value="WinGet-Paket-ID" />
                        <x-ui.forms.input id="device-software-package-id" wire:model="packageId" maxlength="128" class="mt-1 font-mono" placeholder="Hersteller.Paketname" autocomplete="off" spellcheck="false" :readonly="(bool) $packagePublicId" aria-describedby="device-software-package-id-hint" />
                        <p id="device-software-package-id-hint" class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $packagePublicId ? 'Die Paket-ID bleibt fest. Für ein anderes Paket bitte ein neues Programm anlegen.' : 'Die ID steht im WinGet-Katalog, zum Beispiel Adobe.Acrobat.Reader.64-bit.' }}
                        </p>
                        <x-ui.forms.input-error for="package_id" class="mt-1" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-ui.forms.label for="device-software-version" value="Feste Version" />
                            <x-ui.forms.input id="device-software-version" wire:model="version" maxlength="40" class="mt-1 font-mono" placeholder="Zum Beispiel 24.5.20320" autocomplete="off" spellcheck="false" />
                            <x-ui.forms.input-error for="version" class="mt-1" />
                        </div>
                        <div>
                            <x-ui.forms.label for="device-software-scope" value="Installation" />
                            <x-ui.forms.select id="device-software-scope" wire:model="scope" class="mt-1">
                                <option value="user">Für den Benutzer</option>
                                <option value="machine">Für das ganze Gerät</option>
                            </x-ui.forms.select>
                            <x-ui.forms.input-error for="scope" class="mt-1" />
                        </div>
                    </div>
                    <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">„Für das ganze Gerät“ braucht Adminrechte. Windows fragt den Mitarbeiter dann einmal nach der Erlaubnis.</p>
                    <div class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border">
                        <x-ui.forms.checkbox id="device-software-agreements" wire:model="agreementsAccepted" label="Lizenz- und Quellbedingungen dieser Version sind geprüft und für die Firma akzeptiert." />
                        <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die Installation bestätigt die Bedingungen auf den Geräten ohne weitere Rückfrage. Nötig beim Anlegen und bei jeder neuen Version.</p>
                        <x-ui.forms.input-error for="agreements_accepted" class="mt-1" />
                    </div>
                </form>
            @endif
        </x-slot:content>
        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-2">
                <x-ui.buttons.button-basic type="button" wire:click="$set('showPackageDialog', false)" class="min-h-11">Abbrechen</x-ui.buttons.button-basic>
                @if($showPackageDialog && $canManageDirectory)
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="savePackage" wire:loading.attr="disabled" wire:target="savePackage" class="min-h-11">
                        <i class="far fa-spinner fa-spin" wire:loading wire:target="savePackage" aria-hidden="true"></i>
                        Speichern
                    </x-ui.buttons.button-basic>
                @endif
            </div>
        </x-slot:footer>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showAssignDialog" maxWidth="lg" id="device-software-assign-dialog">
        <x-slot:title>Zuweisen{{ $assigning ? ' · '.$assigning->name : '' }}</x-slot:title>
        <x-slot:content>
            @if($assigning && $canAssign)
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-rt-text dark:text-white">Bisher zugewiesen</h3>
                        @if($assigning->assignments->isEmpty())
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Noch nicht zugewiesen.</p>
                        @else
                            <ul class="mt-2 divide-y divide-rt-border rounded-xl border border-rt-border dark:divide-rt-dark-border dark:border-rt-dark-border" aria-label="Bisherige Zuweisungen">
                                @foreach($assigning->assignments as $index => $assignment)
                                    <li class="flex min-w-0 flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="software-assignment-{{ $assignment->public_id }}">
                                        <span class="min-w-0 break-words text-sm">{{ $assigning->target_labels[$index] ?? 'Ziel' }}</span>
                                        <x-ui.buttons.button-basic type="button" size="sm" wire:click="unassign('{{ $assignment->public_id }}')" wire:loading.attr="disabled" class="min-h-9">Entfernen</x-ui.buttons.button-basic>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <form wire:submit="assign" class="space-y-3">
                        <div>
                            <x-ui.forms.label for="device-software-target-type" value="Neues Ziel" />
                            <x-ui.forms.select id="device-software-target-type" wire:model.live="targetType" class="mt-1">
                                <option value="corporate">Alle Firmengeräte</option>
                                <option value="profile">Ein Arbeitsplatzprofil</option>
                                <option value="device">Ein einzelnes Gerät</option>
                            </x-ui.forms.select>
                        </div>
                        @if($targetType === 'profile')
                            <div>
                                <x-ui.forms.label for="device-software-target-profile" value="Arbeitsplatzprofil" />
                                <x-ui.forms.select id="device-software-target-profile" wire:model="targetProfile" placeholder="Profil auswählen" class="mt-1">
                                    @foreach($profiles as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </x-ui.forms.select>
                            </div>
                        @elseif($targetType === 'device')
                            <div>
                                <x-ui.forms.label for="device-software-target-device" value="Gerät" />
                                <x-ui.forms.select id="device-software-target-device" wire:model="targetDevice" placeholder="Gerät auswählen" class="mt-1">
                                    @foreach($devices as $device)
                                        <option value="{{ $device->public_id }}">{{ $device->display_name ?: $device->hostname ?: 'Unbenanntes Gerät' }}{{ $device->ownership === 'byod' ? ' · Privatgerät' : '' }}</option>
                                    @endforeach
                                </x-ui.forms.select>
                            </div>
                        @endif
                        <x-ui.forms.input-error for="target" class="mt-1" />
                        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die Zuweisung wirkt beim nächsten Abgleich der Geräte, in der Regel innerhalb weniger Minuten. Entfernen deinstalliert nichts.</p>
                    </form>
                </div>
            @endif
        </x-slot:content>
        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-2">
                <x-ui.buttons.button-basic type="button" wire:click="$set('showAssignDialog', false)" class="min-h-11">Schließen</x-ui.buttons.button-basic>
                @if($assigning && $canAssign)
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="assign" wire:loading.attr="disabled" wire:target="assign" class="min-h-11">
                        <i class="far fa-spinner fa-spin" wire:loading wire:target="assign" aria-hidden="true"></i>
                        Zuweisen
                    </x-ui.buttons.button-basic>
                @endif
            </div>
        </x-slot:footer>
    </x-dialog-modal>
</x-ui.page>
