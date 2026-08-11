/**
 * Erzeugt die Ziel-Koordinaten der Partikelwolke aus dem ECHTEN RT-Icon.
 *
 * FASSUNG 2 — Radius an den Punktabstand gekoppelt.
 *
 * Fassung 1 setzte die Punkte AUF die Kante und liess den Radius fest bei
 * 1,55/1,75 px. Bei 132 Partikeln lagen die Konturpunkte dadurch rund
 * 8,5 px auseinander bei 3,5 px Durchmesser — die Silhouette war sichtbar
 * gestrichelt.
 *
 * Die naive Behebung "Radius = halber Abstand" reicht NICHT. Zwischen R und
 * T liegen nur 40 Einheiten Luft (bei 144 px Canvas rund 3 px). Ein Radius
 * von 40 Einheiten auf Kantenpunkten wuerde diesen Spalt exakt zubruecken —
 * R und T verkleben.
 *
 * Deshalb zwei gekoppelte Massnahmen:
 *
 *   1. RUECKZUG. Die Konturpunkte liegen nicht auf der Kante, sondern genau
 *      r Einheiten INNERHALB. Der Scheibenrand landet damit auf der wahren
 *      Kante — und der sichtbare Abstand zwischen R und T bleibt der wahre
 *      Abstand, unabhaengig vom Radius.
 *   2. ABSTIMMUNG. r wird je Budget so bestimmt, dass die Konturpunkte
 *      genau 2r auseinander liegen. Dann beruehren sich die Scheiben und
 *      der Strich ist geschlossen.
 *
 * Beides braucht ein Abstandsfeld (Entfernung jedes Innenpunktes zur
 * naechsten Aussenkante). Das wird hier einmalig gerechnet; im Browser
 * faellt nichts davon an.
 */
import { writeFileSync } from 'node:fs';
import { PNG } from 'pngjs';

// --- Die Pfade aus Website/Shared/assets/icons/favicon.svg ----------------
const R_PFADE = [
    'M0 0H738L862 152V462L755 583H617L1000 1000H731L460 658V410H675L715 364V206L673 160H0Z',
    'M0 410H205V1000H0Z',
];
const T_PFAD = 'M0 200H670V370H420V1000H245V370H0Z';

const GROESSE = 1000;
const RASTER = 500;                 // 2 Einheiten je Rasterzelle
const EINHEIT = GROESSE / RASTER;   // Einheiten je Rasterzelle
const BUDGETS = [132, 180, 236, 300];
const KONTUR_ANTEIL = 0.62;         // Anteil der Punkte auf der Kontur
const NORM = 1.24;                  // Wertebereich -0.62..0.62 wie bisher

function parsePfad(d) {
    const marken = d.match(/[MHVLZ][^MHVLZ]*/gi) || [];
    const punkte = [];
    let x = 0;
    let y = 0;

    for (const marke of marken) {
        const befehl = marke[0].toUpperCase();
        const zahlen = (marke.slice(1).match(/-?\d+(?:\.\d+)?/g) || []).map(Number);

        if (befehl === 'M' || befehl === 'L') {
            for (let i = 0; i + 1 < zahlen.length; i += 2) { x = zahlen[i]; y = zahlen[i + 1]; punkte.push([x, y]); }
        } else if (befehl === 'H') {
            for (const w of zahlen) { x = w; punkte.push([x, y]); }
        } else if (befehl === 'V') {
            for (const w of zahlen) { y = w; punkte.push([x, y]); }
        }
    }

    return punkte;
}

function imPolygon(poly, px, py) {
    let drin = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i, i += 1) {
        const [xi, yi] = poly[i];
        const [xj, yj] = poly[j];
        if ((yi > py) !== (yj > py) && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) drin = !drin;
    }
    return drin;
}

const rPolys = R_PFADE.map(parsePfad);
const tPoly = parsePfad(T_PFAD);

// --- Masken ---------------------------------------------------------------
const istR = new Uint8Array(RASTER * RASTER);
const istT = new Uint8Array(RASTER * RASTER);

