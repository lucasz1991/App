import { readFileSync, realpathSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

// Source tests do not prove that the deployed Vite entry contains the same
// mail contracts. Check the entry actually selected by the manifest, not an
// arbitrary newer chunk left beside it. This is not a mail-client render test.
export function verifyMailEditorBuild(directory) {
    const root = realpathSync(directory);
    const manifest = JSON.parse(readFileSync(path.join(root, 'manifest.json'), 'utf8'));
    const visited = new Set();
    const sources = [];
    const visit = (key) => {
        if (visited.has(key)) return;
        visited.add(key);
        const entry = manifest[key];
        if (!entry?.file?.endsWith('.js')) throw new Error(`Mail editor build entry missing: ${key}`);
        const file = realpathSync(path.resolve(root, entry.file));
        if (!file.startsWith(root + path.sep)) throw new Error('Build entry escapes its directory.');
        sources.push(readFileSync(file, 'utf8'));
        for (const dependency of entry.imports || []) visit(dependency);
    };
    visit('resources/js/app.js');
    const source = sources.join('\n');
    for (const marker of ['v26', 'data-rt-v26-', 'imgOverlapProfile',
        'Die V26-Geometrie', 'rt-sign-train-layer', 'previewResponsiveCssByArtifact']) {
        if (!source.includes(marker)) {
            throw new Error(`Stale mail editor build: ${marker} is absent from the manifest-selected entry. Rebuild before deploying.`);
        }
    }
    return { ok: true, entry: manifest['resources/js/app.js'].file, contract: 'v26', renderingVerified: false };
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    console.log(JSON.stringify(verifyMailEditorBuild(process.argv[2] || 'public/build')));
}
