/**
 * Erzeugt die transparente Endlosschleife der Ruhefahne — nur Rauch,
 * niemals einen zweiten Zug. Die Mailsignatur revealt sie erst nach der
 * 13-s-Hauptanimation als dauerhaft laufendes Overlay.
 *
 *   zug-dampf-idle-{light,dark}.gif
 *
 * WARUM EINE ZWEITE EBENE
 * Ein GIF kann keine Teilschleife nach einer einmaligen Einfahrt bilden.
 * Die Ebene darf nicht sofort sichtbar sein: sonst raucht es am Haltepunkt,
 * bevor der Zug dort ankommt. `render-zug-einfahrt.mjs` traegt Einfahrt und
 * zwei Idle-Zyklen bis 13 s. Erst dann wird diese Schleife eingeblendet; ihr
 * Phasenversatz setzt exakt den gehaltenen letzten Hauptframe fort.
 *
 * Und sie enthaelt AUSSCHLIESSLICH die Fahne. Eine fruehere Fassung trug
 * den kompletten Zug ein zweites Mal — sobald die Einfahrt durchsichtig
 * wurde, stand er doppelt im Bild. Dieser Fehler ist der Grund fuer die
 * strikte Trennung hier.
 *
 * NAHTLOS: Jede Wolke laeuft auf derselben periodischen Bahn, nur mit
 * eigenem Versatz. Das Bild am Ende der Laufzeit ist damit rechnerisch
 * dasselbe wie am Anfang — die Schleife hat keine Naht.
 *
 * Masse und Lage stammen aus render-zug-einfahrt.mjs und muessen mit ihm
 * uebereinstimmen, sonst sitzt die Fahne nicht auf dem Schornstein.
 *
 * Aufruf: node tools/render-zug-idle.mjs
 */
import puppeteer from 'puppeteer-core';
import gifenc from 'gifenc';
import { PNG } from 'pngjs';
import { writeFileSync, copyFileSync } from 'node:fs';

const { GIFEncoder } = gifenc;
const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ASSETS = 'resources/mail-templates/assets';
const OEFFENTLICH = 'public/mail-assets';

// --- Muss zur Einfahrt passen ----------------------------------------
const BREITE = 1440;
const SKALA = 1.5;
const WAGEN_SICHTBAR = 6;
const SICHTBARE_EINHEITEN = 708 + (WAGEN_SICHTBAR * 330);
const BASIS_ZUG_FAKTOR = 2053 / SICHTBARE_EINHEITEN;
const ZUG_MASSSTAB = 0.90;
const ZUG_FAKTOR = BASIS_ZUG_FAKTOR * ZUG_MASSSTAB;
const ZUG_BREITE = BREITE * ZUG_FAKTOR;
const ZUG_HOEHE = ZUG_BREITE * (151 / 2053);
const BASIS_ZUG_HOEHE = (BREITE * BASIS_ZUG_FAKTOR) * (151 / 2053);
const KOPFRAUM = 1.8;
const HOEHE = Math.round(BASIS_ZUG_HOEHE * KOPFRAUM);
const ZUG_Y = HOEHE - ZUG_HOEHE;
const ZIEL_RECHTS = 0.60;
const SCHORNSTEIN_X = (BREITE * ZIEL_RECHTS) - (ZUG_BREITE * 0.035);
const SCHORNSTEIN_Y = ZUG_Y + (ZUG_HOEHE * 0.16);

// --- Schleife ---------------------------------------------------------
const BILDER = 20;
const SUMME_CS = 200;                   // 2 s je Umlauf
const MASSSTAB = BREITE / 560;
// Haupt-GIF haelt nach zwei 2-s-Zyklen bei Phase 0,94. Das Overlay laeuft
// waehrend seiner 13 s Unsichtbarkeit bereits 6,5 Zyklen; +0,44 landet beim
// Reveal wieder exakt bei 0,94.
const PHASEN_OFFSET = 0.44;

const VARIANTEN = [
    { key: 'light', rauch: [90, 99, 110], grund: [255, 255, 255], tinte: [115, 125, 137] },
    { key: 'dark', rauch: [196, 206, 219], grund: [12, 16, 23], tinte: [216, 221, 228] },
];

const rausch = (i, salz = 0) => {
    const v = Math.sin(((i + 1) * 12.9898) + (salz * 78.233)) * 43758.5453;

    return v - Math.floor(v);
};

// Deutlich weniger als bei der Einfahrt: im Stand raucht eine Lok leise.
const WOLKEN = 64;
const wolken = Array.from({ length: WOLKEN }, (_, i) => ({
    versatz: i / WOLKEN,
    drift: (-8 - (rausch(i, 11) * 15)) * MASSSTAB,
    steigen: (-5 - (rausch(i, 12) * 8)) * MASSSTAB,
    wuchs: (1.8 + (rausch(i, 13) * 2.8)) * MASSSTAB,
    r0: (1.1 + (rausch(i, 14) * 1.2)) * MASSSTAB,
    phase: rausch(i, 15) * Math.PI * 2,
}));

const LEBEN_S = SUMME_CS / 100;

function verzoegerungen() {
    const grund = Math.floor(SUMME_CS / BILDER);
    const werte = new Array(BILDER).fill(grund);
    werte[BILDER - 1] += SUMME_CS - (grund * BILDER);

    return werte;
}

const cs = verzoegerungen();

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body style="margin:0"></body></html>', { waitUntil: 'load' });

