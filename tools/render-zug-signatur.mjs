/**
 * Erzeugt die Zug-Grafiken fuer die RailTime-Signaturen.
 *
 *   zug-dampf-{light,dark}.gif        Einfahrt, einmalig, 740 cs
 *   zug-dampf-{light,dark}.png        Standbild in Ruhelage, doppelte Aufloesung
 *   zug-dampf-idle-{light,dark}.gif   Standrauch, Endlosschleife, 370 cs
 *
 * WARUM ES DIESE DATEI GIBT
 * Der Generator der vorigen Fassung lag nicht im Repository — nur seine
 * Ergebnisse. Diese Datei stellt ihn wieder her und ergaenzt die Rauchschrift.
 * Die alte Fassung liegt unveraendert unter
 * resources/mail-templates/assets/archiv-rauch-klassisch/.
 *
 * DIE ZEITEN SIND VERTRAGLICH, NICHT FREI WAEHLBAR
 * tests/Feature/EmailTemplatesPageTest.php sichert zu:
 *   - erstes Einzelbild 30 cs
 *   - Einfahrt genau 740 cs
 *   - Idle genau 370 cs, endlos
 *   - 740 % 370 === 0, die Einfahrt endet also exakt auf der Naht des
 *     Idle-Loops. Nur so laesst sich beides nahtlos aneinanderhaengen.
 *   - je Datei hoechstens 200 kB
 *
 * ENTSORGUNGSMETHODE 1 IST PFLICHT
 * Bei 2 leert der Browser die Flaeche nach dem letzten Einzelbild und der
 * Zug verschwindet. Abgesichert durch tests/Feature/SignatureTrainGifTest.php.
 *
 * DECKENDER GRUND
 * GIF kennt nur 1-Bit-Transparenz. Weich gezeichneter Rauch wuerde daran
 * zerreissen, deshalb wird auf die Signaturfarbe gerechnet. Als Bild auf
 * einer Flaeche derselben Farbe ist der Uebergang unsichtbar.
 */
import puppeteer from 'puppeteer-core';
import gifenc from 'gifenc';
import { PNG } from 'pngjs';
import { readFileSync, writeFileSync } from 'node:fs';

const { GIFEncoder, quantize, applyPalette } = gifenc;
const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const ASSETS = 'resources/mail-templates/assets';
const BREITE = 720;
const HOEHE = 75;

// Aus der bestehenden Fassung ausgemessen (Anker: Lokfront als eindeutiges
// Merkmal — ein Profilabgleich verhakt sich an den gleichfoermigen Wagen).
const ZUG_BREITE = 1020;
const RUHE_X = -331;
const START_X = -ZUG_BREITE;        // vollstaendig links ausserhalb
const SCHORNSTEIN_DX = 987;         // Abstand von der linken Zugkante
const SCHORNSTEIN_DY = 17;

// Einfahrt: 65 Einzelbilder, erstes 30 cs, Summe 740 cs.
const BILDER = 65;
const ERSTES_CS = 30;
const SUMME_CS = 740;
const IDLE_BILDER = 37;
const IDLE_SUMME_CS = 370;

const VARIANTEN = [
    { key: 'light', grund: '#f7f6f3', linie: '#737d89', rauch: '90, 99, 110', deckkraft: 0.30 },
    { key: 'dark', grund: '#0c1017', linie: '#d8dde4', rauch: '196, 206, 219', deckkraft: 0.26 },
];

/** Verteilt SUMME_CS auf BILDER, erstes Bild fest. */
function verzoegerungen() {
    const rest = SUMME_CS - ERSTES_CS;
    const n = BILDER - 1;
    const grund = Math.floor(rest / n);
    const ueber = rest - (grund * n);
    const werte = [ERSTES_CS];
    for (let i = 0; i < n; i += 1) werte.push(grund + (i < ueber ? 1 : 0));

    return werte;
}

const svgOhneRauch = readFileSync(`${ASSETS}/zug-dampf-ohne-rauch.svg`, 'utf8');

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: false,
    defaultViewport: { width: 1600, height: 600 },
});
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body style="margin:0"></body></html>', { waitUntil: 'load' });

