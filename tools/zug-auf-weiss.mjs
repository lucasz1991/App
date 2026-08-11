/**
 * Setzt den Signaturzug von BEIGEM auf WEISSEN Grund — und erzeugt das
 * Standbild ohne Rauch.
 *
 * WARUM NICHT NEU RENDERN
 * Die Einfahrt ist eine gewachsene, abgestimmte Animation. Sie neu zu
 * rechnen hiesse, ihre Choreografie erneut zu treffen. Der Grundwechsel
 * braucht das nicht: Der Zug ist auf #f7f6f3 gerechnet, Weiss ist
 * #ffffff — ein Unterschied von nur 8/9/12 je Kanal. Wird dieser Abstand
 * auf JEDEN Palettenwert addiert, landet der Grund exakt auf Weiss und die
 * Tinte wird um denselben, kaum messbaren Betrag heller. Die Kanten
 * bleiben stimmig, weil die Verschiebung fuer alle Mischwerte gilt.
 *
 * DAS STANDBILD DAGEGEN WIRD NEU GEZEICHNET — ohne Rauch.
 * Gemeldet war, dass der Uebergang von der Idle-Fahne zum Standbild beim
 * Rauch nicht passt. Wo keine Fahne laufen kann, soll auch keine stehen.
 * Gezeichnet wird aus zug-dampf-ohne-rauch.svg in exakt der Ruhelage der
 * Einfahrt, damit beide deckungsgleich sind.
 *
 * Aufruf: node tools/zug-auf-weiss.mjs
 */
import puppeteer from 'puppeteer-core';
import { PNG } from 'pngjs';
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';

const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ASSETS = 'resources/mail-templates/assets';
const OEFFENTLICH = 'public/mail-assets';

// Die Geometrie der Einfahrt — dieselben Werte wie im Generator, damit das
// Standbild und das letzte Einzelbild des GIF uebereinanderliegen.
const BREITE = 720;
const HOEHE = 75;
const ZUG_BREITE = 1020;
const RUHE_X = -331;
const SKALA = 2;

const VARIANTEN = [
    { key: 'light', alt: [0xf7, 0xf6, 0xf2], neu: [0xff, 0xff, 0xff], deckkraft: 0.30 },
    // Dunkel bleibt, wie es ist: dort war nie Beige im Spiel.
    { key: 'dark', alt: null, neu: [0x0c, 0x10, 0x17], deckkraft: 0.26 },
];

/* ------------------------------------------------------------------ *
 * 1. Palette verschieben
 * ------------------------------------------------------------------ */

/**
 * Verschiebt JEDE Farbtabelle eines GIF um einen festen Abstand — die
 * globale UND die lokalen.
 *
 * Die lokalen sind der eigentliche Punkt: Die Einfahrt legt je Einzelbild
 * eine eigene Tabelle an. Wer nur die globale verschiebt, aendert nichts
 * am Bild (nachgemessen: der Grund blieb bei 247,246,242).
 */
function grundWechseln(pfad, alt, neu) {
    const b = readFileSync(pfad);
    const dr = neu[0] - alt[0];
    const dg = neu[1] - alt[1];
    const db = neu[2] - alt[2];
    const klemm = (w) => Math.max(0, Math.min(255, w));

    let tabellen = 0;
    let farben = 0;

    const schiebe = (start, anzahl) => {
        tabellen += 1;
        farben += anzahl;
        for (let i = 0; i < anzahl; i += 1) {
            const p = start + (i * 3);
            b[p] = klemm(b[p] + dr);
            b[p + 1] = klemm(b[p + 1] + dg);
            b[p + 2] = klemm(b[p + 2] + db);
        }
    };

    const flags = b[10];
    let i = 13;

    if (flags & 0x80) {
        const anzahl = 2 << (flags & 7);
        schiebe(13, anzahl);
        i = 13 + (3 * anzahl);
    }

    while (i < b.length) {
        const typ = b[i];

        if (typ === 0x3b) break;

        if (typ === 0x21) {
            i += 2;
            i += 1 + b[i];
            while (b[i] !== 0) i += 1 + b[i];
            i += 1;
            continue;
        }

        if (typ === 0x2c) {
            const lokal = b[i + 9];
            i += 10;
            if (lokal & 0x80) {
                const anzahl = 2 << (lokal & 7);
                schiebe(i, anzahl);
                i += 3 * anzahl;
            }
            i += 1;                       // LZW-Mindestcodelaenge
            while (b[i] !== 0) i += 1 + b[i];
            i += 1;
            continue;
        }

        i += 1;
    }

    writeFileSync(pfad, b);

    return { tabellen, farben };
}

for (const v of VARIANTEN) {
    if (v.alt === null) continue;

    for (const name of [`zug-dampf-${v.key}.gif`, `zug-dampf-idle-${v.key}.gif`, `zug-dampf-outlook-${v.key}.gif`]) {
        const { tabellen, farben } = grundWechseln(`${ASSETS}/${name}`, v.alt, v.neu);
        console.log(`${name.padEnd(30)} ${tabellen} Farbtabellen, ${farben} Werte verschoben`);
    }
}

/* ------------------------------------------------------------------ *
 * 2. Standbild ohne Rauch
 * ------------------------------------------------------------------ */

const svg = readFileSync(`${ASSETS}/zug-dampf-ohne-rauch.svg`, 'utf8')
    .replace('viewBox="0 0 2053 151"', 'viewBox="0 0 2053 151" width="2053" height="151"');

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body style="margin:0"></body></html>', { waitUntil: 'load' });

await page.evaluate((markup) => new Promise((res, rej) => {
    const i = new Image();
    i.onload = () => { window.rtZug = i; res(); };
    i.onerror = rej;
    i.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
}), svg);

for (const v of VARIANTEN) {
    const roh = await page.evaluate((a) => {
        const c = document.createElement('canvas');
        c.width = a.breite * a.skala;
        c.height = a.hoehe * a.skala;
        const x = c.getContext('2d');
        x.scale(a.skala, a.skala);

        x.fillStyle = a.grund;
        x.fillRect(0, 0, a.breite, a.hoehe);

        // KEIN Rauch. Nur der Zug in Ruhelage.
        x.globalAlpha = a.deckkraft;
        x.drawImage(window.rtZug, a.zugX, 0, a.zugBreite, a.hoehe);
        x.globalAlpha = 1;

        return Array.from(new Uint8Array(x.getImageData(0, 0, c.width, c.height).data.buffer));
    }, {
        breite: BREITE, hoehe: HOEHE, skala: SKALA,
        grund: `rgb(${v.neu.join(',')})`,
        deckkraft: v.deckkraft,
        zugX: RUHE_X, zugBreite: ZUG_BREITE,
    });

    const png = new PNG({ width: BREITE * SKALA, height: HOEHE * SKALA });
    png.data.set(new Uint8Array(roh));
    const bytes = PNG.sync.write(png, { deflateLevel: 9 });
    writeFileSync(`${ASSETS}/zug-dampf-${v.key}.png`, bytes);

    console.log(`zug-dampf-${v.key}.png`.padEnd(30) + `${BREITE * SKALA}x${HOEHE * SKALA}, ohne Rauch, ${(bytes.length / 1024).toFixed(1)} kB`);
}

await browser.close();

for (const v of VARIANTEN) {
    for (const name of [`zug-dampf-${v.key}.gif`, `zug-dampf-idle-${v.key}.gif`, `zug-dampf-${v.key}.png`]) {
        copyFileSync(`${ASSETS}/${name}`, `${OEFFENTLICH}/${name}`);
    }
}

console.log('Nach public/mail-assets kopiert.');
