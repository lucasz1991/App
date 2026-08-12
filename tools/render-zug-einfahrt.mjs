/**
 * Erzeugt die Einfahrt des Signaturzuges — DURCHSICHTIG, in doppelter
 * Aufloesung und groesser im Bild.
 *
 *   zug-dampf-{light,dark}.gif        Einfahrt, einmalig, 740 cs
 *   zug-dampf-{light,dark}.png        Ruhelage als Standbild, 2x, mit Alpha
 *
 * WARUM NEU GERENDERT STATT GEPATCHT
 * Drei Anforderungen zusammen liessen sich am fertigen GIF nicht mehr
 * erfuellen: kein eigener Hintergrund, hoehere Aufloesung und ein
 * groesseres Motiv. Die ersten beiden haengen an den Bilddaten selbst.
 *
 * DURCHSICHTIGKEIT UND IHRE GRENZE
 * GIF kennt nur 1-Bit-Alpha. Der Zug ist eine weiche Silhouette bei 30 %
 * Deckkraft — als reine Ein/Aus-Maske zerrisse er. Deshalb wird auf die
 * Zielfarbe gerechnet (weiss beziehungsweise dunkel) und anschliessend
 * GENAU DIESER Grundton als durchsichtig markiert. Ergebnis: die leere
 * Flaeche gibt den Hintergrund frei, die Tinte des Zuges bleibt als
 * heller Schleier stehen. Mehr ist im Format nicht vorgesehen.
 *
 * ENTSORGUNG: Mit durchsichtigen Bildpunkten MUSS jedes Einzelbild vor dem
 * naechsten geraeumt werden (Methode 2) — sonst schmiert der fahrende Zug
 * ueber die Leinwand. Das LETZTE Bild bleibt stehen (Methode 1), damit der
 * Zug nach der Einfahrt nicht verschwindet.
 *
 * GEOMETRIE: Die Lok steht am RECHTEN Bildrand. Wer das Bild auf 70 % der
 * Signaturbreite legt, hat die Lok damit exakt bei 70 % — und der Zug ist
 * trotzdem groesser als zuvor, weil er einen groesseren Teil des Bildes
 * einnimmt (Faktor 1,8 statt 1,42) und der Rest links aus dem Bild laeuft.
 *
 * DER RAUCH LOEST SICH AUF, bevor die Einfahrt endet. Damit gleicht das
 * letzte Einzelbild dem Standbild — der gemeldete Sprung zwischen Fahne
 * und Standbild entfaellt.
 *
 * Aufruf: node tools/render-zug-einfahrt.mjs
 */
import puppeteer from 'puppeteer-core';
import gifenc from 'gifenc';
import { PNG } from 'pngjs';
import { readFileSync, writeFileSync, copyFileSync } from 'node:fs';

const { GIFEncoder } = gifenc;
const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ASSETS = 'resources/mail-templates/assets';
const OEFFENTLICH = 'public/mail-assets';

// --- Leinwand ---------------------------------------------------------
const BREITE = Number(process.env.RT_BREITE || 820);
const SKALA = Number(process.env.RT_SKALA || 2);                       // 1400 x 200 echte Bildpunkte

// --- Zug --------------------------------------------------------------
// Das Motiv ist 2053 : 151 breit zu hoch. Der Faktor sagt, wie viel
// breiter der Zug als die Leinwand gezeichnet wird — je groesser, desto
// naeher steht er am Betrachter und desto mehr laeuft links heraus.
const ZUG_FAKTOR = Number(process.env.RT_FAKTOR || 1.8);
const ZUG_BREITE = BREITE * ZUG_FAKTOR;

/*
 * WEITERE WAGGONS HINTEN ANSETZEN.
 *
 * Am Motiv nachgemessen: die Fahrzeuge stehen in einem Wiederholmass von
 * 330 Einheiten, der Wagenteil endet bei 1338 — dort beginnt die Lok.
 * Eine zweite Kopie, um genau 1338 nach links versetzt, schliesst deshalb
 * fugenlos an. Ihre eigene Lok faellt dabei aus dem Beschnitt: gezeichnet
 * wird nur, was LINKS der bereits gesetzten Zugkante liegt.
 *
 * Dadurch faehrt der Zug laenger ein und fuellt auch breite Streifen, statt
 * hinter dem letzten Wagen leer zu laufen.
 */
