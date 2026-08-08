/**
 * Inhaltsschleuse fuer Dialoge (components/dialog-modal.blade.php).
 *
 * WARUM ES SIE GIBT
 * Die Einfahrt eines Dialogs bewegt ausschliesslich `transform` — das ist
 * billig und laeuft auf dem Compositor. Teuer wird es erst, wenn sich
 * WAEHREND dieser Bewegung die Hoehe des Panels aendert: dann rechnet der
 * Browser Zentrierung und Wegstrecke neu aus, und der Dialog springt.
 * Gemessen in echtem Chrome: ein Panel wuchs waehrend der Einfahrt von
 * 317 auf 703 Pixel, weil der Inhalt nachgeladen wurde.
 *
 * Diese Schleuse haelt die Geometrie ruhig: solange der Inhalt nicht steht,
 * zeigt der Dialogrumpf ein Skelett fester Hoehe. Erst danach kommt der
 * echte Inhalt.
 *
 * KEIN KUENSTLICHER AUFENTHALT
 * Steht der Inhalt schon beim Oeffnen — der Normalfall bei rein
 * serverseitig gerenderten Dialogen — schaltet die Schleuse noch im selben
 * Einzelbild frei. Es erscheint dann gar kein Skelett.
 */

// Laufende Livewire-Anfragen. Ein Dialog, dessen Rumpf gerade nachgeladen
// wird, gilt als noch nicht bereit.
let livewireInFlight = 0;

/** Wird einmalig aus app.js aufgerufen, sobald Livewire bereitsteht. */
export function trackLivewireRequests(Livewire) {
    Livewire.hook('request', ({ succeed, fail }) => {
        livewireInFlight += 1;

        let erledigt = false;
        const fertig = () => {
            if (erledigt) return;
            erledigt = true;
            livewireInFlight = Math.max(0, livewireInFlight - 1);
        };

        succeed(fertig);
        fail(fertig);
    });
}

// Spaetestens danach wird freigegeben — eine Schleuse darf nie klemmen.
const NOTBREMSE_MS = 2200;
// So lange muss der Rumpf unveraendert bleiben, bevor er als fertig gilt.
const RUHEZEIT_MS = 90;

