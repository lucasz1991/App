<div
    data-session-permissions="{{ $automatic ? 'prompt' : 'profile' }}"
    x-data="{
        dialogOpen: $wire.entangle('dialogOpen'),
        busy: null,
        generation: 0,
        browserMessage: '',
        init() {
            this.$watch('dialogOpen', (open, previous) => {
                if (!open && previous) {
                    this.generation++;
                    $wire.dismiss();
                }
            });
        },
        async allow(purpose, alreadyAccepted = false) {
            if (this.busy) return;
            this.busy = purpose;
            const generation = this.generation;
            this.browserMessage = '';
            try {
                const helper = window.RailtimeSessionPermissions;
                const permission = helper
                    ? await (purpose === 'push' ? helper.requestPushPermission() : helper.requestLocationPermission())
                    : 'unsupported';
                if (generation !== this.generation) return;
                const saved = alreadyAccepted || await $wire.decide(purpose, true);
                if (!saved) return;
                if (purpose === 'push' && permission === 'granted') {
                    await helper.subscribePush(@js($permissionClient));
                    this.browserMessage = 'Push ist für diese Anmeldesitzung auf diesem Gerät eingerichtet.';
                } else if (purpose === 'location' && permission === 'granted') {
                    this.browserMessage = 'Die Browserberechtigung ist erteilt. Der Standortdienst auf dem Geschäftshandy muss weiterhin eingerichtet und ausdrücklich gestartet werden.';
                } else if (permission === 'denied') {
                    this.browserMessage = 'Deine RailTime-Freigabe ist gespeichert. Die Geräteberechtigung ist blockiert; du kannst sie in den Browser- oder Geräteeinstellungen ändern.';
                } else if (permission === 'install-required') {
                    this.browserMessage = 'Deine RailTime-Freigabe ist gespeichert. Installiere RailTime auf dem iPhone über „Zum Home-Bildschirm“, um Push zu verwenden.';
                } else {
                    this.browserMessage = 'Deine RailTime-Freigabe ist gespeichert. Die Geräteberechtigung ist noch nicht verfügbar; die Funktion ist dadurch noch nicht aktiv.';
                }
            } catch (error) {
                this.browserMessage = 'Die Einrichtung konnte nicht abgeschlossen werden. Prüfe den Status in deinem Profil und versuche es erneut.';
            } finally {
                this.busy = null;
            }
        }
    }"
>
    @if ($automatic)
        <x-dialog-modal id="session-permissions-dialog" max-width="2xl" show-expression="dialogOpen" :instant="true">
            <x-slot name="title">Push und Standort freigeben</x-slot>
            <x-slot name="content">
                @include('livewire.settings.partials.session-permission-controls', ['permissionIdPrefix' => 'login-permissions'])
            </x-slot>
            <x-slot name="footer">
                <x-ui.buttons.button-basic mode="secondary" wire:click="dismiss" wire:loading.attr="disabled" x-bind:disabled="busy !== null" class="min-h-11 w-full rounded-xl sm:w-auto" data-session-permissions-continue>
                    {{ ($permissions['push_available'] && $permissions['push_decision'] === null) || ($permissions['location_enabled'] && $permissions['location_decision'] === null) ? 'Ohne weitere Freigabe fortfahren' : 'Fertig' }}
                </x-ui.buttons.button-basic>
            </x-slot>
        </x-dialog-modal>
    @else
        <section class="space-y-4" aria-labelledby="profile-session-permissions-heading">
            <h3 id="profile-session-permissions-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Deine Freigaben</h3>
            @include('livewire.settings.partials.session-permission-controls', ['permissionIdPrefix' => 'profile-permissions'])
        </section>
    @endif
</div>
