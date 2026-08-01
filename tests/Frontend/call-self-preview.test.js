import test from 'node:test';
import assert from 'node:assert/strict';

import {
    clampSelfPreviewPosition,
    isSelfPreviewCenterOutside,
    nearestSelfPreviewEdge,
    selfPreviewDockPosition,
} from '../../resources/js/call-self-preview.js';

const stage = { width: 640, height: 480 };
const preview = { width: 176, height: 99 };

test('self preview is clamped completely inside the stage with an eight pixel inset', () => {
    assert.deepEqual(
        clampSelfPreviewPosition({ x: -30, y: 900 }, stage, preview),
        { x: 8, y: 373 },
    );

    assert.deepEqual(
        clampSelfPreviewPosition({ x: 220, y: 180 }, stage, preview),
        { x: 220, y: 180 },
    );
});

test('partial overflow is allowed until the preview centre leaves the stage', () => {
    assert.equal(
        isSelfPreviewCenterOutside({ x: -87, y: 20 }, stage, preview),
        false,
    );
    assert.equal(
        isSelfPreviewCenterOutside({ x: -89, y: 20 }, stage, preview),
        true,
    );
    assert.equal(
        isSelfPreviewCenterOutside({ x: 553, y: 20 }, stage, preview),
        true,
    );
});

test('restore control docks at the closest exit edge and remains a 44px-safe inset', () => {
    const draggedRight = { x: 680, y: 175 };

    assert.equal(nearestSelfPreviewEdge(draggedRight, stage, preview), 'right');
    assert.deepEqual(
        selfPreviewDockPosition('right', draggedRight, stage, preview),
        { x: 588, y: 202.5 },
    );

    assert.deepEqual(
        selfPreviewDockPosition('top', { x: 12, y: -80 }, stage, preview),
        { x: 78, y: 8 },
    );
});

test('invalid geometry never creates NaN transforms', () => {
    assert.deepEqual(
        clampSelfPreviewPosition({ x: Number.NaN, y: undefined }, {}, {}),
        { x: 8, y: 8 },
    );
});
