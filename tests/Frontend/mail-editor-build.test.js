import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { verifyMailEditorBuild } from '../../scripts/verify-mail-editor-build.mjs';

const geometryMarkers = 'v26 data-rt-v26- imgOverlapProfile Die V26-Geometrie rt-sign-train-layer previewResponsiveCssByArtifact';
const transportMarkers = `${geometryMarkers} --rt-mail-outlook- Outlook-Stilregel nicht verlustfrei lesen reservierten Outlook-Parserwert`;
const markers = `${transportMarkers} rt-mail-cta Mail-Medienbibliothek verwenden.`;

function fixture(run) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'railtime-mail-build-test-'));
    try {
        mkdirSync(path.join(root, 'assets'));
        writeFileSync(path.join(root, 'manifest.json'), JSON.stringify({ 'resources/js/app.js': { file: 'assets/app.js' } }));
        run(root);
    } finally {
        assert.ok(path.basename(root).startsWith('railtime-mail-build-test-'));
        assert.equal(path.dirname(root), os.tmpdir());
        rmSync(root, { recursive: true });
    }
}

test('mail release gate rejects old manifest-selected bundle even if a current unused chunk exists', () => fixture((root) => {
    writeFileSync(path.join(root, 'assets/app.js'), 'rt-sign-train-layer');
    writeFileSync(path.join(root, 'assets/unused-new.js'), markers);
    assert.throws(() => verifyMailEditorBuild(root), /Stale mail editor build/);
}));

test('mail release gate accepts V26 code but does not claim client rendering', () => fixture((root) => {
    writeFileSync(path.join(root, 'assets/app.js'), markers);
    assert.deepEqual(verifyMailEditorBuild(root), {
        ok: true, entry: 'assets/app.js', contract: 'v26', renderingVerified: false,
    });
}));

test('mail release gate rejects V26 geometry without the lossless Outlook CSS transport', () => fixture((root) => {
    writeFileSync(path.join(root, 'assets/app.js'), geometryMarkers);
    assert.throws(() => verifyMailEditorBuild(root), /Stale mail editor build.*rt-mail-outlook/);
}));

test('mail release gate requires both CTA fallbacks and the current mail media policy', () => fixture((root) => {
    writeFileSync(path.join(root, 'assets/app.js'), transportMarkers);
    assert.throws(() => verifyMailEditorBuild(root), /Stale mail editor build.*rt-mail-cta/);
    writeFileSync(path.join(root, 'assets/app.js'), `${transportMarkers} rt-mail-cta`);
    assert.throws(() => verifyMailEditorBuild(root), /Stale mail editor build.*Mail-Medienbibliothek/);
}));