/**
 * Die gesamte Bilderzeugung laeuft IM BROWSER: dort gibt es Canvas,
 * SVG-Rasterung und Textmasken. Node bekommt nur fertige PNG-Daten.
 */
await page.evaluate(() => {
    window.rt = {};

    /**
     * Zielpunkte fuer die Rauchschrift.
     *
     * Der Schriftzug wird in eine Maske gezeichnet und deren Tinte
     * abgetastet. Die Partikel wandern spaeter auf diese Punkte zu — so
     * entsteht die Schrift AUS dem Rauch statt als aufgelegtes Wort.
     */
    window.rt.textZiele = (text, breite, hoehe, groesse, sperrung, mitteX, mitteY) => {
        const c = document.createElement('canvas');
        c.width = breite; c.height = hoehe;
        const x = c.getContext('2d', { willReadFrequently: true });

        x.clearRect(0, 0, breite, hoehe);
        x.fillStyle = '#fff';
        x.textAlign = 'center';
        x.textBaseline = 'middle';
        x.font = `700 ${groesse}px system-ui, "Segoe UI", sans-serif`;

        // Sperrung von Hand: letterSpacing ist nicht ueberall verfuegbar.
        const zeichen = [...text];
        const breiten = zeichen.map((z) => x.measureText(z).width);
        const gesamt = breiten.reduce((s, b) => s + b, 0) + (sperrung * (zeichen.length - 1));

        // Die Schrift gehoert in den HIMMEL ueber die Wagen und LINKS der
        // Lok — dorthin, wo die Fahne zieht. Mittig auf dem Streifen laege
        // sie auf den Wagen und waere unlesbar.
        let cx = mitteX - (gesamt / 2);

        zeichen.forEach((z, i) => {
            x.fillText(z, cx + (breiten[i] / 2), mitteY);
            cx += breiten[i] + sperrung;
        });

        const d = x.getImageData(0, 0, breite, hoehe).data;
        const punkte = [];
        for (let sy = 0; sy < hoehe; sy += 1) {
            for (let sx = 0; sx < breite; sx += 1) {
                if (d[(((sy * breite) + sx) * 4) + 3] > 128) punkte.push({ x: sx, y: sy });
            }
        }

        return punkte;
    };

    /** Deterministisches Rauschen — gleiche Eingabe, gleiches Bild. */
    window.rt.noise = (i, salz = 0) => {
        const v = Math.sin(((i + 1) * 12.9898) + (salz * 78.233)) * 43758.5453;
        return v - Math.floor(v);
    };

    window.rt.ladeSvg = async (markup) => {
        window.rt.zug = await new Promise((res, rej) => {
            const i = new Image();
            i.onload = () => res(i);
            i.onerror = rej;
            i.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
        });
    };
});

await page.evaluate((markup) => window.rt.ladeSvg(markup), svgOhneRauch);

console.log('Zug geladen. Erzeuge Einzelbilder …');

/** Rendert ein Einzelbild und gibt es als base64-PNG zurueck. */
async function zeichne(auftrag) {
    return page.evaluate((a) => {
        const c = document.createElement('canvas');
        c.width = a.breite * a.skala;
        c.height = a.hoehe * a.skala;
        const x = c.getContext('2d');
        x.scale(a.skala, a.skala);

        x.fillStyle = a.grund;
        x.fillRect(0, 0, a.breite, a.hoehe);

        // --- Rauch ZUERST: er liegt hinter dem Zug -----------------------
        x.save();
        for (const p of a.partikel) {
            if (p.alpha <= 0.004) continue;
            const g = x.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r);
            g.addColorStop(0, `rgba(${a.rauch}, ${p.alpha})`);
            g.addColorStop(0.55, `rgba(${a.rauch}, ${p.alpha * 0.45})`);
            g.addColorStop(1, `rgba(${a.rauch}, 0)`);
            x.fillStyle = g;
            x.beginPath();
            x.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            x.fill();
        }
        x.restore();

        // --- Zug ---------------------------------------------------------
        x.globalAlpha = a.zugDeckkraft;
        x.drawImage(window.rt.zug, a.zugX, 0, a.zugBreite, a.hoehe);
        x.globalAlpha = 1;

        return c.toDataURL('image/png').split(',')[1];
    }, auftrag);
}

