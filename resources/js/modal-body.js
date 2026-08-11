/**
 * Inhaltsschleuse fuer Dialoge (components/dialog-modal.blade.php).
 *
 * WARUM ES SIE GIBT
 * Die Einfahrt eines Dialogs bewegt ausschliesslich `transform`. Aendert sich
 * WAEHREND dieser Bewegung die Hoehe des Panels — weil Livewire-Inhalt
 * nachkommt oder ein Bild fertig laedt — rechnet der Browser Zentrierung und
 * Wegstrecke neu aus, und der Dialog springt. Gemessen in echtem Chrome:
 * ein Panel wuchs waehrend der Einfahrt von 317 auf 703 Pixel.
 *
 * Deshalb verdeckt die Schleuse den Rumpf, bis er steht — aber NUR waehrend
 * der Einfahrt.
 *
 * ZWEI REGELN, die aus einem echten Fehler entstanden sind
 *
 * 1. SIE VERSAGT OFFEN. Der Grundzustand ist `ready`, nicht `loading`. Auf
 *    `loading` schaltet ausschliesslich diese Datei, und nur nachdem sie
 *    positiv festgestellt hat, dass der Inhalt noch nicht steht. Ein Fehler
 *    fuehrt damit hoechstens dazu, dass die Einfahrt einmal ruckelt — nie
 *    dazu, dass Inhalt dauerhaft verdeckt bleibt.
 *
 * 2. SIE GREIFT NUR EINMAL JE OEFFNUNG. Ist der Rumpf freigegeben, ist die
 *    Schleuse fuer diese Oeffnung erledigt. Aendert sich der Inhalt danach —
 *    etwa weil im Dialog "Teams und Rechte" ein anderes Team gewaehlt wird —
 *    ist das gewoehnliche Bedienung und darf nie verdeckt werden.
 *
 * Regel 2 war der Fehler: Livewire schreibt beim Neurendern das serverseitige
 * Markup zurueck, also auch das Zustandsattribut. Stand dort `loading`, blieb
 * das Skelett fuer immer stehen, weil die Komponente sich laengst fuer fertig
 * hielt und kein Zeitgeber mehr lief. Der Attributwaechter weiter unten
 * stellt den Zustand nach jedem solchen Neuschreiben wieder her.
 */

// Laufende Livewire-Anfragen. Ein Dialog, dessen Rumpf gerade nachgeladen
// wird, gilt als noch nicht bereit.
let livewireInFlight = 0;

// Sicherung gegen einen haengenden Zaehler: bricht eine Anfrage ab, ohne
// succeed oder fail zu melden, wuerde sie sonst JEDE Schleuse blockieren.
const ANFRAGE_HOECHSTDAUER_MS = 4000;

/** Wird einmalig aus app.js aufgerufen, sobald Livewire bereitsteht. */
export function trackLivewireRequests(Livewire) {
    Livewire.hook('request', ({ succeed, fail }) => {
        livewireInFlight += 1;

        let erledigt = false;
        const fertig = () => {
            if (erledigt) return;
            erledigt = true;
            clearTimeout(sicherung);
            livewireInFlight = Math.max(0, livewireInFlight - 1);
        };

        const sicherung = setTimeout(fertig, ANFRAGE_HOECHSTDAUER_MS);

        succeed(fertig);
        fail(fertig);
    });
}

// Spaetestens danach wird freigegeben. Bewusst knapp: die Einfahrt dauert
// 460 ms, laenger darf ein Skelett den Rumpf nie verdecken.
const NOTBREMSE_MS = 900;
// So lange muss der Rumpf unveraendert bleiben, bevor er als fertig gilt.
const RUHEZEIT_MS = 90;

