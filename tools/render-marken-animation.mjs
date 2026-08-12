/**
 * Erzeugt die bewegten Markenbilder fuer Signatur und Vorlage.
 *
 *   wortmarke-*.gif   Der Schriftzug baut sich Zeichen fuer Zeichen auf,
 *                     danach die Linie, zuletzt GMBH.
 *   icon-rt-*.gif     Das Zeichen baut sich in DREI Stufen auf: erst der
 *                     rote Balken links unten, dann das rote R aussen
 *                     herum, zuletzt das T in der Mitte.
 *
 * ZWEI DINGE, DIE DIESE DATEI RICHTIG MACHEN MUSS
 *
 * 1. FARBTREUE. Ein erster Versuch quantisierte mit rgba4444 — vier Bit je
 *    Kanal. Der Verlauf der Wortmarke zerfiel dabei sichtbar in Streifen.
 *    Hier wird deshalb mit rgb565 quantisiert und die Durchsichtigkeit
 *    ueber eine RESERVIERTE Farbe geloest: durchsichtige Bildpunkte
 *    bekommen ein Magenta, das im Motiv nicht vorkommt, und ihr Index wird
 *    danach unmittelbar gesetzt statt ueber die Naechste-Farbe-Suche.
 *
 * 2. ZEICHENGRENZEN OHNE ZEICHEN. Der Schriftzug ist ein Bild, keine
 *    Schrift — und seine Buchstaben beruehren einander, es gibt nur zwei
 *    echte Luecken. Geschnitten wird deshalb an den MINIMA der
 *    Spaltendichte: dort, wo am wenigsten Tinte steht, verlaeuft mit hoher
 *    Wahrscheinlichkeit eine Zeichengrenze.
 *
 * OUTLOOK: Diese Dateien bauen sich auf, ihr erstes Einzelbild ist also
 * fast leer. Outlook-Desktop zeigt ausschliesslich dieses erste Bild und
 * bekommt deshalb ueber einen bedingten Kommentar das Standbild (.png).
 * Siehe emails/parts/signature.blade.php und email-master.html.
 *
 * Aufruf: node tools/render-marken-animation.mjs
 */
import puppeteer from 'puppeteer-core';
import gifenc from 'gifenc';
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';

const { GIFEncoder, quantize, applyPalette } = gifenc;
const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ASSETS = 'resources/mail-templates/assets';
const OEFFENTLICH = 'public/mail-assets';

const BILDER = 42;
const SUMME_CS = 320;
const MAGENTA = [255, 0, 255];

const WORTMARKEN = [
    'wortmarke-signature-light',
    'wortmarke-signature-dark',
    'wortmarke-mail-dark',
];

const ZEICHEN = [
    { key: 'icon-rt-light', t: '#151b24' },
    { key: 'icon-rt-dark', t: '#e8ecf1' },
];

function verzoegerungen() {
    const grund = Math.floor(SUMME_CS / BILDER);
    const werte = new Array(BILDER).fill(grund);
    werte[BILDER - 1] += SUMME_CS - (grund * BILDER);

    return werte;
}

const cs = verzoegerungen();

/**
 * Baut die Farbtabelle und kodiert.
 *
 * @param bilder  Liste roher RGBA-Puffer, gleiche Groesse
 */
function schreibeGif(bilder, breite, hoehe, ziel) {
    // Arbeitspuffer: durchsichtige Punkte bekommen Magenta und volle
    // Deckung, damit die Quantisierung sie als EINE eigene Farbe fuehrt
    // und nicht in die Verlaeufe des Motivs einrechnet.
    const arbeit = bilder.map((roh) => {
        const a = new Uint8Array(roh);
        for (let i = 0; i < a.length; i += 4) {
            if (a[i + 3] < 128) {
                a[i] = MAGENTA[0];
                a[i + 1] = MAGENTA[1];
                a[i + 2] = MAGENTA[2];
            }
            a[i + 3] = 255;
        }

        return a;
    });

    // Die Tabelle entsteht aus dem LETZTEN Bild — dort steht das Motiv
    // vollstaendig, also mit allen seinen Farben.
    const palette = quantize(arbeit[arbeit.length - 1], 255, { format: 'rgb565' });

    // Magenta erzwingen statt hoffen: als letzter Eintrag angehaengt, damit
    // sein Index feststeht.
    palette.push(MAGENTA.slice());
    const durchsichtig = palette.length - 1;

    const encoder = GIFEncoder();

    arbeit.forEach((puffer, index) => {
        const indizes = applyPalette(puffer, palette, 'rgb565');
        const roh = bilder[index];

        // Durchsichtige Punkte DIREKT setzen, nicht ueber die Farbsuche:
        // sonst landete ein Randpunkt gelegentlich auf einer Motivfarbe.
        for (let i = 0, p = 0; i < roh.length; i += 4, p += 1) {
            if (roh[i + 3] < 128) indizes[p] = durchsichtig;
        }

        encoder.writeFrame(indizes, breite, hoehe, {
            palette: index === 0 ? palette : undefined,
            delay: cs[index] * 10,
            transparent: true,
            transparentIndex: durchsichtig,
            // 2 = raeumen. Waehrend des Aufbaus wachsen die Teile; ohne
            // Raeumen bliebe jeder Zwischenstand stehen und die weichen
            // Kanten wuerden sich uebereinanderlegen.
            dispose: index === bilder.length - 1 ? 1 : 2,
            repeat: -1,
        });
    });

    encoder.finish();
    const gif = Buffer.from(encoder.bytes());
    writeFileSync(`${ASSETS}/${ziel}`, gif);
    copyFileSync(`${ASSETS}/${ziel}`, `${OEFFENTLICH}/${ziel}`);

    console.log(`${ziel.padEnd(32)} ${breite}x${hoehe}, ${bilder.length} Bilder, ${(gif.length / 1024).toFixed(1)} kB`);
}

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body style="margin:0"></body></html>', { waitUntil: 'load' });