for (const v of VARIANTEN) {
    const bilder = [];

    for (let i = 0; i < BILDER; i += 1) {
        const t = i / BILDER;

        const sichtbar = wolken.map((w) => {
            // Periodische Bahn: das Alter laeuft von 0 bis LEBEN_S und
            // springt dann zurueck. Weil jede Wolke einen eigenen Versatz
            // hat, ist zu jedem Zeitpunkt dieselbe Verteilung im Bild.
            const u = ((t + w.versatz + PHASEN_OFFSET) % 1);
            const alter = u * LEBEN_S;
            const r = w.r0 + (w.wuchs * alter);

            // Auf- und abblenden an den Enden der Bahn, damit keine Wolke
            // sichtbar entsteht oder verschwindet.
            const rand = Math.min(u, 1 - u) / 0.18;
            const blende = Math.min(1, Math.max(0, rand));
            const verduennung = (w.r0 / r) ** 1.35;

            return {
                x: SCHORNSTEIN_X + (w.drift * alter)
                    + (Math.sin(w.phase + (alter * 1.7)) * 2.2 * MASSSTAB * (1 + (alter * 0.5))),
                y: SCHORNSTEIN_Y + (w.steigen * alter)
                    + (Math.cos(w.phase + (alter * 1.3)) * 1.1 * MASSSTAB * (1 + (alter * 0.35))),
                r,
                alpha: blende * 0.20 * verduennung,
            };
        }).filter((z) => z.alpha > 0.004 && z.x > -60 && z.x < BREITE + 60);

        const roh = await page.evaluate((a) => {
            const c = document.createElement('canvas');
            c.width = a.breite * a.skala;
            c.height = a.hoehe * a.skala;
            const x = c.getContext('2d');
            x.scale(a.skala, a.skala);

            // Wie im Hauptgenerator zuerst auf den Theme-Grund rechnen.
            // Index 0 wird beim GIF-Encoding anschliessend transparent;
            // dadurch bleiben Rauchmaske und Farbstufen am 13-s-Handoff
            // pixelgleich, ohne einen deckenden Kasten auszuliefern.
            x.fillStyle = `rgb(${a.grund})`;
            x.fillRect(0, 0, a.breite, a.hoehe);

            for (const w of a.wolken) {
                const g = x.createRadialGradient(w.x, w.y, 0, w.x, w.y, w.r);
                g.addColorStop(0, `rgba(${a.rauch}, ${w.alpha})`);
                g.addColorStop(0.55, `rgba(${a.rauch}, ${w.alpha * 0.45})`);
                g.addColorStop(1, `rgba(${a.rauch}, 0)`);
                x.fillStyle = g;
                x.beginPath();
                x.arc(w.x, w.y, w.r, 0, Math.PI * 2);
                x.fill();
            }

            return Array.from(new Uint8Array(x.getImageData(0, 0, c.width, c.height).data.buffer));
        }, {
            breite: BREITE,
            hoehe: HOEHE,
            skala: SKALA,
            grund: v.grund.join(','),
            rauch: v.rauch.join(','),
            wolken: sichtbar,
        });

        bilder.push(new Uint8Array(roh));
    }

    // --- Farbtabelle: durchsichtig plus eine Rampe zur Rauchfarbe -------
    const STUFEN = 7;
    const tabelle = [v.grund.slice()];
    for (let k = 1; k <= STUFEN; k += 1) tabelle.push(v.rauch.slice());
    const durchsichtig = 0;

    // Die Rampe traegt die Deckung ueber die Farbe: auf hellem Grund hellt
    // der Rauch auf, auf dunklem dunkelt er ab. Weil GIF kein Alpha je
    // Bildpunkt kennt, wird die Deckkraft in die Helligkeit gerechnet.
    const grundton = v.grund;
    for (let k = 1; k <= STUFEN; k += 1) {
        const anteil = k / STUFEN;
        tabelle[k] = [
            Math.round(grundton[0] + ((v.tinte[0] - grundton[0]) * anteil)),
            Math.round(grundton[1] + ((v.tinte[1] - grundton[1]) * anteil)),
            Math.round(grundton[2] + ((v.tinte[2] - grundton[2]) * anteil)),
        ];
    }

    const encoder = GIFEncoder();

    bilder.forEach((rgba, index) => {
        const n = rgba.length / 4;
        const indizes = new Uint8Array(n);
        for (let p = 0; p < n; p += 1) {
            const r = rgba[p * 4];
            const g = rgba[(p * 4) + 1];
            const b = rgba[(p * 4) + 2];
            let beste = 0;
            let abstand = Infinity;
            for (let k = 0; k < tabelle.length; k += 1) {
                const dr = tabelle[k][0] - r;
                const dg = tabelle[k][1] - g;
                const db = tabelle[k][2] - b;
                const d = (dr * dr) + (dg * dg) + (db * db);
                if (d < abstand) { abstand = d; beste = k; }
            }
            indizes[p] = beste;
        }

        encoder.writeFrame(indizes, BREITE * SKALA, HOEHE * SKALA, {
            palette: index === 0 ? tabelle : undefined,
            delay: cs[index] * 10,
            transparent: true,
            transparentIndex: durchsichtig,
            // 2 = raeumen: die Fahne zieht, jeder Zwischenstand muss weg.
            dispose: 2,
            repeat: 0,
        });
    });

    encoder.finish();
    const gif = Buffer.from(encoder.bytes());
    writeFileSync(`${ASSETS}/zug-dampf-idle-${v.key}.gif`, gif);
    copyFileSync(`${ASSETS}/zug-dampf-idle-${v.key}.gif`, `${OEFFENTLICH}/zug-dampf-idle-${v.key}.gif`);

    console.log(`zug-dampf-idle-${v.key}.gif`.padEnd(30)
        + `${BREITE * SKALA}x${HOEHE * SKALA}, ${BILDER} Bilder, ${SUMME_CS} cs, ${(gif.length / 1024).toFixed(1)} kB`);
}

await browser.close();
console.log('Nach public/mail-assets kopiert.');
