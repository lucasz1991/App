{{-- RailTime als Web-App auf jedem Gerät: Smartphone, Tablet, Windows- oder Mac-Laptop. Die Anleitung des aktuellen Geräts wird hervorgehoben. --}}
<details class="rt-install-card" wire:ignore x-data="railtimePwaInstall({messages:{installed:'Auf diesem Gerät installiert',ready:'Bereit zur Installation',manual:'Folge der Anleitung für dein Gerät',accepted:'RailTime wird installiert.',failed:'Installation konnte nicht gestartet werden.'},targets:{ios:'#rt-install-ios',android:'#rt-install-android',desktop:'#rt-install-desktop'}})" x-bind:data-install-mode="mode" x-show="mode !== 'installed'">
    <summary class="rt-install-card__summary">
        <span class="rt-install-card__icon" aria-hidden="true"><i class="fas fa-download"></i></span>
        <span class="rt-install-card__heading">
            <strong>RailTime als App installieren</strong>
            <span>Auf Smartphone, Tablet oder Laptop – mit eigenem Symbol und schnellem Start.</span>
        </span>
        <span class="rt-install-card__status" x-text="statusText()"></span>
        <i class="fas fa-chevron-down rt-install-card__chevron" aria-hidden="true"></i>
    </summary>
    <div class="rt-install-card__body">
        <div class="rt-install-card__platforms">
            <section id="rt-install-ios" class="rt-install-platform" x-bind:data-current="install.ios ? '' : null">
                <h3><i class="fab fa-apple" aria-hidden="true"></i>iPhone & iPad</h3>
                <ol><li>In <strong>Safari</strong> öffnen</li><li><strong>Teilen</strong> antippen</li><li><strong>Zum Home-Bildschirm</strong> wählen</li></ol>
            </section>
            <section id="rt-install-android" class="rt-install-platform" x-bind:data-current="install.android ? '' : null">
                <h3><i class="fab fa-android" aria-hidden="true"></i>Android</h3>
                <ol><li>In <strong>Chrome</strong> öffnen</li><li>Menü <strong>⋮</strong> antippen</li><li><strong>App installieren</strong> wählen</li></ol>
            </section>
            <section id="rt-install-desktop" class="rt-install-platform" x-bind:data-current="install.desktop ? '' : null">
                <h3><i class="fas fa-laptop" aria-hidden="true"></i>Windows & Mac</h3>
                <ol><li>In <strong>Chrome</strong> oder <strong>Edge</strong>: Installieren-Symbol in der Adressleiste</li><li>In <strong>Safari</strong> (Mac): Ablage → <strong>Zum Dock hinzufügen</strong></li></ol>
            </section>
        </div>
        <footer class="rt-install-card__footer">
            <p class="ops-muted">Für die Standortfreigabe während der Arbeitszeit richtest du auf dem Mobiltelefon zusätzlich den Standortdienst ein.</p>
            <x-ui.buttons.button-basic mode="primary" x-on:click="installApp()" x-bind:disabled="disabled" x-text="mode === 'prompt' ? 'Jetzt installieren' : (mode === 'installed' ? 'Installiert' : 'Anleitung für mein Gerät')" class="min-h-11">Anleitung für mein Gerät</x-ui.buttons.button-basic>
        </footer>
        <p role="status" class="rt-install-card__notice" x-show="notice" x-text="notice"></p>
        <p role="alert" class="rt-install-card__notice" x-show="error" x-text="error"></p>
    </div>
</details>