/* ------------------------------------------------------------------ *
 * Wortmarke
 * ------------------------------------------------------------------ */

for (const name of WORTMARKEN) {
    const daten = readFileSync(`${ASSETS}/${name}.png`).toString('base64');

    const { bilder, breite, hoehe } = await page.evaluate(async (a) => {
        const bild = new Image();
        await new Promise((res, rej) => {
            bild.onload = res;
            bild.onerror = rej;
            bild.src = 'data:image/png;base64,' + a.daten;
        });

        const B = bild.naturalWidth;
        const H = bild.naturalHeight;

        const mess = document.createElement('canvas');
        mess.width = B; mess.height = H;
        const mx = mess.getContext('2d', { willReadFrequently: true });
        mx.drawImage(bild, 0, 0);
        const px = mx.getImageData(0, 0, B, H).data;

        // --- Baender trennen: Schriftzug oben, Linie und GMBH unten ------
        const zeileTinte = [];
        for (let y = 0; y < H; y += 1) {
            let n = 0;
            for (let x = 0; x < B; x += 1) if (px[(((y * B) + x) * 4) + 3] > 16) n += 1;
            zeileTinte.push(n);
        }
        let trenner = H;
        for (let y = Math.floor(H * 0.5); y < H; y += 1) {
            if (zeileTinte[y] === 0 && zeileTinte[y + 1] > 0) { trenner = y + 1; break; }
        }

        // --- Zeichengrenzen: Minima der Spaltendichte im oberen Band -----
        const spalte = [];
        for (let x = 0; x < B; x += 1) {
            let n = 0;
            for (let y = 0; y < trenner; y += 1) if (px[(((y * B) + x) * 4) + 3] > 16) n += 1;
            spalte.push(n);
        }

        const teile = a.zeichen;
        const mindest = Math.floor(B / (teile + 2));
        const kandidaten = spalte
            .map((wert, x) => ({ wert, x }))
            .filter((k) => k.x > mindest && k.x < B - mindest)
            .sort((p, q) => p.wert - q.wert);

        const schnitte = [];
        for (const k of kandidaten) {
            if (schnitte.length >= teile - 1) break;
            if (schnitte.every((s) => Math.abs(s - k.x) >= mindest)) schnitte.push(k.x);
        }
        schnitte.sort((p, q) => p - q);

        const grenzen = [0, ...schnitte, B];

        // --- GMBH von der Linie trennen: die Linie ist flach -------------
        const istText = [];
        for (let x = 0; x < B; x += 1) {
            let n = 0;
            for (let y = trenner; y < H; y += 1) if (px[(((y * B) + x) * 4) + 3] > 16) n += 1;
            istText.push(n > 4);
        }

        const glatt = (v) => v * v * (3 - (2 * v));
        const klemm = (v) => Math.min(1, Math.max(0, v));

        const aus = [];
        for (let i = 0; i < a.bilder; i += 1) {
            const t = i / (a.bilder - 1);

            const c = document.createElement('canvas');
            c.width = B; c.height = H;
            const x = c.getContext('2d');

            // --- Zeichen nacheinander aus dem Nichts ---------------------
            // Jedes Zeichen startet unscharf, tiefer und ohne Deckung und
            // faehrt in seine Lage. Der Versatz je Zeichen ergibt das
            // Nacheinander.
            const zeichenBis = 0.62;
            for (let k = 0; k < grenzen.length - 1; k += 1) {
                const von = grenzen[k];
                const bis = grenzen[k + 1];
                const start = (k / (grenzen.length - 1)) * zeichenBis;
                const p = glatt(klemm((t - start) / 0.2));
                if (p <= 0) continue;

                x.save();
                x.globalAlpha = p;
                if (p < 1) x.filter = `blur(${(1 - p) * 3.2}px)`;
                x.drawImage(
                    bild,
                    von, 0, bis - von, trenner,
                    von, (1 - p) * 7, bis - von, trenner,
                );
                x.restore();
            }

            // --- Danach die Linie, von der Mitte nach aussen -------------
            const linieP = glatt(klemm((t - 0.6) / 0.2));
            if (linieP > 0) {
                const halb = (B / 2) * linieP;
                for (let sx = 0; sx < B; sx += 1) {
                    if (istText[sx]) continue;
                    if (Math.abs(sx - (B / 2)) > halb) continue;
                    x.drawImage(bild, sx, trenner, 1, H - trenner, sx, trenner, 1, H - trenner);
                }
            }

            // --- Zuletzt GMBH ------------------------------------------
            const textP = glatt(klemm((t - 0.78) / 0.18));
            if (textP > 0) {
                x.save();
                x.globalAlpha = textP;
                for (let sx = 0; sx < B; sx += 1) {
                    if (!istText[sx]) continue;
                    x.drawImage(bild, sx, trenner, 1, H - trenner, sx, trenner, 1, H - trenner);
                }
                x.restore();
            }

            aus.push(Array.from(new Uint8Array(x.getImageData(0, 0, B, H).data.buffer)));
        }

        return { bilder: aus, breite: B, hoehe: H };
    }, { daten, bilder: BILDER, zeichen: 8 });

    schreibeGif(bilder.map((b) => new Uint8Array(b)), breite, hoehe, `${name}.gif`);
}

