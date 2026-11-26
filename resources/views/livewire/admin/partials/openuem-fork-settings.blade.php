<div class="space-y-4 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60" data-openuem-fork-settings>
    <div>
        <x-ui.forms.label for="openuem-adapter" :value="__('device_management.openuem_fork.adapter')" />
        <select id="openuem-adapter" wire:model.live="providers.openuem.adapter" class="mt-1.5 w-full rounded-lg border-rt-border bg-rt-surface text-sm text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text">
            <option value="connector_v1">{{ __('device_management.openuem_fork.adapter_connector') }}</option>
            <option value="native_fork_v1">{{ __('device_management.openuem_fork.adapter_native_fork') }}</option>
        </select>
        <x-ui.forms.input-error for="providers.openuem.adapter" class="mt-1" />
    </div>
    @if (($provider['adapter'] ?? '') === 'native_fork_v1')
        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            {{ __('device_management.openuem_fork.native_fork_hint') }}
        </p>
        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            {{ __('device_management.openuem_fork.profiles_hint') }}
        </p>
        <div class="space-y-3">
            @forelse (($provider['native_profiles'] ?? []) as $profileIndex => $nativeProfile)
                <fieldset wire:key="openuem-native-profile-{{ $profileIndex }}" class="space-y-2 rounded-lg border border-rt-border/70 p-3 dark:border-rt-dark-border/70">
                    <legend class="px-1 text-xs font-semibold">{{ __('device_management.openuem_fork.profile_permission') }} {{ $profileIndex + 1 }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-ui.forms.label :for="'openuem-native-agent-'.$profileIndex" value="OpenUEM Agent-ID" />
                            <x-ui.forms.input :id="'openuem-native-agent-'.$profileIndex" wire:model="providers.openuem.native_profiles.{{ $profileIndex }}.agent_id" maxlength="128" autocomplete="off" class="mt-1 font-mono" />
                            <x-ui.forms.input-error :for="'providers.openuem.native_profiles.'.$profileIndex.'.agent_id'" />
                        </div>
                        <div>
                            <x-ui.forms.label :for="'openuem-native-profile-'.$profileIndex" :value="__('device_management.openuem_fork.profile_id')" />
                            <x-ui.forms.number-input min="1" max="2147483647" :id="'openuem-native-profile-'.$profileIndex" wire:model="providers.openuem.native_profiles.{{ $profileIndex }}.profile_id" />
                            <x-ui.forms.input-error :for="'providers.openuem.native_profiles.'.$profileIndex.'.profile_id'" />
                        </div>
                    </div>
                    <x-ui.forms.label :for="'openuem-native-label-'.$profileIndex" :value="__('device_management.openuem_fork.profile_label')" />
                    <x-ui.forms.input :id="'openuem-native-label-'.$profileIndex" wire:model="providers.openuem.native_profiles.{{ $profileIndex }}.label" maxlength="120" />
                    <x-ui.forms.input-error :for="'providers.openuem.native_profiles.'.$profileIndex.'.label'" />
                    <x-ui.buttons.button-basic mode="secondary" type="button" wire:click="removeNativeProfile({{ $profileIndex }})" class="min-h-11">{{ __('device_management.openuem_fork.profile_remove') }}</x-ui.buttons.button-basic>
                </fieldset>
            @empty
                <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('device_management.openuem_fork.profiles_empty') }}</p>
            @endforelse
            <x-ui.forms.input-error for="providers.openuem.native_profiles" />
            <x-ui.buttons.button-basic mode="secondary" type="button" wire:click="addNativeProfile" wire:loading.attr="disabled" class="min-h-11">{{ __('device_management.openuem_fork.profile_add') }}</x-ui.buttons.button-basic>
        </div>
    @endif
</div>
