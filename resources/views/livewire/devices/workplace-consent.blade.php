<div>
    <button type="button" wire:click="open" class="min-h-10 rounded-xl border border-rt-border px-3 text-xs font-semibold">{{ $device->ownership === 'byod' ? 'Privatgerät' : 'Firmengerät' }} · Verwaltung & Freigabe</button>
    <x-dialog-modal wire:model="showModal">
        <x-slot:title>Arbeitsplatzprofil · {{ $device->display_name }}</x-slot:title>
        <x-slot:content>
            <div class="space-y-4 text-sm">
                <p>{{ $device->ownership === 'byod' ? 'Privatgerät – bleibt Ihr Eigentum und wird nicht zum Firmenlagerbestand.' : 'Zugeordnetes Firmengerät' }}</p>
                @can('devices.manage')
                    <form wire:submit="configure" class="space-y-2">
                        <label class="block">Arbeitsplatzprofil<select wire:model="profile" class="mt-1 w-full rounded-xl border-rt-border">
                            @foreach(\App\Services\DeviceManagement\WorkplaceProfileCatalog::all() as $key => $profileInfo)
                                @if($key === 'railtime_basic' || ($device->ownership === 'byod' ? $key === 'byod_managed' : $key === 'corporate_standard'))<option value="{{ $key }}">{{ $profileInfo['label'] }}</option>@endif
                            @endforeach
                        </select></label>
                        <button type="submit" class="min-h-10 rounded-xl border px-3">Profil festlegen</button>
                        <p class="text-xs text-rt-muted">Änderungen sperren offene Aufträge und benötigen eine neue Mitarbeiterfreigabe.</p>
                    </form>
                @endcan
                @if($summary['scope'])
                    <div class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-950">
                        <p class="font-semibold">{{ $summary['scope']['label'] }} · Umfang {{ $summary['revision'] }}</p>
                        <p>{{ $summary['scope']['notice'] }}</p>
                        <p>{{ $summary['scope']['system_service'] ? 'Dauerhafte Verwaltungs- und Supportdienste, Geräte- und Zustandsinventar sowie freigegebene Firmenprogramme. Ein Dienst mit Systemrechten hat technisch Zugriff auf den gesamten Rechner – kein isolierter Firmencontainer.' : 'RailTime-Client und Hilfe ohne verpflichtende Office-Lizenz und ohne automatische Systeminstallation.' }}</p>
                        <p>Keine privaten Dateien, Mailkonten oder Browserprofile werden automatisch übernommen. Keine permanente Ortung; Standort bedeutet dokumentierter Lager-/Einsatzort.</p>
                        <p>Privatgeräte: Fernhilfe mit Sitzungsfreigabe und gesonderter Dateiübertragung. Keine Komplettlöschung. Vorhandenes Office und OneDrive werden nicht ungeprüft ersetzt. Neustarts müssen angekündigt und aufschiebbar sein.</p>
                        <p>Keine zusätzliche Gerätelizenz. Office-Desktop benötigt eine passende bestehende Microsoft-Berechtigung. Dienste werden erst nach separat nachgewiesener Einrichtung als bereit angezeigt.</p>
                    </div>
                    @if($summary['management_state'] === 'cleanup_pending')
                        <p role="status" class="font-semibold text-amber-800">Zugriff gesperrt – lokale Bereinigung ausstehend. IT muss das Beenden und Entfernen der Verwaltungs- und Supportdienste bestätigen. Private Dateien werden nicht pauschal gelöscht.</p>
                    @elseif($summary['consent_current'])
                        <p role="status">Verwaltungsumfang bestätigt. Dies ist noch kein Nachweis installierter Dienste.</p>
                        @if($owner)<form wire:submit="withdraw" class="space-y-2"><label class="flex gap-2"><input type="checkbox" wire:model="withdrawConfirmed">Ich möchte die Verwaltung beenden und weitere Aufträge sperren.</label><button type="submit" class="min-h-10 rounded-xl border border-red-300 px-3 text-red-700">Verwaltung widerrufen</button></form>@endif
                    @elseif($owner)
                        <form wire:submit="accept" class="space-y-2"><label class="flex items-start gap-2"><input type="checkbox" wire:model="confirmed">Ich bestätige die Gerätezuordnung, das angegebene Eigentum und den beschriebenen Verwaltungsumfang. Bei meinem Privatgerät stimme ich der dauerhaften Verwaltung ausdrücklich zu.</label><button type="submit" class="min-h-10 rounded-xl bg-rt-red px-3 text-white">Umfang verbindlich bestätigen</button></form>
                    @else
                        <p>Die persönliche Zustimmung erfolgt durch den zugeordneten Mitarbeiter unter „Meine Geräte“.</p>
                    @endif
                @else
                    <p>Die IT muss zuerst ein Arbeitsplatzprofil festlegen. Bestehende Firmenkopplungen bleiben erhalten.</p>
                @endif
                @error('withdrawConfirmed')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
            </div>
        </x-slot:content>
        <x-slot:footer><button type="button" wire:click="$set('showModal', false)" class="min-h-10 rounded-xl border px-4">Schließen</button></x-slot:footer>
    </x-dialog-modal>
</div>