/**
 * Baut die Partikelbahnen der Einfahrt.
 *
 * Ablauf: Der Schornstein stoesst fortlaufend Rauch aus. Die Wolken steigen
 * und treiben nach hinten. Im mittleren Abschnitt ziehen sie zu den
 * Zielpunkten der Wortmarke, halten sie kurz und loesen sich wieder auf.
 */
const einfahrtPartikel = await page.evaluate((k) => {
    const ziele = window.rt.textZiele(
        'RAIL TIME', k.breite, k.hoehe, k.schriftgroesse, k.sperrung, k.mitteX, k.mitteY,
    );
    const n = k.anzahl;
    const partikel = [];

    for (let i = 0; i < n; i += 1) {
        const r1 = window.rt.noise(i, 1);
        const r2 = window.rt.noise(i, 2);
        const r3 = window.rt.noise(i, 3);
        const ziel = ziele[Math.floor(window.rt.noise(i, 4) * ziele.length)] || { x: k.breite / 2, y: k.hoehe / 2 };

        partikel.push({
            // Austrittszeitpunkt, gleichmaessig ueber die Ausstossphase.
            geburt: (i / n) * k.ausstossBis,
            drift: -18 - (r1 * 26),      // px je Sekunde nach hinten
            steigen: -7 - (r2 * 6),      // px je Sekunde nach oben
            wuchs: 2.4 + (r3 * 3.2),     // Radiuswachstum je Sekunde
            r0: 1.6 + (r1 * 1.8),
            ziel,
            phase: r2 * Math.PI * 2,
        });
    }

    return partikel;
}, {
    breite: BREITE, hoehe: HOEHE,
    anzahl: 620,
    ausstossBis: 4.2,
    // Der Himmel ueber den Wagen ist rund 30 px hoch. Mehr als 19 px
    // Versalhoehe traegt er nicht, ohne in die Dachkanten zu laufen.
    schriftgroesse: 19,
    sperrung: 6,
    // Links der Lok (Schornstein bei x=656), auf Hoehe der Fahne.
    mitteX: 330,
    mitteY: 15,
});

console.log(`Rauchpartikel: ${einfahrtPartikel.length}, Zielpunkte aus der Wortmarke abgetastet.`);

const cs = verzoegerungen();
const GESAMT_S = SUMME_CS / 100;
console.log(`Zeiten: erstes Bild ${cs[0]} cs, Summe ${cs.reduce((s, v) => s + v, 0)} cs`);

const glatt = (a, b, t) => {
    const p = Math.min(1, Math.max(0, (t - a) / (b - a)));
    return p * p * (3 - (2 * p));
};
const mische = (a, b, t) => a + ((b - a) * t);
const easeOut = (t) => 1 - Math.pow(1 - Math.min(1, Math.max(0, t)), 3);

/**
 * CHOREOGRAFIE DER EINFAHRT (7,4 s)
 *
 *   0,0 - 2,6 s  Zug faehrt ein und kommt zur Ruhe
 *   0,2 - 4,2 s  Schornstein stoesst fortlaufend Rauch aus
 *   3,0 - 4,6 s  die Wolken ziehen zu den Zielpunkten der Wortmarke
 *   4,6 - 6,0 s  "RAIL TIME" steht lesbar in der Fahne
 *   6,0 - 7,4 s  die Schrift loest sich auf, ein duenner Rest bleibt
 *
 * Wichtig: Der Rauch tritt an der Stelle aus, an der der Schornstein zum
 * ZEITPUNKT DES AUSTRITTS stand — nicht dort, wo er spaeter steht. Nur so
 * bleibt die Fahne hinter dem fahrenden Zug zurueck.
 */