for (let ry = 0; ry < RASTER; ry += 1) {
    const py = (ry + 0.5) * EINHEIT;
    for (let rx = 0; rx < RASTER; rx += 1) {
        const px = (rx + 0.5) * EINHEIT;
        const i = (ry * RASTER) + rx;
        istR[i] = rPolys.some((p) => imPolygon(p, px, py)) ? 1 : 0;
        istT[i] = imPolygon(tPoly, px, py) ? 1 : 0;
    }
}

/**
 * Abstandsfeld: Entfernung jeder Innenzelle zur naechsten Aussenzelle, in
 * Einheiten. Zwei Durchlaeufe mit Chamfer-Gewichten (3,4)/3 — fuer diese
 * Zwecke genau genug und in einem Wimpernschlag gerechnet.
 */
function abstandsfeld(maske) {
    const GROSS = 1e9;
    const d = new Float64Array(RASTER * RASTER);
    for (let i = 0; i < d.length; i += 1) d[i] = maske[i] ? GROSS : 0;

    const setze = (i, wert) => { if (wert < d[i]) d[i] = wert; };
    const GERADE = 1;
    const DIAGONAL = Math.SQRT2;

    for (let y = 0; y < RASTER; y += 1) {
        for (let x = 0; x < RASTER; x += 1) {
            const i = (y * RASTER) + x;
            if (d[i] === 0) continue;
            if (x > 0) setze(i, d[i - 1] + GERADE);
            if (y > 0) setze(i, d[i - RASTER] + GERADE);
            if (x > 0 && y > 0) setze(i, d[i - RASTER - 1] + DIAGONAL);
            if (x < RASTER - 1 && y > 0) setze(i, d[i - RASTER + 1] + DIAGONAL);
        }
    }
    for (let y = RASTER - 1; y >= 0; y -= 1) {
        for (let x = RASTER - 1; x >= 0; x -= 1) {
            const i = (y * RASTER) + x;
            if (d[i] === 0) continue;
            if (x < RASTER - 1) setze(i, d[i + 1] + GERADE);
            if (y < RASTER - 1) setze(i, d[i + RASTER] + GERADE);
            if (x < RASTER - 1 && y < RASTER - 1) setze(i, d[i + RASTER + 1] + DIAGONAL);
            if (x > 0 && y < RASTER - 1) setze(i, d[i + RASTER - 1] + DIAGONAL);
        }
    }

    for (let i = 0; i < d.length; i += 1) d[i] *= EINHEIT;

    return d;
}

// Wichtig: R und T bekommen EIGENE Abstandsfelder. Nur so landet der
// Scheibenrand auf der jeweils eigenen Kante — und der Spalt dazwischen
// bleibt erhalten. Ein gemeinsames Feld ueber der Vereinigung wuerde den
// Spalt ignorieren, sobald die Formen sich naeher kommen.
const dR = abstandsfeld(istR);
const dT = abstandsfeld(istT);

/** Alle Zellen, deren Abstand zur eigenen Kante nahe bei r liegt. */
function konturRing(r) {
    const toleranz = EINHEIT * 1.2;
    const treffer = [];

    for (let ry = 0; ry < RASTER; ry += 1) {
        for (let rx = 0; rx < RASTER; rx += 1) {
            const i = (ry * RASTER) + rx;
            const inR = istR[i] && Math.abs(dR[i] - r) <= toleranz;
            const inT = istT[i] && Math.abs(dT[i] - r) <= toleranz;
            if (!inR && !inT) continue;
            treffer.push({
                x: (rx + 0.5) * EINHEIT,
                y: (ry + 0.5) * EINHEIT,
                ton: istT[i] ? 'slate' : 'red',
            });
        }
    }

    return treffer;
}

/** Innenzellen mit genuegend Abstand, damit die Scheibe nicht uebersteht. */
function innenraum(r) {
    const treffer = [];
    for (let ry = 0; ry < RASTER; ry += 1) {
        for (let rx = 0; rx < RASTER; rx += 1) {
            const i = (ry * RASTER) + rx;
            const tiefe = istT[i] ? dT[i] : dR[i];
            if (!(istR[i] || istT[i]) || tiefe < r * 1.9) continue;
            treffer.push({ x: (rx + 0.5) * EINHEIT, y: (ry + 0.5) * EINHEIT, ton: istT[i] ? 'slate' : 'red' });
        }
    }
    return treffer;
}

