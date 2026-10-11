import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { timelineProposalPingScale, timelinePlanning } from '../../resources/js/timeline-planning-actions.js';

test('proposal ripple travels five pixels on every side regardless of badge aspect ratio', () => {
    for (const [width, height] of [[110, 18], [140, 18], [500, 18], [18, 18], [72.5, 22.5]]) {
        const scale = timelineProposalPingScale(width, height);
        assert.ok(Math.abs(width * (scale.x - 1) / 2 - 5) < 1e-10);
        assert.ok(Math.abs(height * (scale.y - 1) / 2 - 5) < 1e-10);
    }
});

test('invalid or not yet measurable proposal geometry does not create broken transforms', () => {
    for (const dimensions of [[0, 18], [110, 0], [NaN, 18], [110, Infinity], [-1, 18], [110, 18, -1]]) {
        assert.equal(timelineProposalPingScale(...dimensions), null);
    }
    assert.deepEqual(timelineProposalPingScale(140, 18, 0), { x: 1, y: 1 });
});

test('proposal ripple keeps static content, reduced motion, offscreen and background-tab pauses', () => {
    const css = readFileSync(new URL('../../resources/css/timeline-planning-actions.css', import.meta.url), 'utf8');
    assert.match(css, /\.rt-timeline-proposal-ping\.animate-ping\s*\{ animation-name: rt-timeline-border-ping;/);
    assert.match(css, /transform: scale\(var\(--proposal-ping-x, 1\), var\(--proposal-ping-y, 1\)\)/);
    assert.match(css, /prefers-reduced-motion: reduce[^}]*\.rt-timeline-proposal-ping\.animate-ping\s*\{ animation: none;/);
    assert.match(css, /proposal:not\(\[data-in-view='true'\]\) \+ \.rt-timeline-proposal-ping \{ animation: none;/);
    assert.match(css, /data-motion-paused='true'.*animation-play-state: paused/);
    assert.match(css, /proposal-ping \{[^}]*background: transparent; pointer-events: none;/);
});

test('proposal resize observer is released with its timeline owner', () => {
    const planner = timelinePlanning();
    let disconnects = 0;
    planner.proposalResize = { disconnect: () => disconnects++ };
    const originalDocument = globalThis.document;
    globalThis.document = { removeEventListener() {} };
    try { planner.destroy(); } finally { globalThis.document = originalDocument; }
    assert.equal(disconnects, 1);
    assert.equal(planner.disposed, true);
});