const WAGENTEIL = 1338 / 2053;          // Anteil des Wagenteils am Motiv
const ANHAENGE = Number(process.env.RT_ANHAENGE || 2);
const ZUG_HOEHE = ZUG_BREITE * (151 / 2053);
// HOEHE = Zughoehe mal Kopfraum. Der Kopfraum ist der Himmel ueber dem
// Zug, in dem die Rauchfahne aufsteigt — je groesser, desto hoeher darf
// sie ziehen, bevor sie oben abgeschnitten wird.
const KOPFRAUM = Number(process.env.RT_KOPFRAUM || 1.9);
const HOEHE = Math.round(ZUG_HOEHE * KOPFRAUM);
const RUHE_X = BREITE - ZUG_BREITE;     // Lok am rechten Bildrand
const START_X = -ZUG_BREITE * (1 + (WAGENTEIL * ANHAENGE));
const ZUG_Y = HOEHE - ZUG_HOEHE;
const SCHORNSTEIN_X = BREITE - (ZUG_BREITE * 0.035);

// --- Zeiten (vertraglich, siehe EmailTemplatesPageTest) ---------------
const BILDER = Number(process.env.RT_BILDER || 44);
const ERSTES_CS = 30;
const SUMME_CS = 740;
const GESAMT_S = SUMME_CS / 100;
const FAHRT_S = 2.6;                    // bis zum Stillstand
// Die Fahne VERWEHT NICHT. Sie bleibt stehen wie in der klassischen
// Fassung — nur der Ausstoss endet, wenn der Zug haelt.

const STUFEN = Number(process.env.RT_STUFEN || 7);
// DECKEND, und das ist eine gemessene Entscheidung: Durchsichtigkeit
// macht die Datei KEIN Byte kleiner (gifenc schreibt so oder so Vollbilder)
// und zwingt zusaetzlich zur Entsorgungsmethode 2, damit der fahrende Zug
// nicht schmiert. Der sichtbare Kasten hinter dem Zug wird stattdessen
// dadurch vermieden, dass Raster und Wasserzeichen als durchsichtige
// Ebenen UEBER dem Zug liegen (siehe signature.blade.php).
const DURCHSICHTIG = process.env.RT_TRANSPARENT === '1';

const VARIANTEN = [
    { key: 'light', grund: [255, 255, 255], rauch: '90, 99, 110', deckkraft: 0.30 },
    { key: 'dark', grund: [12, 16, 23], rauch: '196, 206, 219', deckkraft: 0.26 },
];

/**
 * Verteilt SUMME_CS auf BILDER — ABSICHTLICH UNGLEICH.
 *
 * Eine hoehere Aufloesung kostet Dateigroesse, und die haengt fast nur an
 * der ZAHL der Einzelbilder (jedes ist ein Vollbild). Statt die Bilder
 * gleichmaessig zu verteilen und ueberall gleich grob zu werden, liegen
 * sie dort dicht, wo etwas passiert: waehrend der Einfahrt. Danach steht
 * der Zug und nur der Rauch verweht — dort genuegen wenige, lange Bilder.
 *
 * Ergebnis bei 44 Bildern: rund 12 Bilder je Sekunde waehrend der Fahrt
 * statt der 5,9, die eine gleichmaessige Verteilung ergaebe.
 */
function verzoegerungen() {
    const fahrtAnteil = FAHRT_S / GESAMT_S;
    const fahrtBilder = Math.max(2, Math.round(BILDER * 0.72));
    const restBilder = BILDER - fahrtBilder;

    const fahrtCs = Math.round(SUMME_CS * fahrtAnteil);
    const restCs = SUMME_CS - fahrtCs;

    const verteile = (summe, anzahl, erstes = null) => {
        const werte = [];
        const kopf = erstes ?? 0;
        const uebrig = summe - kopf;
        const n = erstes === null ? anzahl : anzahl - 1;
        const grund = Math.floor(uebrig / n);
        const ueber = uebrig - (grund * n);
        if (erstes !== null) werte.push(erstes);
        for (let i = 0; i < n; i += 1) werte.push(grund + (i < ueber ? 1 : 0));

        return werte;
    };

    return [
        ...verteile(fahrtCs, fahrtBilder, ERSTES_CS),
        ...verteile(restCs, restBilder),
    ];
}

/** Zeitpunkt des i-ten Bildes aus den Anzeigedauern. */
function zeitpunkte(cs) {
    const t = [];
    let summe = 0;
    for (const wert of cs) { t.push(summe / 100); summe += wert; }

    return t;
}

const glatt = (a, b, t) => {
    const p = Math.min(1, Math.max(0, (t - a) / (b - a)));

    return p * p * (3 - (2 * p));
};
const mische = (a, b, t) => a + ((b - a) * t);
const easeOut = (t) => 1 - Math.pow(1 - Math.min(1, Math.max(0, t)), 3);
const rausch = (i, salz = 0) => {
    const v = Math.sin(((i + 1) * 12.9898) + (salz * 78.233)) * 43758.5453;

    return v - Math.floor(v);
};

