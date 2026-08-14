/**
 * Erzeugt die Einfahrt des Signaturzuges — DURCHSICHTIG, in doppelter
 * Aufloesung und groesser im Bild.
 *
 *   zug-dampf-{light,dark}.gif        Einfahrt plus Idle-Rauch, einmalig
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
 * GEOMETRIE: Der Zug wird gegenueber der vorherigen Fassung proportional
 * auf 90 Prozent verkleinert. Seine rechte Kante kommt bei 75 Prozent der
 * Leinwand zur Ruhe; das rechte Viertel bleibt bewusst frei.
 *
 * DER RAUCH IST SEQUENZIERT: Waehrend der Fahrt kommt er nur aus dem
 * mitfahrenden Schornstein. Die stehende Idle-Fahne beginnt erst, nachdem
 * der Zug seine Zielposition vollstaendig erreicht hat. Beides steckt in
 * demselben nicht-loopenden GIF; E-Mail-Clients brauchen weder CSS-Timing
 * noch JavaScript, um die Reihenfolge einzuhalten.
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
const OUTLOOK_BREITE = 720;
const OUTLOOK_HOEHE = 75;

// --- Leinwand ---------------------------------------------------------
const BREITE = Number(process.env.RT_BREITE || 1080);
const SKALA = Number(process.env.RT_SKALA || 2);                       // 1400 x 200 echte Bildpunkte

// --- Zug --------------------------------------------------------------
// Das Motiv ist 2053 : 151 breit zu hoch. Der Faktor sagt, wie viel
// breiter der Zug als die Leinwand gezeichnet wird — je groesser, desto
// naeher steht er am Betrachter und desto mehr laeuft links heraus.
/*
 * WIE GROSS DER ZUG IM BILD STEHT — aus der geforderten Wagenzahl
 * GERECHNET, nicht geschaetzt.
 *
 * Am Motiv nachgemessen: die Lok belegt die Einheiten 1338 bis 2046, ein
 * Wagen misst 330 Einheiten. Fuer die Lok plus WAGEN_SICHTBAR Wagen muss
 * die Leinwand also
 *
 *     708 + WAGEN_SICHTBAR * 330
 *
 * Einheiten zeigen. Der Faktor sagt, wie breit ein Motivdurchlauf
 * gegenueber der Leinwand gezeichnet wird — er ergibt sich daraus als
 * 2053 geteilt durch diese Zahl.
 */
const WAGEN_SICHTBAR = Number(process.env.RT_WAGEN || 6);
const LOK_EINHEITEN = 708;
const WAGEN_EINHEITEN = 330;
const SICHTBARE_EINHEITEN = LOK_EINHEITEN + (WAGEN_SICHTBAR * WAGEN_EINHEITEN);
const BASIS_ZUG_FAKTOR = 2053 / SICHTBARE_EINHEITEN;
const ZUG_MASSSTAB = Number(process.env.RT_ZUG_MASSSTAB || 0.90);
const ZUG_FAKTOR = Number(process.env.RT_FAKTOR || (BASIS_ZUG_FAKTOR * ZUG_MASSSTAB));
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
const BASIS_ZUG_HOEHE = (BREITE * BASIS_ZUG_FAKTOR) * (151 / 2053);
// HOEHE = Zughoehe mal Kopfraum. Der Kopfraum ist der Himmel ueber dem
// Zug, in dem die Rauchfahne aufsteigt — je groesser, desto hoeher darf
// sie ziehen, bevor sie oben abgeschnitten wird.
// Der Kopfraum ist jetzt ein VIELFACHES der Zughoehe, weil der Zug mit
// sechs Wagen deutlich flacher im Bild steht. Ohne diese Entkopplung
// schrumpfte der Himmel mit und die Fahne haette keinen Platz mehr.
const KOPFRAUM = Number(process.env.RT_KOPFRAUM || 1.8);
// Die Leinwand bleibt gleich hoch. Nur das Motiv selbst wird um zehn
// Prozent kleiner und weiterhin sauber am Boden ausgerichtet.
const HOEHE = Math.round(BASIS_ZUG_HOEHE * KOPFRAUM);
const ZIEL_RECHTS = Number(process.env.RT_ZIEL_RECHTS || 0.75);
const RUHE_RECHTS = BREITE * ZIEL_RECHTS;
const RUHE_X = RUHE_RECHTS - ZUG_BREITE;
const START_X = -ZUG_BREITE * (1 + (WAGENTEIL * ANHAENGE));
const ZUG_Y = HOEHE - ZUG_HOEHE;
const SCHORNSTEIN_X = RUHE_RECHTS - (ZUG_BREITE * 0.035);
const SCHORNSTEIN_Y = ZUG_Y + (ZUG_HOEHE * 0.16);

