/**
 * Erzeugt die bewegten Markenbilder fuer Signatur und Vorlage.
 *
 *   wortmarke-*.gif   Der Schriftzug wird GESCHRIEBEN: eine schraege
 *                     Kante zieht Zeichen fuer Zeichen ueber die Flaeche,
 *                     danach die Linie in einem Zug, zuletzt GMBH.
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

const BILDER = 60;
const SUMME_CS = 620;

// Das RT-Zeichen laeuft dauerhaft und braucht deshalb eine eigene, laengere
// Folge. WICHTIG: Ein GIF wiederholt IMMER die ganze Animation — es gibt
// kein "ab Bild N schleifen". Damit der Aufbau nicht bei jedem Durchlauf
// hart neu anspringt, ist die Folge GESCHLOSSEN: der Ruheteil kehrt am Ende
// genau in den Zustand zurueck, in dem der Aufbau beginnt. Das Zeichen ist
// dabei nie ganz weg, es sinkt nur auf einen leisen Rest.
const ZEICHEN_BILDER = 64;
const ZEICHEN_SUMME_CS = 900;
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

function zeichenVerzoegerungen() {
    const grund = Math.floor(ZEICHEN_SUMME_CS / ZEICHEN_BILDER);
    const werte = new Array(ZEICHEN_BILDER).fill(grund);
    werte[ZEICHEN_BILDER - 1] += ZEICHEN_SUMME_CS - (grund * ZEICHEN_BILDER);

    return werte;
}

/**
 * Baut die Farbtabelle und kodiert.
 *
 * @param bilder  Liste roher RGBA-Puffer, gleiche Groesse
 */
