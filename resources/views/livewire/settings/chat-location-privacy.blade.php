<section class="space-y-3" aria-labelledby="chat-location-privacy-title">
    <h3 id="chat-location-privacy-title" class="font-semibold">Chat-Standorte im Personalprofil</h3>
    <p class="text-sm text-rt-text-muted dark:text-rt-dark-text-muted">Du entscheidest beim Teilen im Chat zusätzlich, ob präzise Messpunkte zur Einsatzkoordination sieben Tage unter „Betrieb → Standorte“ für berechtigte Administratoren sichtbar sein dürfen. Diese Freigabe ist unabhängig vom Arbeitsstandortdienst. Aus Messpunkten werden keine Arbeitszeiten abgeleitet.</p>
    @if($ready)
        <p class="text-sm" role="status">{{ $shareCount }} Freigaben · {{ $pointCount }} Messpunkte innerhalb der Aufbewahrungsfrist.</p>
        <x-ui.buttons.button-basic type="button" mode="basic" wire:click="revokeAll" wire:loading.attr="disabled"
            wire:confirm="Alle Freigaben für Chat-Standorte im Personalprofil widerrufen und diese gespeicherten Messpunkte löschen? Die Standortfreigaben im Chat selbst bleiben bestehen."
            :disabled="$shareCount > 0 ? null : true">
            Freigaben widerrufen und Verlauf löschen
        </x-ui.buttons.button-basic>
        <p class="text-sm text-rt-text-muted dark:text-rt-dark-text-muted">Du kannst auch nach Ende einer Standortfreigabe oder nach Verlassen des Chats widerrufen. Die Standortfreigabe für Chat-Teilnehmer bleibt davon unberührt.</p>
    @else
        <p class="text-sm" role="status">Der Chat-Standortverlauf ist noch nicht eingerichtet.</p>
    @endif
</section>
