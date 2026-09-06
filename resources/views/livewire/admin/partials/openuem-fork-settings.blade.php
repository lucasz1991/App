<div class="space-y-4 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60" data-openuem-fork-settings>
    <div>
        <x-ui.forms.label for="openuem-adapter" :value="$isGerman ? 'OpenUEM-Anbindung' : 'OpenUEM adapter'" />
        <select id="openuem-adapter" wire:model.live="providers.openuem.adapter" class="mt-1.5 w-full rounded-lg border-rt-border bg-rt-surface text-sm text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text">
            <option value="connector_v1">{{ $isGerman ? 'Bisheriger Connector-Vertrag' : 'Legacy connector contract' }}</option>
            <option value="native_fork_v1">{{ $isGerman ? 'RailTime-Fork · Windows-Profilaufträge v1' : 'RailTime fork · Windows profile runs v1' }}</option>
        </select>
        <x-input-error for="providers.openuem.adapter" class="mt-1" />
    </div>
    @if (($provider['adapter'] ?? '') === 'native_fork_v1')
        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            {{ $isGerman ? 'Benötigt den erweiterten Worker und Windows-Agenten mit eigenem Auftragsjournal. Server-Funktionstest und Geräteausführung sind getrennte Nachweise. Keine automatische Installation oder Produktionsfreigabe; Fernwartung bleibt separat.' : 'Requires the extended worker and Windows agent with a durable run journal. Server health and device execution are separate proofs. No automatic installation or production approval; remote support remains separate.' }}
        </p>
        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            {{ $isGerman ? 'Nur bestehende, im Worker ebenfalls freigegebene Profile eintragen. Keine automatisch oder über Tags verteilten Profile. Agent-ID ist die technische OpenUEM-ID, nicht die RailTime-Geräte-ID. Ergebnisse werden abgefragt; kein Webhook-Geheimnis nötig.' : 'Only existing profiles also allowed by the worker. Do not use automatic or tag-applied profiles. Agent ID means the native OpenUEM ID, not the RailTime device ID. Results are polled; no webhook secret is needed.' }}
        </p>
        <div class="space-y-3">
            @forelse (($provider['native_profiles'] ?? []) as $profileIndex => $nativeProfile)
                <fieldset wire:key="openuem-native-profile-{{ $profileIndex }}" class="space-y-2 rounded-lg border border-rt-border/70 p-3 dark:border-rt-dark-border/70">
                    <legend class="px-1 text-xs font-semibold">{{ $isGerman ? 'Profilfreigabe' : 'Profile permission' }} {{ $profileIndex + 1 }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-ui.forms.label :for="'openuem-native-agent-'.$profileIndex" value="OpenUEM Agent-ID" />
                            <x-ui.forms.input :id="'openuem-native-agent-'.$profileIndex" wire:model="providers.openuem.native_profiles.{{ $profileIndex }}.agent_id" maxlength="128" autocomplete="off" class="mt-1 font-mono" />
                            <x-input-error :for="'providers.openuem.native_profiles.'.$profileIndex.'.agent_id'" />
                        </div>
                        <div>
                            <x-ui.forms.label :for="'openuem-native-profile-'.$profileIndex" :value="$isGerman ? 'OpenUEM Profil-ID' : 'OpenUEM profile ID'" />
                            <x-ui.forms.input type="number" min="1" max="2147483647" :id="'openuem-native-profile-'.$profileIndex" wire:model="providers.openuem.native_profiles.{{ $profileIndex }}.profile_id" class="mt-1" />
                            <x-input-error :for="'providers.openuem.native_profiles.'.$profileIndex.'.profile_id'" />
                        </div>
                    </div>
                    <x-ui.forms.label :for="'openuem-native-label-'.$profileIndex" :value="$isGerman ? 'Bezeichnung (optional)' : 'Label (optional)'" />
                    <x-ui.forms.input :id="'openuem-native-label-'.$profileIndex" wire:model="providers.openuem.native_profiles.{{ $profileIndex }}.label" maxlength="120" />
                    <x-input-error :for="'providers.openuem.native_profiles.'.$profileIndex.'.label'" />
                    <button type="button" wire:click="removeNativeProfile({{ $profileIndex }})" class="rt-ui-button rt-ui-button-secondary min-h-11">{{ $isGerman ? 'Freigabe entfernen' : 'Remove permission' }}</button>
                </fieldset>
            @empty
                <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Noch kein Geräteprofil freigegeben.' : 'No device profiles allowed yet.' }}</p>
            @endforelse
            <x-input-error for="providers.openuem.native_profiles" />
            <button type="button" wire:click="addNativeProfile" wire:loading.attr="disabled" class="rt-ui-button rt-ui-button-secondary min-h-11">{{ $isGerman ? 'Profilfreigabe hinzufügen' : 'Add profile permission' }}</button>
        </div>
    @endif
</div>