function schreibeGif(bilder, breite, hoehe, ziel, takt = cs, schleife = false) {
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
            delay: takt[index] * 10,
            transparent: true,
            transparentIndex: durchsichtig,
            // 2 = raeumen. Waehrend des Aufbaus wachsen die Teile; ohne
            // Raeumen bliebe jeder Zwischenstand stehen und die weichen
            // Kanten wuerden sich uebereinanderlegen.
            dispose: index === bilder.length - 1 && ! schleife ? 1 : 2,
            // 0 = endlos, -1 = kein Schleifenblock (spielt einmal).
            repeat: schleife ? 0 : -1,
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
        // Die Wortmarke traegt seit dem Zuschnitt KEIN Zusatzband mehr
        // (Linie und GMBH sind entfallen). Findet sich keines, gilt das
        // ganze Bild als Schriftzug und die beiden Nachlaufphasen entfallen
        // — dann darf das Schreiben die volle Laufzeit nutzen.
        let trenner = H;
        for (let y = Math.floor(H * 0.5); y < H - 1; y += 1) {
            if (zeileTinte[y] === 0 && zeileTinte[y + 1] > 0) { trenner = y + 1; break; }
        }
        const mitZusatz = trenner < H;

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

        // Aus der Spalten-Kennung zusammenhaengende Baender machen. Damit
        // laesst sich in EINEM Zug zeichnen statt Spalte fuer Spalte.
        const baender = (pruefe) => {
            const raus = [];
            let start = -1;
            for (let sx = 0; sx <= B; sx += 1) {
                const drin = sx < B && pruefe(sx);
                if (drin && start < 0) start = sx;
                if (! drin && start >= 0) { raus.push([start, sx]); start = -1; }
            }

            return raus;
        };
        const linienBaender = baender((sx) => ! istText[sx]);
        const textBaender = baender((sx) => istText[sx]);

        const aus = [];
        for (let i = 0; i < a.bilder; i += 1) {
            const t = i / (a.bilder - 1);

            const c = document.createElement('canvas');
            c.width = B; c.height = H;
            const x = c.getContext('2d');

            // --- Zeichen werden GESCHRIEBEN ------------------------------
            // Nicht eingeblendet, sondern gezogen: Jedes Zeichen wird von
            // einer schraegen Kante freigelegt, die seiner Neigung folgt —
            // so, wie ein Stift ueber das Papier laeuft. Die Schraege ist
            // wesentlich: Der Schriftzug ist kursiv, eine senkrechte Kante
            // wirkte wie eine Jalousie, keine Schreibbewegung.
            const NEIGUNG = 0.30;          // seitlicher Versatz je Hoeheneinheit
            const KANTE = 9;               // Weichzeichnung der Schreibkante
            const zeichenBis = mitZusatz ? 0.60 : 0.86;
            for (let k = 0; k < grenzen.length - 1; k += 1) {
                const von = grenzen[k];
                const bis = grenzen[k + 1];
                const start = (k / (grenzen.length - 1)) * zeichenBis;
                // Weicher gefuehrt: anlaufen, ziehen, auslaufen — statt mit
                // gleichbleibender Geschwindigkeit durchzufahren. Eine
                // Schreibbewegung ist nie gleichfoermig.
                const roh = klemm((t - start) / 0.20);
                const p = roh * roh * (3 - (2 * roh));
                if (p <= 0) continue;

                if (p >= 1) {
                    x.drawImage(bild, von, 0, bis - von, trenner, von, 0, bis - von, trenner);
                    continue;
                }

                // Die Kante laeuft von links nach rechts durch das Zeichen
                // und ist oben weiter vorn als unten — entlang der Kursive.
                const versatz = trenner * NEIGUNG;
                const spitze = von - versatz - KANTE + (p * (bis - von + (2 * versatz) + (2 * KANTE)));

                x.save();
                x.beginPath();
                x.moveTo(spitze + versatz, 0);
                x.lineTo(von - versatz - KANTE, 0);
                x.lineTo(von - versatz - KANTE, trenner);
                x.lineTo(spitze - versatz, trenner);
                x.closePath();
                x.clip();
                x.drawImage(bild, von, 0, bis - von, trenner, von, 0, bis - von, trenner);

                // Die Spitze des Stiftes: ein schmaler heller Streifen an
                // der Schreibkante, der nur auf der Schrift selbst sichtbar
                // wird (source-atop).
                const g = x.createLinearGradient(spitze - 14, 0, spitze + 2, 0);
                g.addColorStop(0, 'rgba(255,255,255,0)');
                g.addColorStop(1, 'rgba(255,255,255,0.55)');
                x.globalCompositeOperation = 'source-atop';
                x.fillStyle = g;
                x.fillRect(von - versatz - KANTE, 0, (bis - von) + (2 * versatz) + (2 * KANTE), trenner);
                x.globalCompositeOperation = 'source-over';
                x.restore();
            }

            // --- Danach die Linie, von links nach rechts -----------------
            // Wie ein Unterstrich, den man in einem Zug zieht.
            //
            // FLAECHIG ueber einen Beschnitt, NICHT spaltenweise: Der frueher
            // hier stehende 1-px-Blit je Spalte setzte an jeder Spaltenkante
            // neu an. Bei weichen Kanten ergab das eine Reihe feiner Punkte —
            // genau die gemeldeten Stoerungen an Strich und GMBH.
            const linieP = mitZusatz ? glatt(klemm((t - 0.62) / 0.22)) : 0;
            if (linieP > 0) {
                x.save();
                x.beginPath();
                for (const [von, bis] of linienBaender) {
                    const w = Math.min(bis, B * linieP) - von;
                    if (w > 0) x.rect(von, trenner, w, H - trenner);
                }
                x.clip();
                x.drawImage(bild, 0, trenner, B, H - trenner, 0, trenner, B, H - trenner);
                x.restore();
            }

            // --- Zuletzt GMBH ------------------------------------------
            const textP = mitZusatz ? glatt(klemm((t - 0.82) / 0.18)) : 0;
            if (textP > 0) {
                x.save();
                x.globalAlpha = textP;
                x.beginPath();
                for (const [von, bis] of textBaender) x.rect(von, trenner, bis - von, H - trenner);
                x.clip();
                x.drawImage(bild, 0, trenner, B, H - trenner, 0, trenner, B, H - trenner);
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

const zeichenTakt = zeichenVerzoegerungen();

for (const zeichen of ZEICHEN) {
    const { bilder, breite, hoehe } = await page.evaluate(async (a) => {
        const G = 132;
        const glatt = (v) => v * v * (3 - (2 * v));
        const klemm = (v) => Math.min(1, Math.max(0, v));

        // Reihenfolge wie gewuenscht: erst der rote Balken links unten,
        // dann das rote R aussen herum, zuletzt das T in der Mitte.
        const stufen = [
            { d: a.pfade[1], farbe: '#e4002b', start: 0.02 },
            { d: a.pfade[0], farbe: '#e4002b', start: 0.10 },
            { d: a.pfade[2], farbe: a.tFarbe, start: 0.19 },
        ];

        // Abschnitte der geschlossenen Folge, als Anteil der Laufzeit:
        //   0,00 - 0,30  Aufbau in drei Stufen
        //   0,30 - 0,86  Ruhe: ein leises Kippen um die Hochachse mit
        //                wanderndem Glanz — die Anmutung des 3D-Modells der
        //                Anmeldeseite, mit den Mitteln einer Flaeche
        //   0,86 - 1,00  Zurueck auf den leisen Rest, mit dem der Aufbau
        //                beginnt: nur so schliesst sich der Kreis ohne Sprung
        const RUHE_AB = 0.30;
        const RUECK_AB = 0.86;
        const REST = 0.16;

        const aus = [];
        // PHASENVERSATZ: Der Zyklus startet am FERTIGEN Zeichen, nicht am
        // leeren. Sichtbar ist derselbe Ablauf — aber das ERSTE Einzelbild
        // zeigt die Marke vollstaendig, und genau dieses eine sehen alle
        // Vorschauen und jeder Client, der Animationen nicht abspielt.
        // Ohne den Versatz wirkte das Zeichen schlicht als fehlend.
        const VERSATZ = 0.30;

        for (let i = 0; i < a.bilder; i += 1) {
            const t = ((i / a.bilder) + VERSATZ) % 1;

            const c = document.createElement('canvas');
            c.width = G; c.height = G;
            const x = c.getContext('2d');

            // Ruhephase: ein Kippen um die Hochachse, angenaehert durch
            // eine Stauchung in der Breite. Ein volles Hin und Her je
            // Durchlauf, damit Anfang und Ende gleich stehen.
            const ruheP = klemm((t - RUHE_AB) / (RUECK_AB - RUHE_AB));
            const kippen = Math.sin(ruheP * Math.PI * 2);
            const breitSkala = 1 - (Math.abs(kippen) * 0.09);

            // Rueckgang auf den leisen Rest — der Ausgangspunkt des Aufbaus.
            const rueck = glatt(klemm((t - RUECK_AB) / (1 - RUECK_AB)));

            for (const stufe of stufen) {
                const p = glatt(klemm((t - stufe.start) / 0.09));
                if (p <= 0) continue;

                const deckung = Math.max(REST * rueck, p * (1 - rueck)) + (rueck * REST * 0);
                const sichtbar = rueck > 0 ? ((p * (1 - rueck)) + (REST * rueck)) : p;
                if (sichtbar <= 0.01) continue;

                x.save();
                x.globalAlpha = sichtbar;
                // Aus dem Nichts: leicht zu gross und unscharf beginnen,
                // dann in die eigene Lage einrasten.
                const skala = 1 + ((1 - p) * 0.14);
                x.translate(G / 2, G / 2);
                x.scale(skala * breitSkala, skala);
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
    }, { pfade, tFarbe: zeichen.t, bilder: ZEICHEN_BILDER });

    schreibeGif(bilder.map((b) => new Uint8Array(b)), breite, hoehe, `${zeichen.key}.gif`, zeichenTakt, true);
}

await browser.close();
console.log('Nach public/mail-assets kopiert.');
