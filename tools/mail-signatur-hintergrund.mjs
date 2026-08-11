/**
 * Erzeugt die Hintergrundgrafik des Signaturstreifens.
 *
 *   signatur-grund-light.png   weisser Grund
 *   signatur-grund-dark.png    dunkler Grund
 *
 * VORLAGE IST DER NOTFALLBANNER der Website (.rt-emergency-banner--v2).
 * Von dort stammt die Bildsprache, nachgemessen am laufenden Auftritt:
 *
 *   - ein feines technisches Raster, 64 px, 1-px-Linien
 *   - ein GROSSES RT-Wasserzeichen als Maske ueber einem Verlauf,
 *     Deckkraft rund 7,5 %
 *   - ein roter Radialschimmer rechts (70% 180% at 96% 48%)
 *
 * Umgesetzt fuer die Signatur auf HELLEM Grund: dieselben Elemente, aber
 * als dunkle Linien auf Weiss statt heller Linien auf Schwarz.
 *
 * WARUM EIN BILD UND KEIN CSS: Outlook-Desktop rendert mit der Word-Engine
 * und kennt weder Verlaeufe noch Masken. Als PNG steht die Grafik ueberall.
 *
 * Gerechnet wird im Browser (Verlaeufe, Masken), geliefert wird ein PNG in
 * doppelter Aufloesung.
 *
 * Aufruf: node tools/mail-signatur-hintergrund.mjs
 */
import puppeteer from 'puppeteer-core';
import { writeFileSync, copyFileSync, readFileSync } from 'node:fs';

const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ASSETS = 'resources/mail-templates/assets';
const OEFFENTLICH = 'public/mail-assets';

// Der Streifen ist auf jeder Schirmbreite anders breit. Ein Bild in
// Signaturbreite wuerde deshalb gestaucht — das Raster wird stattdessen
// WIEDERHOLT. Dafuer muss die Kachel ein VIELFACHES der Rasterweite sein,
// sonst hat sie an jeder Kante eine sichtbare Naht: 128 = 2 x 64.
// Wasserzeichen und Schimmer liegen in einer eigenen, NICHT wiederholten
// Ebene (siehe signature.blade.php).
const RASTER = 64;
const KACHEL = RASTER * 2;
const SKALA = 2;

const LOGO = readFileSync('public/rt-brand/rt-logo.svg', 'utf8');

const VARIANTEN = [
    {
        key: 'light',
        grund: '#ffffff',
        raster: 'rgba(16, 21, 27, .055)',
        schimmer: 'rgba(228, 0, 43, .07)',
        marke: ['#e4002b', '#8a94a1'],
        markeDeckkraft: 0.06,
    },
    {
        key: 'dark',
        grund: '#0c1017',
        raster: 'rgba(255, 255, 255, .045)',
        schimmer: 'rgba(228, 0, 43, .16)',
        marke: ['#e4002b', '#8a94a1'],
        markeDeckkraft: 0.1,
    },
];

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
});

for (const v of VARIANTEN) {
    const page = await browser.newPage();
    await page.setViewport({ width: KACHEL, height: KACHEL, deviceScaleFactor: SKALA });

    // --- Kachel: nur das Raster, nahtlos wiederholbar ---------------------
    await page.setContent(`<!doctype html><html style="background:transparent"><body style="margin:0;background:transparent">
      <div style="width:${KACHEL}px;height:${KACHEL}px;background-color:${v.grund};
        background-image:
          linear-gradient(${v.raster} 1px, transparent 1px),
          linear-gradient(90deg, ${v.raster} 1px, transparent 1px);
        background-size:${RASTER}px ${RASTER}px,${RASTER}px ${RASTER}px;
        background-position:0 0,0 0;"></div>
    </body></html>`, { waitUntil: 'load' });

    const kachel = await page.screenshot({ clip: { x: 0, y: 0, width: KACHEL, height: KACHEL } });
    writeFileSync(`${ASSETS}/signatur-raster-${v.key}.png`, kachel);

    // --- Marke: Wasserzeichen plus Schimmer, EINMAL rechts ----------------
    // Eigene Ebene, weil sie NICHT gekachelt werden darf.
    const mb = 440;
    const mh = 200;
    await page.setViewport({ width: mb, height: mh, deviceScaleFactor: SKALA });
    // DURCHSICHTIG: Diese Ebene liegt UEBER dem Raster. Haette sie einen
    // eigenen Grund, verdeckte sie es vollstaendig.
    await page.setContent(`<!doctype html><html style="background:transparent"><body style="margin:0;background:transparent">
      <div style="position:relative;width:${mb}px;height:${mh}px;overflow:hidden">
        <div style="position:absolute;inset:0;background:
          radial-gradient(70% 180% at 96% 48%, ${v.schimmer}, transparent 62%)"></div>
        <div style="position:absolute;right:26px;top:50%;transform:translateY(-50%);
          width:152px;height:152px;opacity:${v.markeDeckkraft};
          background:linear-gradient(145deg, ${v.marke[0]} 12%, ${v.marke[1]} 78%);
          -webkit-mask:url('data:image/svg+xml;charset=utf-8,${encodeURIComponent(LOGO)}') no-repeat center/contain;
          mask:url('data:image/svg+xml;charset=utf-8,${encodeURIComponent(LOGO)}') no-repeat center/contain;"></div>
      </div>
    </body></html>`, { waitUntil: 'load' });
    await new Promise((r) => setTimeout(r, 250));

    const marke = await page.screenshot({ clip: { x: 0, y: 0, width: mb, height: mh }, omitBackground: true });
    writeFileSync(`${ASSETS}/signatur-marke-${v.key}.png`, marke);

    console.log(`${v.key}: Raster ${(kachel.length / 1024).toFixed(1)} kB, Marke ${(marke.length / 1024).toFixed(1)} kB`);
    await page.close();
}

await browser.close();

for (const v of VARIANTEN) {
    for (const name of [`signatur-raster-${v.key}.png`, `signatur-marke-${v.key}.png`]) {
        copyFileSync(`${ASSETS}/${name}`, `${OEFFENTLICH}/${name}`);
    }
}

console.log('Nach public/mail-assets kopiert.');
