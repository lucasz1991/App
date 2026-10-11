<section class="space-y-5" aria-label="Mitarbeiter-Standorte">
    <header><h2 class="text-lg font-semibold">Mitarbeiter-Standorte</h2><p class="text-sm text-rt-muted">Tägliche freiwillige Freigabe auf dem zugewiesenen Geschäftshandy; ausschließlich während laufender Arbeitszeiterfassung.</p></header>
    <form wire:submit="save" class="ops-form">
        <div class="ops-full"><x-ui.forms.checkbox wire:model="config.enabled" label="Mitarbeiter-Standortdienst aktivieren" /><p class="mt-2 text-sm text-rt-muted">Erst nach Abnahme auf Geschäftshandys und Klärung des betrieblichen Zwecks einschließlich der Aufbewahrung aktivieren.</p></div>
        <div class="ops-full text-sm"><p>Intervall: 15 Minuten · Aufbewahrung: 7 Tage · erforderliche Genauigkeit: höchstens 50 Meter.</p><p>Neue Zustimmung täglich, Ende um Mitternacht (Europe/Berlin). Ohne Zustimmung bleibt die Zeiterfassung nutzbar.</p></div>
        <x-operations.field label="Erklärtext für die tägliche Freigabe" model="config.explanation_text" type="textarea" :wide="true" required />
        <p class="ops-full text-sm text-rt-muted">Aktuelle Textversion {{ $config['explanation_version'] }}. Eine Textänderung erhöht die Version und beendet laufende Standortfreigaben.</p>
        <x-operations.field label="Installationslink Android" model="config.android_install_url" type="url" :wide="true" />
        <x-operations.field label="Installationslink iPhone" model="config.ios_install_url" type="url" :wide="true" />
        <x-operations.field label="Android-Signatur (SHA-256)" model="config.android_certificate_sha256" :wide="true" />
        <x-operations.field label="Apple App-ID (Team-ID.Bundle-ID)" model="config.ios_app_id" :wide="true" />
        <p class="ops-full text-sm text-rt-muted">Zugriff auf aktuelle Standorte wird ausdrücklich über die Team-Berechtigung „Freigegebene aktuelle Mitarbeiterstandorte“ und vorhandene Personen-Zuständigkeiten vergeben. Allgemeine Adminrechte allein gewähren keinen Standortzugriff.</p>
        <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic></div>
    </form>
    <p class="text-sm" role="status">{{ $ready ? 'Standortspeicherung eingerichtet.' : 'Die Standortmigrationen müssen vor einer Aktivierung ausgeführt werden.' }}</p>
</section>