function farthestPoint(pool, anzahl) {
    if (!pool.length) return [];
    const gewaehlt = [pool[Math.floor(pool.length / 2)]];
    const abstand = pool.map((p) => ((p.x - gewaehlt[0].x) ** 2) + ((p.y - gewaehlt[0].y) ** 2));

    while (gewaehlt.length < Math.min(anzahl, pool.length)) {
        let bi = 0;
        let bw = -1;
        for (let i = 0; i < pool.length; i += 1) if (abstand[i] > bw) { bw = abstand[i]; bi = i; }
        const neu = pool[bi];
        gewaehlt.push(neu);
        for (let i = 0; i < pool.length; i += 1) {
            const dd = ((pool[i].x - neu.x) ** 2) + ((pool[i].y - neu.y) ** 2);
            if (dd < abstand[i]) abstand[i] = dd;
        }
    }

    return gewaehlt;
}

/** Median des Abstands zum naechsten Nachbarn. */
function medianNachbarabstand(punkte) {
    if (punkte.length < 2) return 0;
    const werte = punkte.map((p, i) => {
        let best = Infinity;
        for (let j = 0; j < punkte.length; j += 1) {
            if (i === j) continue;
            const d = ((punkte[i].x - punkte[j].x) ** 2) + ((punkte[i].y - punkte[j].y) ** 2);
            if (d < best) best = d;
        }
        return Math.sqrt(best);
    }).sort((a, b) => a - b);

    return werte[Math.floor(werte.length / 2)];
}

/**
 * Sucht r so, dass die Konturpunkte genau 2r auseinander liegen.
 * Zu grosses r: Ring wird kurz, Punkte stehen enger als 2r.
 * Zu kleines r: Ring wird lang, Punkte stehen weiter als 2r.
 */
function stimmeRadiusAb(konturBudget) {
    let unten = 6;
    let oben = 70;
    let bestes = null;

    for (let schritt = 0; schritt < 18; schritt += 1) {
        const r = (unten + oben) / 2;
        const ring = konturRing(r);
        if (ring.length < konturBudget) { oben = r; continue; }

        const punkte = farthestPoint(ring, konturBudget);
        const abstand = medianNachbarabstand(punkte);
        bestes = { r, abstand, punkte };

        if (abstand > 2 * r) unten = r;   // Luecken: groesserer Radius
        else oben = r;                    // Ueberlappung: kleinerer Radius
    }

    return bestes;
}

// --- Je Budget rechnen ----------------------------------------------------
const tabellen = {};

for (const budget of BUDGETS) {
    const konturBudget = Math.round(budget * KONTUR_ANTEIL);
    const flaecheBudget = budget - konturBudget;

    const abgestimmt = stimmeRadiusAb(konturBudget);
    const r = abgestimmt.r;
    const kontur = abgestimmt.punkte;
    const flaeche = farthestPoint(innenraum(r), flaecheBudget);

    // Verschraenken, damit auch ein Abbruch mittendrin ausgewogen bleibt.
    const punkte = [];
    let ki = 0;
    let fi = 0;
    while (punkte.length < budget && (ki < kontur.length || fi < flaeche.length)) {
        for (let n = 0; n < 3 && ki < kontur.length && punkte.length < budget; n += 1) { punkte.push(kontur[ki]); ki += 1; }
        for (let n = 0; n < 2 && fi < flaeche.length && punkte.length < budget; n += 1) { punkte.push(flaeche[fi]); fi += 1; }
    }

    // Kleinster Abstand zwischen einem roten und einem anthrazitfarbenen
    // Punkt — er entscheidet, ob R und T optisch getrennt bleiben.
    let minGemischt = Infinity;
    for (const a of punkte) {
        if (a.ton !== 'red') continue;
        for (const b of punkte) {
            if (b.ton !== 'slate') continue;
            const d = Math.hypot(a.x - b.x, a.y - b.y);
            if (d < minGemischt) minGemischt = d;
        }
    }

    tabellen[budget] = {
        radiusUnits: Number(r.toFixed(2)),
        konturAbstand: Number(abgestimmt.abstand.toFixed(1)),
        minGemischt: Number(minGemischt.toFixed(1)),
        punkte: punkte.map((p) => ({
            x: Number((((p.x - (GROESSE / 2)) / GROESSE) * NORM).toFixed(4)),
            y: Number((((p.y - (GROESSE / 2)) / GROESSE) * NORM).toFixed(4)),
            ton: p.ton,
        })),
    };
}