// --- Zeiten (vertraglich, siehe EmailTemplatesPageTest) ---------------
const BILDER = Number(process.env.RT_BILDER || 72);
const ERSTES_CS = 30;
// SIEBEN SEKUNDEN bis zum Stillstand. Danach bleibt das letzte Einzelbild
// an exakt 75 Prozent der Leinwand stehen.
const FAHRT_S = 7.0;

// VORLAUF, bevor der Zug ueberhaupt anfaengt. In dieser Zeit schreiben sich
// Wortmarke und Zeichen fertig — erst danach setzt sich der Zug in
// Bewegung. Ohne den Versatz laeuft alles gleichzeitig und die Signatur
// wirkt unruhig.
const WARTE_S = Number(process.env.RT_WARTE || 1.2);
const FAHRT_ENDE_S = WARTE_S + FAHRT_S;

// Zwei ruhige Rauchzyklen beginnen NACH der vollstaendigen Einfahrt. Ein
// kurzer End-Hold friert das letzte sichtbare Bild ein; der GIF-Loop bleibt
// ausgeschaltet, damit niemals erneut ein Zug ins Bild faehrt.
const IDLE_ZYKLUS_S = Number(process.env.RT_IDLE_ZYKLUS || 2.0);
const IDLE_ZYKLEN = Number(process.env.RT_IDLE_ZYKLEN || 2);
const IDLE_DAUER_S = IDLE_ZYKLUS_S * IDLE_ZYKLEN;
const ENDE_HALT_S = Number(process.env.RT_ENDE_HALT || 0.8);
const GESAMT_S = FAHRT_ENDE_S + IDLE_DAUER_S + ENDE_HALT_S;
const SUMME_CS = Math.round(GESAMT_S * 100);

const STUFEN = Number(process.env.RT_STUFEN || 7);
// DURCHSICHTIG. Der Zug steht in der versendeten Mail als eigenes <img>
// UEBER der Zelle — ein deckender Grund verdeckte dort das Raster des
// Streifens als sichtbaren Kasten. Dass die Datei dadurch kein Byte
// groesser wird, ist gemessen (16 Farben mit und ohne: identisch); der
// Preis ist die Entsorgungsmethode 2, ohne die der fahrende Zug schmieren
// wuerde.
const DURCHSICHTIG = process.env.RT_TRANSPARENT !== '0';

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
    const fahrtAnteil = FAHRT_ENDE_S / GESAMT_S;
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

/*
 * DER RAUCH VERWEHT VOR DEM ENDE — und das ist der Kern des sauberen
 * Uebergangs: Ist das letzte Einzelbild rauchfrei, ist es mit dem
 * Standbild IDENTISCH. Damit kann beim Wechsel vom GIF auf das Standbild
 * (oder wenn ein Client die Animation abbricht) kein Sprung mehr
 * entstehen — es gibt keinen Unterschied, der springen koennte.
 */
const RAUCH_AUS_AB = WARTE_S + (FAHRT_S * 0.62);
const RAUCH_AUS_BIS = FAHRT_ENDE_S;
const WOLKEN = 200;
const wolken = Array.from({ length: WOLKEN }, (_, i) => ({
    geburt: WARTE_S + ((i / WOLKEN) * (FAHRT_S * 0.72)),
    // Streuung breiter als zuvor: gleichfoermige Werte ergaben eine Bank,
    // keine Fahne.
    drift: (-11 - (rausch(i, 1) * 30)) * MASSSTAB,
    steigen: (-6 - (rausch(i, 2) * 11)) * MASSSTAB,
    wuchs: (2.4 + (rausch(i, 3) * 4.4)) * MASSSTAB,
    r0: (1.4 + (rausch(i, 1) * 1.6)) * MASSSTAB,
    phase: rausch(i, 2) * Math.PI * 2,
}));