// --- Rauchwolken ------------------------------------------------------
// Die Fahne ist an die LEINWAND gekoppelt, nicht an feste Pixelwerte:
// sonst sieht sie auf einer groesseren Leinwand grob und klumpig aus,
// weil dieselben Radien dort einen kleineren Anteil des Bildes einnehmen
// und als einzelne Ballen stehen bleiben statt zu verschmelzen.
// Bezugsmass ist die fruehere Leinwand von 560 Einheiten.
const MASSSTAB = BREITE / 560;
const WOLKEN = 260;
const wolken = Array.from({ length: WOLKEN }, (_, i) => ({
    geburt: (i / WOLKEN) * 3.6,
    drift: (-16 - (rausch(i, 1) * 22)) * MASSSTAB,
    steigen: (-8 - (rausch(i, 2) * 7)) * MASSSTAB,
    wuchs: (3.0 + (rausch(i, 3) * 3.6)) * MASSSTAB,
    r0: (2.0 + (rausch(i, 1) * 2.2)) * MASSSTAB,
    phase: rausch(i, 2) * Math.PI * 2,
}));

function wolkeBei(w, t, schornsteinX) {
    const alter = t - w.geburt;
    if (alter < 0) return null;

    // Der Rauch tritt dort aus, wo der Schornstein ZUM ZEITPUNKT DES
    // AUSTRITTS stand — nur so bleibt die Fahne hinter dem Zug zurueck.
    const auftauchen = glatt(w.geburt, w.geburt + 0.35, t);

    return {
        x: schornsteinX + (w.drift * alter) + (Math.sin(w.phase + (alter * 1.7)) * 2.4 * MASSSTAB),
        y: (ZUG_Y + (ZUG_HOEHE * 0.16)) + (w.steigen * alter) + (Math.cos(w.phase + (alter * 1.3)) * 1.2 * MASSSTAB),
        r: w.r0 + (w.wuchs * alter),
        alpha: auftauchen * 0.085,
    };
}

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

/** Zeichnet ein Einzelbild und liefert die rohen RGBA-Werte. */
async function zeichne(auftrag) {
    const roh = await page.evaluate((a) => {
        const c = document.createElement('canvas');
        c.width = a.breite * a.skala;
        c.height = a.hoehe * a.skala;
        const x = c.getContext('2d');
        x.scale(a.skala, a.skala);

        x.fillStyle = a.grund;
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

        x.globalAlpha = a.deckkraft;
        // Angehaengte Waggons ZUERST: jede Kopie wird auf den Bereich links
        // der vorigen beschnitten, damit ihre eigene Lok nicht mitgezeichnet
        // wird.
        const schritt = a.zugBreite * a.wagenteil;
        for (let k = a.anhaenge; k >= 1; k -= 1) {
            x.save();
            x.beginPath();
            x.rect(a.zugX - (k * schritt), 0, schritt, a.hoehe);
            x.clip();
            x.drawImage(window.rtZug, a.zugX - (k * schritt), a.zugY, a.zugBreite, a.zugHoehe);
            x.restore();
        }
        x.drawImage(window.rtZug, a.zugX, a.zugY, a.zugBreite, a.zugHoehe);
        x.globalAlpha = 1;

        return Array.from(new Uint8Array(x.getImageData(0, 0, c.width, c.height).data.buffer));
    }, auftrag);

    return new Uint8Array(roh);
}

/**
 * Baut eine Farbtabelle aus dem Grundton (Index 0, spaeter durchsichtig)
 * und einer Rampe zur Tinte. Eine EIGENE Tabelle statt einer gerechneten:
 * nur so ist sicher, dass der Grundton GENAU einen Index belegt — sonst
 * bliebe ein Teil der Flaeche undurchsichtig.
 */
function farbtabelle(grund, tinte, stufen = STUFEN) {
    const tabelle = [grund.slice()];
    for (let i = 1; i <= stufen; i += 1) {
        const t = i / stufen;
        tabelle.push([
            Math.round(mische(grund[0], tinte[0], t)),
            Math.round(mische(grund[1], tinte[1], t)),
            Math.round(mische(grund[2], tinte[2], t)),
        ]);
    }

    return tabelle;
}

/** Ordnet jedem Bildpunkt den naechsten Index der Rampe zu. */
function aufTabelle(rgba, tabelle) {
    const n = rgba.length / 4;
    const index = new Uint8Array(n);

    for (let i = 0; i < n; i += 1) {
        const r = rgba[i * 4];
        const g = rgba[(i * 4) + 1];
        const b = rgba[(i * 4) + 2];

        let beste = 0;
        let abstand = Infinity;
        for (let k = 0; k < tabelle.length; k += 1) {
            const dr = tabelle[k][0] - r;
            const dg = tabelle[k][1] - g;
            const db = tabelle[k][2] - b;
            const d = (dr * dr) + (dg * dg) + (db * db);
            if (d < abstand) { abstand = d; beste = k; }
        }
        index[i] = beste;
    }

    return index;
}

