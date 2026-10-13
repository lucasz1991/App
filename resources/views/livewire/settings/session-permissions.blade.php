<div
    data-session-permissions="{{ $automatic ? 'prompt' : 'profile' }}"
    x-data="{
        automatic: @js($automatic),
        dialogOpen: $wire.entangle('dialogOpen'),
        busy: null,
        generation: 0,
        finishing: false,
        browserMessage: '',
        init() {
            this.$watch('dialogOpen', (open, previous) => {
                if (!open && previous && !this.finishing) {
                    this.generation++;
                    this.dismissAfterClose();
                }
            });
        },
        destroy() {
            this.generation++;
        },
        async dismissAfterClose() {
            try {
                await $wire.dismiss();
            } catch (error) {
                window.dispatchEvent(new CustomEvent('swal:toast', { detail: { type: 'error', text: 'Deine Entscheidung konnte nicht gespeichert werden. Bitte prüfe deine Freigaben im Profil.' } }));
            }
        },
        requestPermissions(purposes) {
            const helper = window.RailtimeSessionPermissions;
            // Start both requests directly in the click, before the first await.
            return Promise.all(purposes.map(purpose => {
                try {
                    return Promise.resolve(helper
                        ? (purpose === 'push' ? helper.requestPushPermission() : helper.requestLocationPermission())
                        : 'unsupported').catch(() => 'unavailable');
                } catch (error) {
                    return Promise.resolve('unavailable');
                }
            }));
        },
        async allow(purpose, alreadyAccepted = false) {
            if (this.busy) return;
            this.busy = purpose;
            const generation = this.generation;
            this.browserMessage = '';
            try {
                const permissions = await this.requestPermissions([purpose]);
                if (generation !== this.generation) return;
                const saved = alreadyAccepted || await $wire.decide(purpose, true);
                if (!saved || generation !== this.generation) return;
                await this.setup([purpose], permissions, generation);
                if (generation === this.generation) await this.finish(generation);
            } catch (error) {
                if (generation === this.generation) this.browserMessage = 'Die Einrichtung konnte nicht abgeschlossen werden. Bitte versuche es erneut.';
            } finally {
                this.busy = null;
            }
        },
        async allowAll(purposes) {
            if (this.busy) return;
            this.busy = 'all';
            const generation = this.generation;
            this.browserMessage = '';
            try {
                const permissions = await this.requestPermissions(purposes);
                if (generation !== this.generation) return;
                const saved = await $wire.acceptAll(purposes);
                if (!saved || generation !== this.generation) return;
                await this.setup(purposes, permissions, generation);
                if (generation === this.generation) await this.finish(generation);
            } catch (error) {
                if (generation === this.generation) this.browserMessage = 'Die Freigaben konnten nicht gespeichert werden. Bitte versuche es erneut.';
            } finally {
                this.busy = null;
            }
        },
        async setup(purposes, permissions, generation) {
            const messages = [];
            for (let index = 0; index < purposes.length; index++) {
                if (generation !== this.generation) return;
                const purpose = purposes[index];
                const permission = permissions[index];
                const label = purpose === 'push' ? 'Push' : 'Standort';
                if (purpose === 'push' && permission === 'granted') {
                    let subscription = null;
                    try { subscription = await window.RailtimeSessionPermissions.subscribePush(@js($permissionClient)); } catch (error) {}
                    if (generation !== this.generation) return;
                    messages.push(subscription ? 'Push ist auf diesem Gerät eingerichtet.' : 'Push-Freigabe gespeichert. Die Geräteeinrichtung ist noch offen; bitte im Profil prüfen.');
                } else if (purpose === 'location' && permission === 'granted') {
                    window.dispatchEvent(new CustomEvent('railtime-location-browser-permission-granted'));
                    messages.push('Standort-Freigabe gespeichert. Im Standby werden keine neuen Positionen erfasst.');
                } else if (permission === 'denied') {
                    messages.push(label + '-Freigabe gespeichert. Die Geräteberechtigung ist blockiert; ändere sie bei Bedarf in den Geräteeinstellungen.');
                } else if (permission === 'install-required') {
                    messages.push('Push-Freigabe gespeichert. Installiere RailTime auf dem iPhone über „Zum Home-Bildschirm“, um Push zu verwenden.');
                } else {
                    messages.push(label + '-Freigabe gespeichert. Die Geräteberechtigung ist noch nicht verfügbar; die Funktion ist noch nicht aktiv.');
                }
            }
            this.browserMessage = messages.join(' ');
        },
        async decline(purpose) {
            if (this.busy) return;
            this.busy = purpose;
            const generation = this.generation;
            this.browserMessage = '';
            try {
                const saved = await $wire.decide(purpose, false);
                if (saved && generation === this.generation) await this.finish(generation);
            } catch (error) {
                if (generation === this.generation) this.browserMessage = 'Deine Entscheidung konnte nicht gespeichert werden. Bitte versuche es erneut.';
            } finally {
                this.busy = null;
            }
        },
        async continueWithout() {
            if (this.busy) return;
            this.busy = 'continue';
            this.finishing = true;
            try {
                await $wire.dismiss();
                this.dialogOpen = false;
                await this.$nextTick();
            } catch (error) {
                this.browserMessage = 'Deine Entscheidung konnte nicht gespeichert werden. Bitte versuche es erneut.';
            } finally {
                this.finishing = false;
                this.busy = null;
            }
        },
        async finish(generation) {
            if (!this.automatic || generation !== this.generation) return;
            this.finishing = true;
            let completed = false;
            try {
                completed = await $wire.complete();
                if (completed && generation === this.generation) {
                    this.dialogOpen = false;
                    await this.$nextTick();
                    if (this.browserMessage) window.dispatchEvent(new CustomEvent('swal:toast', { detail: { type: 'info', text: this.browserMessage } }));
                }
            } finally {
                this.finishing = false;
                if (!completed && !this.dialogOpen && generation === this.generation) {
                    this.generation++;
                    await this.dismissAfterClose();
                }
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
                @php
                    $availablePurposes = array_values(array_filter(['push', 'location'], fn ($purpose) => $purpose === 'push' ? $permissions['push_available'] : $permissions['location_enabled']));
                @endphp
                @if ($availablePurposes !== [])
                    <x-ui.buttons.button-basic mode="success" :x-on:click="'allowAll('.\Illuminate\Support\Js::from($availablePurposes).')'" wire:loading.attr="disabled" x-bind:disabled="busy !== null" x-bind:aria-busy="busy === 'all'" class="min-h-11 w-full rounded-xl sm:w-auto" data-permission-allow-all>
                        <i class="fas fa-check" x-show="busy !== 'all'" aria-hidden="true"></i>
                        <i class="fas fa-spinner fa-spin" x-cloak x-show="busy === 'all'" aria-hidden="true"></i>
                        <span>Zustimmen</span>
                    </x-ui.buttons.button-basic>
                @endif
                <x-ui.buttons.button-basic mode="secondary" x-on:click="continueWithout()" wire:loading.attr="disabled" x-bind:disabled="busy !== null" class="min-h-11 w-full rounded-xl sm:w-auto" data-session-permissions-continue>
                    <i class="fas fa-times" aria-hidden="true"></i>
                    <span>Ablehnen</span>
                </x-ui.buttons.button-basic>
            </x-slot>
        </x-dialog-modal>
    @else
        <section class="space-y-3" aria-label="Push und Standort">
            @include('livewire.settings.partials.session-permission-controls', ['permissionIdPrefix' => 'profile-permissions'])
        </section>
    @endif
</div>