function wolkeBei(w, t, schornsteinX) {
    const alter = t - w.geburt;
    if (alter < 0) return null;

    // Der Rauch tritt dort aus, wo der Schornstein ZUM ZEITPUNKT DES
    // AUSTRITTS stand — nur so bleibt die Fahne hinter dem Zug zurueck.
    const auftauchen = glatt(w.geburt, w.geburt + 0.35, t);
    const vergehen = 1 - glatt(RAUCH_AUS_AB, RAUCH_AUS_BIS, t);
    if (vergehen <= 0) return null;

    const r = w.r0 + (w.wuchs * alter);

    // DAS IST DER PUNKT FUER DIE GLAUBWUERDIGKEIT: Rauch wird duenner,
    // waehrend er sich ausdehnt — dieselbe Menge verteilt sich auf eine
    // groessere Flaeche. Ohne diese Abnahme steht die alte Fahne genauso
    // dicht wie der frische Ausstoss am Schornstein, und das Ergebnis wirkt
    // wie eine aufgemalte Bank statt wie Dampf.
    const verduennung = (w.r0 / r) ** 1.35;

    return {
        // Die seitliche Streuung waechst mit dem Alter: die Fahne faechert
        // auf, statt als Schnur zu ziehen.
        x: schornsteinX + (w.drift * alter)
            + (Math.sin(w.phase + (alter * 1.7)) * 2.4 * MASSSTAB * (1 + (alter * 0.6))),
        y: SCHORNSTEIN_Y + (w.steigen * alter)
            + (Math.cos(w.phase + (alter * 1.3)) * 1.2 * MASSSTAB * (1 + (alter * 0.4))),
        r,
        alpha: auftauchen * vergehen * 0.26 * verduennung,
    };
}

// --- Idle-Rauch nach der Einfahrt ------------------------------------
// Die Wolken folgen einer periodischen Bahn. Im Gegensatz zur frueheren
// separaten Idle-Datei werden sie aber erst nach FAHRT_ENDE_S gerendert.
// Damit kann rechts kein Rauch vorauseilen, solange der Zug noch unterwegs
// ist. Am Ende wird der letzte Zustand fuer ENDE_HALT_S eingefroren.
const IDLE_WOLKEN = 64;
const idleWolken = Array.from({ length: IDLE_WOLKEN }, (_, i) => ({
    versatz: i / IDLE_WOLKEN,
    drift: (-8 - (rausch(i, 11) * 15)) * MASSSTAB,
    steigen: (-5 - (rausch(i, 12) * 8)) * MASSSTAB,
    wuchs: (1.8 + (rausch(i, 13) * 2.8)) * MASSSTAB,
    r0: (1.1 + (rausch(i, 14) * 1.2)) * MASSSTAB,
    phase: rausch(i, 15) * Math.PI * 2,
}));

