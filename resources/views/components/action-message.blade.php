@props(['on'])

{{--
    Fruehere Inline-Meldung ("Gespeichert.") — jetzt eine unsichtbare Bruecke:
    Das Livewire-Ereignis (z. B. 'saved') wird als Erfolgs-Toast gemeldet.
    rt-alerts.js (SweetAlert2) zeigt ihn oben rechts und spielt automatisch den fuer 'success'
    zugeordneten Ton (rt-sounds.js). Der Slot-Text bleibt je Formular anpassbar.

    Achtung: keine doppelten Anfuehrungszeichen innerhalb von x-init — das
    Attribut selbst ist mit " umschlossen (siehe InfoModalTest).
--}}
<span
    x-data
    x-init="@this.on('{{ $on }}', () => {
        window.dispatchEvent(new CustomEvent('swal:toast', {
            detail: { type: 'success', text: @js(trim($slot->isEmpty() ? __('app.saved') : (string) $slot)) },
        }));
    })"
    class="hidden"
    aria-hidden="true"
    data-action-message-toast="{{ $on }}"
></span>
