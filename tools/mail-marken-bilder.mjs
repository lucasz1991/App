/**
 * Erzeugt die beiden Markenbilder, die Signatur und Vorlage getrennt
 * voneinander brauchen.
 *
 *   wortmarke-*.png   Der Schriftzug OHNE das RT-Zeichen. Die Markenspalte
 *                     der Signatur zeigt nur noch Schrift; das Zeichen
 *                     davor doppelte die Marke auf engem Raum.
 *   icon-rt-*.png     Das RT-Zeichen ALLEIN, fuer die rechte obere Ecke der
 *                     E-Mail-Vorlage.
 *
 * ZWEI VERFAHREN, weil die Quellen verschieden sind:
 *
 *   Die Wortmarke wird aus den bestehenden Logodateien GESCHNITTEN. Ein
 *   Neusatz waere ein zweiter Schriftzug mit eigenem Kerning — er wuerde
 *   frueher oder spaeter vom Original abweichen. Der Schnitt kann das nicht.
 *
 *   Das Zeichen wird aus den Pfaden von public/icons/favicon.svg GERECHNET.
 *   Aus der Logodatei geschnitten waere es nur 70 px breit und auf jedem
 *   hochaufloesenden Schirm sichtbar weich. Die Pfade sind reine Polygone
 *   (nur M/H/V/L/Z), deshalb genuegt ein Scanline-Fuellen mit Ueberabtastung
 *   — kein Browser, keine weitere Abhaengigkeit.
 *
 * Aufruf: node tools/mail-marken-bilder.mjs
 */
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';
import { PNG } from 'pngjs';

const QUELLE = 'resources/mail-templates/assets';
const OEFFENTLICH = 'public/mail-assets';

/* ------------------------------------------------------------------ *
 * Wortmarke: Zeichen abschneiden, Rest auf die Tinte zuschneiden
 * ------------------------------------------------------------------ */

/**
 * Findet die erste senkrechte Luecke, die breit genug ist, um der Abstand
 * zwischen Zeichen und Schriftzug zu sein — statt einer fest verdrahteten
 * Pixelspalte. Aendert sich das Logo, wandert der Schnitt mit.
 */
function schnittSpalte(png, mindestLuecke = 8) {
    const tinte = spaltenTinte(png);
    let start = -1;

    for (let x = 0; x < tinte.length; x++) {
        if (tinte[x] === 0) {
            if (start < 0) start = x;
            continue;
        }

        // Eine Luecke zaehlt nur, wenn links davon schon Tinte stand:
        // der leere Rand am Bildanfang ist keine Trennung.
        if (start > 0 && x - start >= mindestLuecke) return x;
        start = -1;
    }

    throw new Error('Keine Trennung zwischen Zeichen und Schriftzug gefunden');
}

function spaltenTinte(png) {
    const tinte = new Array(png.width).fill(0);

    for (let x = 0; x < png.width; x++) {
        for (let y = 0; y < png.height; y++) {
            if (png.data[((png.width * y + x) << 2) + 3] > 16) tinte[x] += 1;
        }
    }

    return tinte;
}

function beschneiden(png, links, bis = png.height) {
    let oben = bis;
    let unten = -1;
    let rechts = -1;

    for (let y = 0; y < bis; y++) {
        for (let x = links; x < png.width; x++) {
            if (png.data[((png.width * y + x) << 2) + 3] <= 16) continue;
            if (y < oben) oben = y;
            if (y > unten) unten = y;
            if (x > rechts) rechts = x;
        }
    }

    const ziel = new PNG({ width: rechts - links + 1, height: unten - oben + 1 });

    for (let y = 0; y < ziel.height; y++) {
        for (let x = 0; x < ziel.width; x++) {
            const q = (png.width * (y + oben) + (x + links)) << 2;
            const z = (ziel.width * y + x) << 2;
            ziel.data[z] = png.data[q];
            ziel.data[z + 1] = png.data[q + 1];
            ziel.data[z + 2] = png.data[q + 2];
            ziel.data[z + 3] = png.data[q + 3];
        }
    }

    return ziel;
}

function wortmarke(quelle, ziel) {
    const png = PNG.sync.read(readFileSync(`${QUELLE}/${quelle}`));
    const schnitt = schnittSpalte(png);
    // Geschnitten wird NUR senkrecht: das RT-Zeichen faellt weg, die Zeile
    // aus Linie und GMBH bleibt. Sie gehoert zur Marke — ein frueherer
    // Versuch, auch sie abzuschneiden, wurde zurueckgenommen.
    const aus = beschneiden(png, schnitt);
    const bytes = PNG.sync.write(aus, { deflateLevel: 9 });

    writeFileSync(`${QUELLE}/${ziel}`, bytes);
    console.log(
        `${ziel.padEnd(30)} ${png.width}x${png.height} -> ${aus.width}x${aus.height}`
        + `  (Zeichen abgeschnitten bei x=${schnitt}, ${(bytes.length / 1024).toFixed(1)} kB)`,
    );
}

/* ------------------------------------------------------------------ *
 * RT-Zeichen: Polygone aus dem Favicon fuellen
 * ------------------------------------------------------------------ */

/** Zerlegt einen Pfad aus M/H/V/L/Z in eine Eckpunktliste. */
function polygon(d) {
    const zahlen = /(-?\d+(?:\.\d+)?)/g;
    const punkte = [];
    let x = 0;
    let y = 0;

    for (const teil of d.match(/[MHVLZ][^MHVLZ]*/gi) ?? []) {
        const befehl = teil[0].toUpperCase();
        const werte = (teil.slice(1).match(zahlen) ?? []).map(Number);

        if (befehl === 'Z') continue;
        if (befehl === 'H') {
            for (const wert of werte) punkte.push([(x = wert), y]);
            continue;
        }
        if (befehl === 'V') {
            for (const wert of werte) punkte.push([x, (y = wert)]);
            continue;
        }
        for (let i = 0; i + 1 < werte.length; i += 2) {
            punkte.push([(x = werte[i]), (y = werte[i + 1])]);
        }
    }

    return punkte;
}

