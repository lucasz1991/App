@can('devices.manage')
    <x-dialog-modal id="device-provider-link-modal" wire:model.live="showProviderLinkForm" maxWidth="2xl">
        <x-slot name="title">
            Provider mit Gerät verknüpfen
        </x-slot>

        <x-slot name="content">
            <form id="device-provider-link-form" wire:submit="saveProviderLink" class="space-y-5" data-device-provider-link-form>
                <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                    Trage die native Geräte-ID aus dem jeweiligen System ein. RailTime überschreibt eine bestehende, abweichende Bindung niemals still und speichert hier keine Provider-Zugangsdaten.
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.forms.label for="device-provider-link-provider" value="Provider" />
                        <x-ui.forms.select id="device-provider-link-provider" wire:model.live="providerLinkForm.provider">
                            <option value="">Bitte auswählen</option>
                            @foreach($compatibleProviders->where('key', '!=', 'simulation') as $provider)
                                <option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>
                            @endforeach
                        </x-ui.forms.select>
                        <x-ui.forms.input-error for="providerLinkForm.provider" class="mt-1.5" />
                    </div>
                    <div>
                        <x-ui.forms.label for="device-provider-link-role" value="Rolle" />
                        <x-ui.forms.select id="device-provider-link-role" wire:model="providerLinkForm.role">
                            <option value="support">Support / Zusatzsystem</option>
                            <option value="primary">Primäre Geräteverwaltung</option>
                        </x-ui.forms.select>
                        <x-ui.forms.input-error for="providerLinkForm.role" class="mt-1.5" />
                    </div>
                </div>

                <div>
                    <x-ui.forms.label for="device-provider-link-external-id" value="Native Geräte-ID" />
                    <x-ui.forms.input
                        id="device-provider-link-external-id"
                        type="text"
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        placeholder="node/domain/…"
                        wire:model="providerLinkForm.external_device_id"
                        class="mt-1.5 font-mono"
                    />
                    <x-ui.forms.input-error for="providerLinkForm.external_device_id" class="mt-1.5" />
                    @if(($providerLinkForm['provider'] ?? '') === 'meshcentral')
                        <p class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die vollständige Node-ID findest du in MeshCentral am bereits installierten Agenten. Generische Gruppen-Einladungslinks werden aus Sicherheitsgründen nicht als Gerätebindung akzeptiert.</p>
                    @endif
                </div>
            </form>
        </x-slot>

        <x-slot name="footer">
            <x-ui.buttons.button-basic mode="secondary" type="button" wire:click="closeProviderLink" class="min-h-11">
                Abbrechen
            </x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic mode="primary" type="submit" form="device-provider-link-form" wire:loading.attr="disabled" wire:target="saveProviderLink" :disabled="blank($providerLinkForm['provider'] ?? '') || blank($providerLinkForm['external_device_id'] ?? '')" class="min-h-11">
                <i class="far fa-link h-4 w-4 text-sm leading-4 text-center" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="saveProviderLink">Verknüpfung speichern</span>
                <span wire:loading wire:target="saveProviderLink">Wird geprüft …</span>
            </x-ui.buttons.button-basic>
        </x-slot>
    </x-dialog-modal>
@endcan