/* ------------------------------------------------------------------ *
 * RT-Zeichen: drei Stufen
 * ------------------------------------------------------------------ */

const favicon = readFileSync('public/icons/favicon.svg', 'utf8');
const pfade = [...favicon.matchAll(/<path d="([^"]+)"/g)].map((m) => m[1]);

if (pfade.length !== 3) {
    throw new Error(`Erwartet werden drei Pfade, gefunden: ${pfade.length}`);
}

for (const zeichen of ZEICHEN) {
    const { bilder, breite, hoehe } = await page.evaluate(async (a) => {
        const G = 132;
        const glatt = (v) => v * v * (3 - (2 * v));
        const klemm = (v) => Math.min(1, Math.max(0, v));

        // Reihenfolge wie gewuenscht: erst der rote Balken links unten,
        // dann das rote R aussen herum, zuletzt das T in der Mitte.
        const stufen = [
            { d: a.pfade[1], farbe: '#e4002b', start: 0.02 },
            { d: a.pfade[0], farbe: '#e4002b', start: 0.26 },
            { d: a.pfade[2], farbe: a.tFarbe, start: 0.52 },
        ];

        const aus = [];
        for (let i = 0; i < a.bilder; i += 1) {
            const t = i / (a.bilder - 1);

            const c = document.createElement('canvas');
            c.width = G; c.height = G;
            const x = c.getContext('2d');

            for (const stufe of stufen) {
                const p = glatt(klemm((t - stufe.start) / 0.22));
                if (p <= 0) continue;

                x.save();
                x.globalAlpha = p;
                // Aus dem Nichts: leicht zu gross und unscharf beginnen,
                // dann in die eigene Lage einrasten.
                const skala = 1 + ((1 - p) * 0.14);
                x.translate(G / 2, G / 2);
                x.scale(skala, skala);
                x.translate(-G / 2, -G / 2);
                if (p < 1) x.filter = `blur(${(1 - p) * 2.6}px)`;

                // Dieselbe Lage wie im Favicon: translate(60 60) scale(0.9)
                // in einer Flaeche von 1024.
                const f = (G / 1024) * 0.9;
                const rand = (G / 1024) * 60;
                x.translate(rand, rand);
                x.scale(f, f);
                x.fillStyle = stufe.farbe;
                x.fill(new Path2D(stufe.d));
                x.restore();
            }

            aus.push(Array.from(new Uint8Array(x.getImageData(0, 0, G, G).data.buffer)));
        }

        return { bilder: aus, breite: G, hoehe: G };
    }, { pfade, tFarbe: zeichen.t, bilder: BILDER });

    schreibeGif(bilder.map((b) => new Uint8Array(b)), breite, hoehe, `${zeichen.key}.gif`);
}

await browser.close();
console.log('Nach public/mail-assets kopiert.');