/**
 * Deckungsgrad je Bildpunkt ueber ein Gitter von UNTERPROBEN. Bei rein
 * geraden Kanten reichen 4x4 fuer eine saubere Schraege.
 */
function decken(punkte, groesse, unterproben = 4) {
    const deckung = new Float32Array(groesse * groesse);
    const schritt = 1 / unterproben;
    const gewicht = 1 / (unterproben * unterproben);

    for (let y = 0; y < groesse; y++) {
        for (let uy = 0; uy < unterproben; uy++) {
            const py = y + (uy + 0.5) * schritt;
            const kreuzungen = [];

            for (let i = 0; i < punkte.length; i++) {
                const [ax, ay] = punkte[i];
                const [bx, by] = punkte[(i + 1) % punkte.length];
                if (ay === by) continue;
                if (py < Math.min(ay, by) || py >= Math.max(ay, by)) continue;
                kreuzungen.push(ax + ((py - ay) / (by - ay)) * (bx - ax));
            }

            kreuzungen.sort((a, b) => a - b);

            for (let k = 0; k + 1 < kreuzungen.length; k += 2) {
                const von = kreuzungen[k];
                const bis = kreuzungen[k + 1];
                const erstesX = Math.max(0, Math.floor(von));
                const letztesX = Math.min(groesse - 1, Math.ceil(bis));

                for (let x = erstesX; x <= letztesX; x++) {
                    for (let ux = 0; ux < unterproben; ux++) {
                        const px = x + (ux + 0.5) * schritt;
                        if (px >= von && px < bis) deckung[groesse * y + x] += gewicht;
                    }
                }
            }
        }
    }

    return deckung;
}

/** Legt eine gedeckte Flaeche in der angegebenen Farbe ueber das Bild. */
function auftragen(png, deckung, [r, g, b]) {
    for (let i = 0; i < deckung.length; i++) {
        const a = Math.min(1, deckung[i]);
        if (a <= 0) continue;

        const p = i << 2;
        const alt = png.data[p + 3] / 255;
        const neu = a + alt * (1 - a);

        png.data[p] = Math.round((r * a + png.data[p] * alt * (1 - a)) / neu);
        png.data[p + 1] = Math.round((g * a + png.data[p + 1] * alt * (1 - a)) / neu);
        png.data[p + 2] = Math.round((b * a + png.data[p + 2] * alt * (1 - a)) / neu);
        png.data[p + 3] = Math.round(neu * 255);
    }
}

const ROT = [0xe4, 0x00, 0x2b];
const ZEICHEN_GROESSE = 132;

function zeichen(ziel, tFarbe) {
    const svg = readFileSync('public/icons/favicon.svg', 'utf8');
    const pfade = [...svg.matchAll(/<path d="([^"]+)"/g)].map((m) => m[1]);

    if (pfade.length !== 3) {
        throw new Error(`Erwartet werden drei Pfade, gefunden: ${pfade.length}`);
    }

    // Dieselbe Lage wie im Favicon: translate(60 60) scale(0.9) in 1024.
    const faktor = (ZEICHEN_GROESSE / 1024) * 0.9;
    const rand = (ZEICHEN_GROESSE / 1024) * 60;
    const lege = (d) => polygon(d).map(([x, y]) => [x * faktor + rand, y * faktor + rand]);

    const png = new PNG({ width: ZEICHEN_GROESSE, height: ZEICHEN_GROESSE });
    png.data.fill(0);

    auftragen(png, decken(lege(pfade[0]), ZEICHEN_GROESSE), ROT);
    auftragen(png, decken(lege(pfade[1]), ZEICHEN_GROESSE), ROT);
    auftragen(png, decken(lege(pfade[2]), ZEICHEN_GROESSE), tFarbe);

    const bytes = PNG.sync.write(png, { deflateLevel: 9 });
    writeFileSync(`${QUELLE}/${ziel}`, bytes);
    console.log(`${ziel.padEnd(30)} ${ZEICHEN_GROESSE}x${ZEICHEN_GROESSE}  (${(bytes.length / 1024).toFixed(1)} kB)`);
}

/* ------------------------------------------------------------------ */

wortmarke('logo-signature-light.png', 'wortmarke-signature-light.png');
wortmarke('logo-signature-dark.png', 'wortmarke-signature-dark.png');
wortmarke('logo-mail-dark.png', 'wortmarke-mail-dark.png');

// Auf hellem Grund traegt der Balken des T das Anthrazit der Marke, auf
// dunklem Grund waere es unsichtbar — dort steht es hell.
zeichen('icon-rt-light.png', [0x15, 0x1b, 0x24]);
zeichen('icon-rt-dark.png', [0xe8, 0xec, 0xf1]);

// Die verlinkte Fassung fuer versendete Mails liegt oeffentlich.
for (const datei of [
    'wortmarke-signature-light.png',
    'wortmarke-signature-dark.png',
    'wortmarke-mail-dark.png',
    'icon-rt-light.png',
    'icon-rt-dark.png',
]) {
    copyFileSync(`${QUELLE}/${datei}`, `${OEFFENTLICH}/${datei}`);
}

console.log(`\nNach ${OEFFENTLICH} kopiert.`);