function idleWolkenBei(t) {
    if (t <= FAHRT_ENDE_S) return [];

    const bisEnde = Math.max(0, IDLE_DAUER_S - 0.12);
    const idleZeit = Math.min(t - FAHRT_ENDE_S, bisEnde);
    const phase = (idleZeit % IDLE_ZYKLUS_S) / IDLE_ZYKLUS_S;
    const einblenden = glatt(FAHRT_ENDE_S, FAHRT_ENDE_S + 0.65, t);

    return idleWolken.map((w) => {
        const u = (phase + w.versatz) % 1;
        const alter = u * IDLE_ZYKLUS_S;
        const r = w.r0 + (w.wuchs * alter);
        const rand = Math.min(u, 1 - u) / 0.18;
        const blende = Math.min(1, Math.max(0, rand));
        const verduennung = (w.r0 / r) ** 1.35;

        return {
            x: SCHORNSTEIN_X + (w.drift * alter)
                + (Math.sin(w.phase + (alter * 1.7)) * 2.2 * MASSSTAB * (1 + (alter * 0.5))),
            y: SCHORNSTEIN_Y + (w.steigen * alter)
                + (Math.cos(w.phase + (alter * 1.3)) * 1.1 * MASSSTAB * (1 + (alter * 0.35))),
            r,
            alpha: einblenden * blende * 0.20 * verduennung,
        };
    }).filter((z) => z.alpha > 0.004 && z.x > -60 && z.x < BREITE + 60);
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

/**
 * Leitet die Classic-/New-Outlook-Datei deterministisch aus exakt denselben
 * Einzelbildern ab. Die breite 2160×218-Fassung wird proportional auf 720 px
 * Breite verkleinert und in der 75-px-Leinwand unten ausgerichtet.
 */
function skaliereFuerOutlook(rgba, grund) {
    const quelleBreite = BREITE * SKALA;
    const quelleHoehe = HOEHE * SKALA;
    const faktor = Math.min(OUTLOOK_BREITE / quelleBreite, OUTLOOK_HOEHE / quelleHoehe);
    const zielBreite = Math.round(quelleBreite * faktor);
    const zielHoehe = Math.round(quelleHoehe * faktor);
    const links = Math.floor((OUTLOOK_BREITE - zielBreite) / 2);
    const oben = OUTLOOK_HOEHE - zielHoehe;
    const ziel = new Uint8Array(OUTLOOK_BREITE * OUTLOOK_HOEHE * 4);

    for (let p = 0; p < OUTLOOK_BREITE * OUTLOOK_HOEHE; p += 1) {
        ziel[p * 4] = grund[0];
        ziel[(p * 4) + 1] = grund[1];
        ziel[(p * 4) + 2] = grund[2];
        ziel[(p * 4) + 3] = 255;
    }

    for (let y = 0; y < zielHoehe; y += 1) {
        const quelleY = Math.min(quelleHoehe - 1, Math.floor(y / faktor));
        for (let x = 0; x < zielBreite; x += 1) {
            const quelleX = Math.min(quelleBreite - 1, Math.floor(x / faktor));
            const quelle = ((quelleY * quelleBreite) + quelleX) * 4;
            const ausgabe = ((((oben + y) * OUTLOOK_BREITE) + links + x) * 4);
            ziel[ausgabe] = rgba[quelle];
            ziel[ausgabe + 1] = rgba[quelle + 1];
            ziel[ausgabe + 2] = rgba[quelle + 2];
            ziel[ausgabe + 3] = rgba[quelle + 3];
        }
    }

    return ziel;
}

const cs = verzoegerungen();
const zeiten = zeitpunkte(cs);
console.log(`Zeiten: ${cs.length} Bilder, Summe ${cs.reduce((a, b) => a + b, 0)} cs, erstes ${cs[0]} cs`);

for (const v of VARIANTEN) {
    const tinte = v.key === 'dark' ? [216, 221, 228] : [115, 125, 137];
    const tabelle = farbtabelle(v.grund, tinte);
    const encoder = GIFEncoder();
    const outlookEncoder = GIFEncoder();
    let letztesBild = null;

    for (let i = 0; i < BILDER; i += 1) {
        const t = zeiten[i];
        const zugX = mische(START_X, RUHE_X, easeOut((t - WARTE_S) / FAHRT_S));

        const sichtbar = [];
        for (const w of wolken) {
            const xBeiGeburt = mische(START_X, RUHE_X, easeOut((w.geburt - WARTE_S) / FAHRT_S));
            const z = wolkeBei(w, t, SCHORNSTEIN_X + (xBeiGeburt - RUHE_X));
            if (z && z.alpha > 0.004 && z.x > -60 && z.x < BREITE + 60) sichtbar.push(z);
        }
        sichtbar.push(...idleWolkenBei(t));

        const rgba = await zeichne({
            breite: BREITE, hoehe: HOEHE, skala: SKALA,
            grund: `rgb(${v.grund.join(',')})`, rauch: v.rauch,
            deckkraft: v.deckkraft, wolken: sichtbar,
            zugX, zugY: ZUG_Y, zugBreite: ZUG_BREITE, zugHoehe: ZUG_HOEHE,
            wagenteil: WAGENTEIL, anhaenge: ANHAENGE,
        });

        letztesBild = rgba;

        encoder.writeFrame(aufTabelle(rgba, tabelle), BREITE * SKALA, HOEHE * SKALA, {
            palette: i === 0 ? tabelle : undefined,
            delay: cs[i] * 10,
            transparent: DURCHSICHTIG,
            transparentIndex: 0,
            // 2 = raeumen. Mit durchsichtigen Punkten muss jedes Bild v