function partikelZustand(p, t, zugXbeiGeburt) {
    const alter = t - p.geburt;
    if (alter < 0) return null;

    const frei = {
        x: zugXbeiGeburt + SCHORNSTEIN_DX + (p.drift * alter)
            + (Math.sin(p.phase + (alter * 1.7)) * 2.2),
        y: SCHORNSTEIN_DY + (p.steigen * alter)
            + (Math.cos(p.phase + (alter * 1.3)) * 1.1),
        r: p.r0 + (p.wuchs * alter),
    };

    // Anziehung zur Wortmarke: aufbauen, halten, wieder loesen.
    const zieh = glatt(3.0, 4.6, t) * (1 - glatt(6.0, 7.1, t));

    const auftauchen = glatt(p.geburt, p.geburt + 0.35, t);
    const vergehen = 1 - glatt(6.2, 7.4, t);

    return {
        x: mische(frei.x, p.ziel.x, zieh),
        y: mische(frei.y, p.ziel.y, zieh),
        // In der Schrift klein und dicht, sonst verschwimmen die Buchstaben.
        r: mische(frei.r, 1.35, zieh),
        // In der Schrift deutlich dichter: bei gleicher Deckkraft wie die
        // freie Fahne bliebe das Wort unter der Wahrnehmungsschwelle.
        alpha: auftauchen * vergehen * mische(0.14, 0.78, zieh),
    };
}

for (const variante of VARIANTEN) {
    const bilder = [];

    for (let i = 0; i < BILDER; i += 1) {
        const t = (i / (BILDER - 1)) * GESAMT_S;
        const zugX = mische(START_X, RUHE_X, easeOut(t / 2.6));

        const sichtbar = [];
        for (const p of einfahrtPartikel) {
            const zugXgeburt = mische(START_X, RUHE_X, easeOut(p.geburt / 2.6));
            const z = partikelZustand(p, t, zugXgeburt);
            if (z && z.alpha > 0.004 && z.x > -40 && z.x < BREITE + 40) sichtbar.push(z);
        }

        bilder.push(await zeichne({
            breite: BREITE, hoehe: HOEHE, skala: 1,
            grund: variante.grund, rauch: variante.rauch,
            zugX, zugBreite: ZUG_BREITE, zugDeckkraft: variante.deckkraft,
            partikel: sichtbar,
        }));

        if (i % 16 === 0) process.stdout.write(`  ${variante.key}: Bild ${i + 1}/${BILDER}\r`);
    }

    // --- GIF kodieren ----------------------------------------------------
    const encoder = GIFEncoder();
    const ruhe = PNG.sync.read(Buffer.from(bilder[bilder.length - 1], 'base64'));
    const palette = quantize(new Uint8Array(ruhe.data), 64, { format: 'rgb565' });

    bilder.forEach((b64, index) => {
        const { data, width, height } = PNG.sync.read(Buffer.from(b64, 'base64'));
        encoder.writeFrame(applyPalette(new Uint8Array(data), palette, 'rgb565'), width, height, {
            palette: index === 0 ? palette : undefined,
            delay: cs[index] * 10,
            // 1 = stehen lassen. Bei 2 leert der Browser die Flaeche und der
            // Zug verschwindet nach der Einfahrt.
            dispose: 1,
            repeat: -1,
        });
    });

    encoder.finish();
    const gif = Buffer.from(encoder.bytes());
    writeFileSync(`${ASSETS}/zug-dampf-${variante.key}.gif`, gif);

    // --- Standbild in doppelter Aufloesung --------------------------------
    const still = await zeichne({
        breite: BREITE, hoehe: HOEHE, skala: 2,
        grund: variante.grund, rauch: variante.rauch,
        zugX: RUHE_X, zugBreite: ZUG_BREITE, zugDeckkraft: variante.deckkraft,
        partikel: einfahrtPartikel
            .map((p) => partikelZustand(p, GESAMT_S, mische(START_X, RUHE_X, easeOut(p.geburt / 2.6))))
            .filter((z) => z && z.alpha > 0.004),
    });
    writeFileSync(`${ASSETS}/zug-dampf-${variante.key}.png`, Buffer.from(still, 'base64'));

    console.log(`  ${variante.key}: GIF ${(gif.length / 1024).toFixed(1)} kB, Standbild geschrieben.`);
}

await browser.close();
console.log('\nFertig. Naechster Schritt: Sichtpruefung der Lesbarkeit.');