const cs = verzoegerungen();
const zeiten = zeitpunkte(cs);
console.log(`Zeiten: ${cs.length} Bilder, Summe ${cs.reduce((a, b) => a + b, 0)} cs, erstes ${cs[0]} cs`);

for (const v of VARIANTEN) {
    const tinte = v.key === 'dark' ? [216, 221, 228] : [115, 125, 137];
    const tabelle = farbtabelle(v.grund, tinte);
    const encoder = GIFEncoder();

    for (let i = 0; i < BILDER; i += 1) {
        const t = zeiten[i];
        const zugX = mische(START_X, RUHE_X, easeOut(t / FAHRT_S));

        const sichtbar = [];
        for (const w of wolken) {
            const xBeiGeburt = mische(START_X, RUHE_X, easeOut(w.geburt / FAHRT_S));
            const z = wolkeBei(w, t, SCHORNSTEIN_X + (xBeiGeburt - RUHE_X));
            if (z && z.alpha > 0.004 && z.x > -60 && z.x < BREITE + 60) sichtbar.push(z);
        }

        const rgba = await zeichne({
            breite: BREITE, hoehe: HOEHE, skala: SKALA,
            grund: `rgb(${v.grund.join(',')})`, rauch: v.rauch,
            deckkraft: v.deckkraft, wolken: sichtbar,
            zugX, zugY: ZUG_Y, zugBreite: ZUG_BREITE, zugHoehe: ZUG_HOEHE,
            wagenteil: WAGENTEIL, anhaenge: ANHAENGE,
        });

        encoder.writeFrame(aufTabelle(rgba, tabelle), BREITE * SKALA, HOEHE * SKALA, {
            palette: i === 0 ? tabelle : undefined,
            delay: cs[i] * 10,
            transparent: DURCHSICHTIG,
            transparentIndex: 0,
            // 2 = raeumen. Mit durchsichtigen Punkten muss jedes Bild vor
            // dem naechsten weg, sonst schmiert der fahrende Zug. Nur das
            // LETZTE bleibt stehen.
            dispose: DURCHSICHTIG && i !== BILDER - 1 ? 2 : 1,
            repeat: -1,
        });

        if (i % 16 === 0) process.stdout.write(`  ${v.key}: Bild ${i + 1}/${BILDER}\r`);
    }

    encoder.finish();
    const gif = Buffer.from(encoder.bytes());
    writeFileSync(`${ASSETS}/zug-dampf-${v.key}.gif`, gif);

    // --- Standbild: Ruhelage, kein Rauch, echtes Alpha ------------------
    const still = await page.evaluate((a) => {
        const c = document.createElement('canvas');
        c.width = a.breite * a.skala;
        c.height = a.hoehe * a.skala;
        const x = c.getContext('2d');
        x.scale(a.skala, a.skala);
        x.globalAlpha = a.deckkraft;
        const schritt = a.zugBreite * a.wagenteil;
        for (let k = a.anhaenge; k >= 1; k -= 1) {
            x.save();
            x.beginPath();
            x.rect(a.zugX - (k * schritt), 0, schritt, a.hoehe);
            x.clip();
            x.drawImage(window.rtZug, a.zugX - (k * schritt), a.zugY, a.zugBreite, a.zugHoehe);
            x.restore();
        }
        x.drawImage(window.rtZug, a.zugX, a.zugY, a.zugBreite, a.zugHoehe);

        return Array.from(new Uint8Array(x.getImageData(0, 0, c.width, c.height).data.buffer));
    }, {
        breite: BREITE, hoehe: HOEHE, skala: SKALA, deckkraft: v.deckkraft,
        zugX: RUHE_X, zugY: ZUG_Y, zugBreite: ZUG_BREITE, zugHoehe: ZUG_HOEHE,
        wagenteil: WAGENTEIL, anhaenge: ANHAENGE,
    });

    const png = new PNG({ width: BREITE * SKALA, height: HOEHE * SKALA });
    png.data.set(new Uint8Array(still));
    const bytes = PNG.sync.write(png, { deflateLevel: 9 });
    writeFileSync(`${ASSETS}/zug-dampf-${v.key}.png`, bytes);

    console.log(`  ${v.key}: GIF ${(gif.length / 1024).toFixed(1)} kB, Standbild ${(bytes.length / 1024).toFixed(1)} kB (${BREITE * SKALA}x${HOEHE * SKALA})`);
}

await browser.close();

for (const v of VARIANTEN) {
    for (const name of [`zug-dampf-${v.key}.gif`, `zug-dampf-${v.key}.png`]) {
        copyFileSync(`${ASSETS}/${name}`, `${OEFFENTLICH}/${name}`);
    }
}

console.log('Nach public/mail-assets kopiert.');