export const modalBody = () => ({
    freigegeben: false,
    pruefend: false,
    groessenwaechter: null,
    inhaltswaechter: null,
    ruheZeitgeber: null,
    notbremse: null,

    init() {
        // Der Dialog liegt beim Seitenaufbau unsichtbar im DOM; init() laeuft
        // also lange vor dem Oeffnen. Als Oeffnungssignal dient die Flaeche:
        // sie ist unabhaengig davon, ob der jeweilige Dialog seinen Zustand
        // `show`, `open` oder anders nennt.
        this.groessenwaechter = new ResizeObserver(() => {
            const sichtbar = this.$el.offsetWidth > 0 && this.$el.offsetHeight > 0;

            if (sichtbar && !this.freigegeben) {
                this.pruefe();
            } else if (!sichtbar && this.freigegeben) {
                // Erst NACH der Ausfahrt: Alpine setzt display:none zum Ende
                // des Uebergangs, vorher bleibt die Flaeche bestehen. Der
                // Nutzer sieht das Zuruecksetzen deshalb nie.
                this.zuruecksetzen();
            }
        });
        this.groessenwaechter.observe(this.$el);

        if (this.$el.offsetHeight > 0) {
            this.pruefe();
        }
    },

    destroy() {
        this.groessenwaechter?.disconnect();
        this.inhaltswaechter?.disconnect();
        clearTimeout(this.ruheZeitgeber);
        clearTimeout(this.notbremse);
    },

    /** Der Rumpf, den die Schleuse verdeckt. */
    get rumpf() {
        return this.$el.querySelector('[data-rt-modal-body-content]');
    },

    /**
     * Synchrone Bereitschaftspruefung. Faellt sie positiv aus, gibt die
     * Schleuse sofort frei und es entsteht keine Verzoegerung.
     */
    istBereit() {
        if (livewireInFlight > 0) return false;

        // Schriftwechsel aendern die Zeilenumbrueche und damit die Hoehe.
        if (typeof document !== 'undefined' && document.fonts && document.fonts.status !== 'loaded') return false;

        const rumpf = this.rumpf;
        if (!rumpf) return true;

        // Ein noch leerer Rumpf bedeutet: der Inhalt kommt erst.
        if (rumpf.childElementCount === 0 && rumpf.textContent.trim() === '') return false;

        for (const bild of rumpf.querySelectorAll('img')) {
            // Bilder ohne Quelle warten auf nichts.
            if (!bild.getAttribute('src')) continue;
            if (!bild.complete) return false;
        }

        return true;
    },

    /**
     * Interpoliert der Browser gerade schon die Einfahrt?
     *
     * Alpine setzt zuerst `-enter` und `-enter-from` und erst im NAECHSTEN
     * Einzelbild `-enter-to`. Solange nur `-from` steht, hat sich noch nichts
     * bewegt: dort darf der Inhalt gefahrlos erscheinen, die Endhoehe steht
     * dann fest, bevor die Bewegung ueberhaupt beginnt.
     *
     * Ist die Fahrt dagegen im Gang, wuerde neuer Inhalt das Panel mitten in
     * der Bewegung wachsen lassen — genau der Sprung, den diese Schleuse
     * verhindern soll. Dann wird gewartet, bis die Fahrt vorbei ist.
     */
    mittenInDerFahrt() {
        const panel = this.$el.closest('[data-rt-modal-panel], .rt-modal-frame');
        if (!panel) return false;

        return panel.classList.contains('rt-motion-modal-enter')
            && panel.classList.contains('rt-motion-modal-enter-to');
    },

    pruefe() {
        if (this.pruefend || this.freigegeben) return;
        this.pruefend = true;

        // Der haeufigste Fall: der Inhalt steht bereits. Dann sofort frei —
        // ohne Skelett, ohne Wartezeit. Das passiert noch im Einzelbild des
        // Oeffnens, also bevor sich das Panel bewegt.
        if (this.istBereit()) {
            this.freigeben();
            return;
        }

        this.warte();
    },

    /**
     * Wartet, bis der Rumpf zur Ruhe gekommen ist: keine Aenderungen mehr,
     * keine offenen Bilder, keine laufende Livewire-Anfrage.
     */
    warte() {
        const rumpf = this.rumpf;

        const anstossen = () => {
            clearTimeout(this.ruheZeitgeber);
            this.ruheZeitgeber = setTimeout(() => {
                if (this.freigegeben) return;
                // Bereit UND die Einfahrt laeuft nicht gerade — sonst warten.
                if (this.istBereit() && !this.mittenInDerFahrt()) {
                    this.freigeben();
                } else {
                    anstossen();
                }
            }, RUHEZEIT_MS);
        };

        if (rumpf) {
            this.inhaltswaechter = new MutationObserver(anstossen);
            this.inhaltswaechter.observe(rumpf, { childList: true, subtree: true, characterData: true });

            // Bilder melden sich selbst, sobald sie stehen.
            for (const bild of rumpf.querySelectorAll('img')) {
                if (bild.complete) continue;
                bild.addEventListener('load', anstossen, { once: true });
                bild.addEventListener('error', anstossen, { once: true });
            }
        }

        if (typeof document !== 'undefined') document.fonts?.ready.then(anstossen).catch(() => {});
        anstossen();

        // Notbremse: lieber ein kurz springender Dialog als ein Skelett,
        // das nie verschwindet.
        this.notbremse = setTimeout(() => this.freigeben(), NOTBREMSE_MS);
    },

    freigeben() {
        if (this.freigegeben) return;

        this.freigegeben = true;
        this.pruefend = false;
        this.$el.dataset.rtState = 'ready';
        this.$el.removeAttribute('aria-busy');

        this.inhaltswaechter?.disconnect();
        this.inhaltswaechter = null;
        clearTimeout(this.ruheZeitgeber);
        clearTimeout(this.notbremse);
    },

    zuruecksetzen() {
        this.freigegeben = false;
        this.pruefend = false;
        this.$el.dataset.rtState = 'loading';
        this.$el.setAttribute('aria-busy', 'true');
    },
});