export const modalBody = () => ({
    // 'ready' oder 'loading' — die Wahrheit liegt hier, das Attribut ist nur
    // deren Abbild fuer das Stylesheet.
    zustand: 'ready',
    // Ist die Schleuse fuer die laufende Oeffnung schon durch?
    erledigt: true,
    schreibtSelbst: false,
    groessenwaechter: null,
    inhaltswaechter: null,
    attributwaechter: null,
    ruheZeitgeber: null,
    notbremse: null,

    init() {
        // Der Dialog liegt beim Seitenaufbau unsichtbar im DOM; init() laeuft
        // also lange vor dem Oeffnen. Als Oeffnungssignal dient die Flaeche:
        // sie ist unabhaengig davon, ob der jeweilige Dialog seinen Zustand
        // `show`, `open` oder anders nennt.
        this.groessenwaechter = new ResizeObserver(() => {
            const sichtbar = this.$el.offsetWidth > 0 && this.$el.offsetHeight > 0;

            if (sichtbar && this.erledigt && this.zustand === 'ready' && !this.warArmiert) {
                // Frisch geoeffnet.
                this.oeffne();
            } else if (!sichtbar) {
                // Erst NACH der Ausfahrt: Alpine setzt display:none zum Ende
                // des Uebergangs. Der Nutzer sieht das Zuruecksetzen nie.
                this.schliesse();
            }
        });
        this.groessenwaechter.observe(this.$el);

        // Livewire schreibt beim Neurendern das serverseitige Markup zurueck
        // und damit auch dieses Attribut. Ohne diesen Waechter bliebe der
        // Rumpf danach verdeckt — genau der Fehler im Dialog "Teams und
        // Rechte" beim Wechsel des Teams.
        if (typeof MutationObserver !== 'undefined') {
            this.attributwaechter = new MutationObserver(() => {
                if (this.schreibtSelbst) return;
                if (this.$el.dataset.rtState !== this.zustand) this.spiegele();
            });
            this.attributwaechter.observe(this.$el, {
                attributes: true,
                attributeFilter: ['data-rt-state'],
            });
        }

        if (this.$el.offsetHeight > 0) this.oeffne();
    },

    destroy() {
        this.groessenwaechter?.disconnect();
        this.inhaltswaechter?.disconnect();
        this.attributwaechter?.disconnect();
        clearTimeout(this.ruheZeitgeber);
        clearTimeout(this.notbremse);
    },

    /** Schreibt den Zustand ins Attribut, ohne den Waechter auszuloesen. */
    spiegele() {
        this.schreibtSelbst = true;
        this.$el.dataset.rtState = this.zustand;

        if (this.zustand === 'loading') this.$el.setAttribute('aria-busy', 'true');
        else this.$el.removeAttribute('aria-busy');

        // Erst im naechsten Takt wieder lauschen, sonst meldet der Waechter
        // die eigene Schreibung.
        setTimeout(() => { this.schreibtSelbst = false; }, 0);
    },

    /** Der Rumpf, den die Schleuse verdeckt. */
    get rumpf() {
        return this.$el.querySelector('[data-rt-modal-body-content]');
    },

    /**
     * Synchrone Bereitschaftspruefung. Faellt sie positiv aus, bleibt es beim
     * Grundzustand `ready` und es entsteht weder Skelett noch Verzoegerung.
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
     * bewegt: dort darf der Inhalt gefahrlos erscheinen. Ist die Fahrt
     * dagegen im Gang, wuerde neuer Inhalt das Panel mitten in der Bewegung
     * wachsen lassen.
     */
    mittenInDerFahrt() {
        const panel = this.$el.closest('[data-rt-modal-panel], .rt-modal-frame');
        if (!panel) return false;

        return panel.classList.contains('rt-motion-modal-enter')
            && panel.classList.contains('rt-motion-modal-enter-to');
    },

    /** Der Dialog wird sichtbar: einmalig pruefen. */
    oeffne() {
        this.warArmiert = true;

        // Der haeufigste Fall: der Inhalt steht bereits. Dann bleibt alles,
        // wie es ist — kein Skelett, keine Wartezeit.
        if (this.istBereit()) {
            this.erledigt = true;
            return;
        }

        this.erledigt = false;
        this.zustand = 'loading';
        this.spiegele();
        this.warte();
    },

    /** Der Dialog ist wieder unsichtbar: fuer die naechste Oeffnung schaerfen. */
    schliesse() {
        this.warArmiert = false;
        this.erledigt = true;
        this.inhaltswaechter?.disconnect();
        this.inhaltswaechter = null;
        clearTimeout(this.ruheZeitgeber);
        clearTimeout(this.notbremse);

        if (this.zustand !== 'ready') {
            this.zustand = 'ready';
            this.spiegele();
        }
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
                if (this.erledigt) return;
                // Bereit UND die Einfahrt laeuft nicht gerade — sonst warten.
                if (this.istBereit() && !this.mittenInDerFahrt()) this.freigeben();
                else anstossen();
            }, RUHEZEIT_MS);
        };

        if (rumpf && typeof MutationObserver !== 'undefined') {
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

        // Notbremse: lieber eine einmal ruckelnde Einfahrt als ein Skelett,
        // das stehen bleibt. Sie ist IMMER scharf, solange verdeckt wird.
        this.notbremse = setTimeout(() => this.freigeben(), NOTBREMSE_MS);
    },

    freigeben() {
        if (this.erledigt) return;

        this.erledigt = true;
        this.zustand = 'ready';
        this.spiegele();

        // Das Einblenden laeuft ueber eine eigene Klasse, nicht ueber den
        // Zustand: sonst blendete der Rumpf bei jedem Livewire-Neurendern
        // erneut ein, weil das Attribut dabei neu geschrieben wird.
        this.$el.classList.add('rt-modal-body-reveal');
        setTimeout(() => this.$el.classList.remove('rt-modal-body-reveal'), 400);

        this.inhaltswaechter?.disconnect();
        this.inhaltswaechter = null;
        clearTimeout(this.ruheZeitgeber);
        clearTimeout(this.notbremse);
    },
});
