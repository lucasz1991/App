import test from 'node:test';
import assert from 'node:assert/strict';

import {
    sidebarScrollBehavior,
    sidebarScrollTarget,
} from '../../resources/js/sidebar-scroll.js';

const baseGeometry = {
    scrollTop: 100,
    clientHeight: 400,
    scrollHeight: 1200,
    containerTop: 70,
    containerBottom: 470,
    padding: 12,
};

test('a fully visible sidebar block does not move the scroll container', () => {
    assert.equal(sidebarScrollTarget({
        ...baseGeometry,
        blockTop: 100,
        blockBottom: 430,
    }), null);
});

test('a submenu below the viewport is brought fully into the local container', () => {
    assert.equal(sidebarScrollTarget({
        ...baseGeometry,
        blockTop: 430,
        blockBottom: 570,
    }), 212);
});

test('a submenu above the viewport scrolls upward and clamps at zero', () => {
    assert.equal(sidebarScrollTarget({
        ...baseGeometry,
        scrollTop: 30,
        blockTop: 20,
        blockBottom: 180,
    }), 0);
});

test('an oversized submenu aligns its beginning instead of jumping the window', () => {
    assert.equal(sidebarScrollTarget({
        ...baseGeometry,
        blockTop: 210,
        blockBottom: 760,
    }), 228);
});

test('reduced motion uses an immediate container scroll', () => {
    assert.equal(sidebarScrollBehavior(true), 'auto');
    assert.equal(sidebarScrollBehavior(false), 'smooth');
});
