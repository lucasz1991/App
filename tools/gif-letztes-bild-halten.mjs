/**
 * Sorgt dafuer, dass ein einmalig abspielendes GIF sein letztes Einzelbild
 * STEHEN LAESST, statt die Flaeche danach zu leeren.
 *
 * HINTERGRUND
 * Jedes Einzelbild traegt in seiner Graphic Control Extension eine
 * Entsorgungsmethode:
 *
 *   0  unbestimmt
 *   1  stehen lassen        <- was wir wollen
 *   2  auf Hintergrund zuruecksetzen
 *   3  vorheriges wiederherstellen
 *
 * Der Signaturzug war komplett auf 2 kodiert. Sichtbar wurde das erst am
 * Ende: nach dem letzten Bild leerte der Browser die Flaeche und der Zug
 * verschwand. Zusaetzlich hatte die Datei als letztes Bild ein 1x1-Pixel —
 * beim Anzeigen dieses Pixels wurde zuerst das vorherige VOLLBILD entsorgt,
 * die Leinwand also geleert.
 *
 * Dieses Werkzeug
 *   - setzt alle Entsorgungsmethoden auf 1,
 *   - laesst Anzeigedauern und Bildfolge unangetastet.
 *
 * Aufruf: node tools/gif-letztes-bild-halten.mjs <datei> [weitere...]
 */
import { readFileSync, writeFileSync } from 'node:fs';

// BEWUSST KEINE Aenderung an den Anzeigedauern: die Gesamtlaufzeit der
// Einfahrt ist an anderer Stelle zugesichert (EmailTemplatesPageTest prueft
// sie auf die Hundertstelsekunde). Das Halten erledigt allein die
// Entsorgungsmethode — ohne Schleife zeigt der Browser das letzte
// Einzelbild danach unbegrenzt.

/** Zerlegt ein GIF in seine Bloecke, ohne die Bilddaten zu deuten. */
function zerlege(b) {
    const leinwand = { w: b.readUInt16LE(6), h: b.readUInt16LE(8) };
    let i = 13;
    const flags = b[10];
    if (flags & 0x80) i = 13 + (3 * (2 << (flags & 7)));

    const kopf = b.subarray(0, i);
    const bloecke = [];
    let offeneGce = null;

    while (i < b.length) {
        const typ = b[i];

        if (typ === 0x3b) break;

        if (typ === 0x21) {
            const start = i;
            const label = b[i + 1];
            i += 2;
            const groesse = b[i];
            i += 1 + groesse;
            while (b[i] !== 0) i += 1 + b[i];
            i += 1;

            const roh = b.subarray(start, i);
            if (label === 0xf9) offeneGce = { roh, start };
            else bloecke.push({ art: 'extension', roh });
            continue;
        }

        if (typ === 0x2c) {
            const start = i;
            const rahmen = {
                x: b.readUInt16LE(i + 1), y: b.readUInt16LE(i + 3),
                w: b.readUInt16LE(i + 5), h: b.readUInt16LE(i + 7),
            };
            const lokal = b[i + 9];
            i += 10;
            if (lokal & 0x80) i += 3 * (2 << (lokal & 7));
            i += 1;
            while (b[i] !== 0) i += 1 + b[i];
            i += 1;

            bloecke.push({ art: 'bild', gce: offeneGce?.roh ?? null, roh: b.subarray(start, i), rahmen });
            offeneGce = null;
            continue;
        }

        i += 1;
    }

    return { kopf, bloecke, leinwand };
}

for (const datei of process.argv.slice(2)) {
    const original = readFileSync(datei);
    const { kopf, bloecke, leinwand } = zerlege(original);

    const bilder = bloecke.filter((x) => x.art === 'bild');

    // Ein abschliessendes Miniaturbild bleibt ERHALTEN. Es ist durchsichtig
    // und traegt nichts bei; sobald keine Entsorgung mehr die Flaeche leert,
    // ueberzeichnet es genau nichts. Entfernen wuerde die zugesicherte
    // Gesamtlaufzeit um seine Anzeigedauer verkuerzen.
    const entfernt = 0;

    const verbleibend = bloecke.filter((x) => x.art === 'bild');
    let umgestellt = 0;

    for (const [index, bild] of verbleibend.entries()) {
        if (!bild.gce) continue;
        const gce = Buffer.from(bild.gce);
        const alt = (gce[3] >> 2) & 7;

        if (alt !== 1) {
            gce[3] = (gce[3] & 0b11100011) | (1 << 2);
            umgestellt += 1;
        }

        bild.gce = gce;
    }

    const teile = [kopf];
    for (const block of bloecke) {
        if (block.art === 'bild' && block.gce) teile.push(block.gce);
        teile.push(block.roh);
    }
    teile.push(Buffer.from([0x3b]));

    const neu = Buffer.concat(teile);
    writeFileSync(datei, neu);

    console.log(`${datei.split(/[\\/]/).pop().padEnd(30)} `
        + `${verbleibend.length} Bilder, ${umgestellt} auf "stehen lassen" umgestellt, `
        + `${entfernt} Miniaturbild entfernt, ${(neu.length / 1024).toFixed(1)} kB`);
}
