/*
 * Verwaiste Vite-Assets aus public/build/assets entfernen.
 *
 * Warum ueberhaupt noetig?
 * In vite.config.js steht `emptyOutDir: false` – und das MUSS so bleiben:
 * public/build enthaelt neben assets/ auch handgepflegte Ordner (libs/ mit
 * CKEditor, css/, fonts/, icons/), die ein automatisches Leeren mitnehmen
 * wuerde. Der Preis dafuer: Jeder Build legt neue Dateien mit neuem Hash ab,
 * die alten bleiben liegen. Ohne Aufraeumen waechst der Ordner unbegrenzt
 * (Stand 29.07.2026: 319 Dateien / 77 MB, davon 308 verwaist).
 *
 * Warum nicht einfach assets/ vor dem Build leeren?
 * Weil der Build dann ein Zeitfenster hinterlaesst, in dem die Anwendung ihre
 * Dateien nicht ausliefern kann. Hier wird NACH erfolgreichem Build gelesen,
 * was noch gebraucht wird – ausgeliefert wird also durchgehend etwas Gueltiges.
 *
 * Warum nicht allein das Manifest fragen?
 * Weil dort nicht zwingend alles steht, was gebraucht wird: Schriftarten und
 * andere per url() eingebettete Dateien tauchen je nach Vite-Version nicht als
 * eigener Eintrag auf. Deshalb wird ab den Manifest-Dateien die transitive
 * Referenzhuelle gebildet – jede Textdatei wird nach Namen weiterer Assets
 * durchsucht, bis nichts Neues mehr hinzukommt.
 *
 * Aufruf:
 *   node scripts/prune-build-assets.mjs            (aufraeumen)
 *   node scripts/prune-build-assets.mjs --dry-run  (nur berichten)
 */
import { readFileSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const appDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const buildDir = join(appDir, 'public', 'build');
const assetsDir = join(buildDir, 'assets');
const dryRun = process.argv.includes('--dry-run');

/** Ein defektes Manifest darf NIE zum Loeschen aller Assets fuehren. */
function readManifest() {
    try {
        const manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8'));

        if (!manifest || typeof manifest !== 'object' || Object.keys(manifest).length === 0) {
            throw new Error('Das Manifest ist leer.');
        }

        return manifest;
    } catch (error) {
        console.error(`✗ manifest.json nicht lesbar – es wird nichts geloescht. (${error.message})`);
        process.exit(1);
    }
}

function listAssets() {
    try {
        return new Set(
            readdirSync(assetsDir).filter((name) => statSync(join(assetsDir, name)).isFile()),
        );
    } catch (_) {
        console.log('· public/build/assets existiert nicht – nichts zu tun.');
        process.exit(0);
    }
}

const manifest = readManifest();
const present = listAssets();
const keep = new Set();

const remember = (value) => {
    if (typeof value !== 'string') return;
    const base = value.split('/').pop();
    if (present.has(base)) keep.add(base);
};

for (const entry of Object.values(manifest)) {
    remember(entry.file);
    (entry.css || []).forEach(remember);
    (entry.assets || []).forEach(remember);
}

if (keep.size === 0) {
    console.error('✗ Das Manifest nennt keine vorhandene Datei – es wird nichts geloescht.');
    process.exit(1);
}

// Transitive Huelle: nur Textdateien koennen auf weitere Assets verweisen.
const scannable = /\.(js|css|svg|json|map)$/i;
const queue = [...keep];

while (queue.length > 0) {
    const name = queue.pop();

    if (!scannable.test(name)) {
        continue;
    }

    let text;

    try {
        text = readFileSync(join(assetsDir, name), 'utf8');
    } catch (_) {
        continue;
    }

    for (const candidate of present) {
        if (!keep.has(candidate) && text.includes(candidate)) {
            keep.add(candidate);
            queue.push(candidate);
        }
    }
}

const stale = [...present].filter((name) => !keep.has(name)).sort();

if (stale.length === 0) {
    console.log(`· Keine verwaisten Assets (${keep.size} in Benutzung).`);
    process.exit(0);
}

const megabytes = stale.reduce((sum, name) => sum + statSync(join(assetsDir, name)).size, 0) / 1024 / 1024;

if (dryRun) {
    console.log(`· ${stale.length} verwaiste Datei(en), ${megabytes.toFixed(1)} MB – Probelauf, nichts geloescht.`);
    process.exit(0);
}

let removed = 0;

for (const name of stale) {
    try {
        unlinkSync(join(assetsDir, name));
        removed += 1;
    } catch (error) {
        console.error(`  ! ${name}: ${error.message}`);
    }
}

console.log(`✓ ${removed} verwaiste Datei(en) entfernt (${megabytes.toFixed(1)} MB), ${keep.size} in Benutzung.`);
