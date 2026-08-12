/**
 * Erzeugt die RUHEFAHNE des Signaturzuges — nur Rauch, kein Zug.
 *
 *   zug-dampf-idle-{light,dark}.gif
 *
 * WARUM EINE ZWEITE EBENE
 * Ein einzelnes GIF kann nicht "einmal einfahren und danach in Schleife
 * bleiben" — es wiederholt immer die GANZE Folge. Die Einfahrt faehrt
 * deshalb einmal und bleibt stehen; die Ruhebewegung liegt als eigene,
 * endlos laufende Ebene DARUEBER.
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
const BREITE = 1080;
const SKALA = 2;
const WAGEN_SICHTBAR = 6;
const SICHTBARE_EINHEITEN = 708 + (WAGEN_SICHTBAR * 330);
const ZUG_FAKTOR = 2053 / SICHTBARE_EINHEITEN;
const ZUG_BREITE = BREITE * ZUG_FAKTOR;
const ZUG_HOEHE = ZUG_BREITE * (151 / 2053);
const KOPFRAUM = 3.2;
const HOEHE = Math.round(ZUG_HOEHE * KOPFRAUM);
const ZUG_Y = HOEHE - ZUG_HOEHE;
const SCHORNSTEIN_X = BREITE - (ZUG_BREITE * 0.035);
const SCHORNSTEIN_Y = ZUG_Y + (ZUG_HOEHE * 0.16);

// --- Schleife ---------------------------------------------------------
const BILDER = 40;
const SUMME_CS = 600;                   // 6 s je Umlauf
const MASSSTAB = BREITE / 560;

const VARIANTEN = [
    { key: 'light', rauch: [90, 99, 110] },
    { key: 'dark', rauch: [196, 206, 219] },
];

const rausch = (i, salz = 0) => {
    const v = Math.sin(((i + 1) * 12.9898) + (salz * 78.233)) * 43758.5453;

    return v - Math.floor(v);
};

// Deutlich weniger als bei der Einfahrt: im Stand raucht eine Lok leise.
const WOLKEN = 70;
const wolken = Array.from({ length: WOLKEN }, (_, i) => ({
    versatz: i / WOLKEN,
    drift: (-9 - (rausch(i, 1) * 16)) * MASSSTAB,
    steigen: (-5 - (rausch(i, 2) * 8)) * MASSSTAB,
    wuchs: (2.0 + (rausch(i, 3) * 3.0)) * MASSSTAB,
    r0: (1.2 + (rausch(i, 1) * 1.3)) * MASSSTAB,
    phase: rausch(i, 2) * Math.PI * 2,
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
            const u = ((t + w.versatz) % 1);
            const alter = u * LEBEN_S;
            const r = w.r0 + (w.wuchs * alter);

            // Auf- und abblenden an den Enden der Bahn, damit keine Wolke
            // sichtbar entsteht oder verschwindet.
            const rand = Math.min(u, 1 - u) / 0.18;
            const blende = Math.min(1, Math.max(0, rand));
            const verduennung = (w.r0 / r) ** 1.35;

            return {
                x: SCHORNSTEIN_X + (w.drift * alter)
                    + (Math.sin(w.phase + (alter * 1.7)) * 2.4 * MASSSTAB * (1 + (alter * 0.6))),
                y: SCHORNSTEIN_Y + (w.steigen * alter)
                    + (Math.cos(w.phase + (alter * 1.3)) * 1.2 * MASSSTAB * (1 + (alter * 0.4))),
                r,
                alpha: blende * 0.22 * verduennung,
            };
        }).filter((z) => z.alpha > 0.004 && z.x > -60 && z.x < BREITE + 60);

        const roh = await page.evaluate((a) => {
            const c = document.createElement('canvas');
            c.width = a.breite * a.skala;
            c.height = a.hoehe * a.skala;
            const x = c.getContext('2d');
            x.scale(a.skala, a.skala);

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
        }, { breite: BREITE, hoehe: HOEHE, skala: SKALA, rauch: v.rauch.join(','), wolken: sichtbar });

        bilder.push(new Uint8Array(roh));
    }

    // --- Farbtabelle: durchsichtig plus eine Rampe zur Rauchfarbe -------
    const STUFEN = 15;
    const tabelle = [[255, 0, 255]];
    for (let k = 1; k <= STUFEN; k += 1) tabelle.push(v.rauch.slice());
    const durchsichtig = 0;

    // Die Rampe traegt die Deckung ueber die Farbe: auf hellem Grund hellt
    // der Rauch auf, auf dunklem dunkelt er ab. Weil GIF kein Alpha je
    // Bildpunkt kennt, wird die Deckkraft in die Helligkeit gerechnet.
    const grundton = v.key === 'dark' ? [12, 16, 23] : [255, 255, 255];
    for (let k = 1; k <= STUFEN; k += 1) {
        const anteil = k / STUFEN;
        tabelle[k] = [
            Math.round(grundton[0] + ((v.rauch[0] - grundton[0]) * anteil)),
            Math.round(grundton[1] + ((v.rauch[1] - grundton[1]) * anteil)),
            Math.round(grundton[2] + ((v.rauch[2] - grundton[2]) * anteil)),
        ];
    }

    const encoder = GIFEncoder();

    bilder.forEach((rgba, index) => {
        const n = rgba.length / 4;
        const indizes = new Uint8Array(n);
        for (let p = 0; p < n; p += 1) {
            const a = rgba[(p * 4) + 3];
            if (a < 10) { indizes[p] = durchsichtig; continue; }
            indizes[p] = Math.max(1, Math.min(STUFEN, Math.round((a / 255) * STUFEN)));
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