// --- Bericht --------------------------------------------------------------
// px je Einheit = cssSize * 0.42 * (NORM / 1000)
const pxProEinheit = (cssSize) => cssSize * 0.42 * (NORM / GROESSE);

console.log('Budget  r(Einh)  Konturabstand  2r     Spalt rot/anthrazit  r@144px  r@124px  Rand-Luecke');
for (const budget of BUDGETS) {
    const t = tabellen[budget];
    const r144 = t.radiusUnits * pxProEinheit(144);
    const r124 = t.radiusUnits * pxProEinheit(124);
    // Der wahre Spalt ist 40 Einheiten. Punkte liegen r innen, Scheibenrand
    // also auf der Kante: verbleibende Luecke = minGemischt - 2r.
    const luecke = t.minGemischt - (2 * t.radiusUnits);
    console.log(
        `${String(budget).padStart(5)}  ${String(t.radiusUnits).padStart(7)}  ${String(t.konturAbstand).padStart(13)}  `
        + `${(2 * t.radiusUnits).toFixed(1).padStart(5)}  ${String(t.minGemischt).padStart(19)}  `
        + `${r144.toFixed(2).padStart(7)}  ${r124.toFixed(2).padStart(7)}  ${luecke.toFixed(1).padStart(11)}`,
    );
}

// --- Ausgabe fuer den Loader ---------------------------------------------
const teile = BUDGETS.map((budget) => {
    const t = tabellen[budget];
    const zeilen = [];
    for (let i = 0; i < t.punkte.length; i += 4) {
        zeilen.push('        ' + t.punkte.slice(i, i + 4)
            .map((p) => `[${p.x}, ${p.y}, ${p.ton === 'slate' ? 1 : 0}]`).join(', ') + ',');
    }
    return `    ${budget}: {\n        radiusUnits: ${t.radiusUnits},\n        punkte: [\n${zeilen.join('\n')}\n        ],\n    },`;
});
writeFileSync('rt-icon-punkte.txt', teile.join('\n'), 'utf8');
console.log('\nGeschrieben: rt-icon-punkte.txt');

// --- Kontrollbogen --------------------------------------------------------
const ZELLE = 300;
const png = new PNG({ width: ZELLE * BUDGETS.length, height: ZELLE });
for (let i = 0; i < png.data.length; i += 4) {
    png.data[i] = 12; png.data[i + 1] = 16; png.data[i + 2] = 22; png.data[i + 3] = 255;
}

BUDGETS.forEach((budget, spalte) => {
    const t = tabellen[budget];
    const ox = spalte * ZELLE;
    const mitte = ZELLE / 2;
    // Kontrollbogen bei 144 px Canvasgroesse zeichnen, aber auf ZELLE
    // vergroessert — Verhaeltnisse bleiben gleich.
    const skala = ZELLE * 0.42;
    const r = t.radiusUnits * (skala * (NORM / GROESSE));

    t.punkte.forEach((p) => {
        const cx = ox + mitte + (p.x * skala);
        const cy = mitte + (p.y * skala);
        const farbe = p.ton === 'slate' ? [210, 220, 232] : [244, 27, 59];
        const rr = Math.ceil(r) + 1;

        for (let dy = -rr; dy <= rr; dy += 1) {
            for (let dx = -rr; dx <= rr; dx += 1) {
                if (Math.hypot(dx, dy) > r) continue;
                const px = Math.round(cx + dx);
                const py = Math.round(cy + dy);
                if (px < 0 || py < 0 || px >= png.width || py >= png.height) continue;
                const idx = ((py * png.width) + px) * 4;
                png.data[idx] = farbe[0]; png.data[idx + 1] = farbe[1]; png.data[idx + 2] = farbe[2];
            }
        }
    });
});

writeFileSync('rt-icon-kontrollbogen.png', PNG.sync.write(png));
console.log('Geschrieben: rt-icon-kontrollbogen.png');
