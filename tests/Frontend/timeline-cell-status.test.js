import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { timelinePlanning } from '../../resources/js/timeline-planning-actions.js';

const settled = () => new Promise(resolve => setImmediate(resolve));
const suitable = { state: 'suitable', label: '2 passend', detail: 'Vorläufig geprüft · zum Auswählen klicken' };

async function fixture(run) {
    const { document } = parseHTML('<div id="timeline"></div>');
    const root = document.getElementById('timeline');
    const events = [], timers = new Map();
    let now = 100000, nextTimer = 1;
    const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    const originalSetTimeout = globalThis.setTimeout, originalClearTimeout = globalThis.clearTimeout, originalNow = Date.now;
    globalThis.document = document;
    globalThis.setTimeout = (callback, delay) => {
        const id = nextTimer++;
        timers.set(id, { callback, at: now + delay });
        return id;
    };
    globalThis.clearTimeout = id => timers.delete(id);
    Date.now = () => now;
    const planner = timelinePlanning();
    Object.assign(planner, { $wire: { $id: 'qa-cell', assignmentOpen: false }, $root: root,
        $dispatch: (name, detail) => events.push({ name, detail }), $nextTick: callback => callback() });
    const advance = ms => {
        const until = now + ms;
        while (true) {
            const next = [...timers].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            now = next[1].at;
            timers.delete(next[0]);
            next[1].callback();
        }
        now = until;
    };
    const cell = (user = 1, date = '2027-05-13') => {
        const button = document.createElement('button');
        button.className = 'rt-timeline-cell-action';
        button.dataset.timelineCellAction = '';
        button.dataset.user = String(user);
        button.dataset.date = date;
        button.setAttribute('aria-label', `Offene Schicht auswählen: QA ${user} · ${date}`);
        const id = `timeline-cell-status-qa-cell-${user}-${date}`;
        button.setAttribute('aria-describedby', id);
        button.innerHTML = `<span class="rt-timeline-cell-status" aria-hidden="true"><i class="far fa-circle" data-timeline-cell-status-icon></i></span><span id="${id}" class="sr-only" data-timeline-cell-status-text></span>`;
        root.append(button);
        return button;
    };
    const icon = anchor => anchor.querySelector('[data-timeline-cell-status-icon]');
    const status = anchor => anchor.querySelector('[data-timeline-cell-status-text]');
    const hover = anchor => planner.hoverCell({ target: anchor, pointerType: 'mouse' });
    try { await run({ planner, events, root, document, cell, advance, timers, icon, status, hover }); }
    finally {
        planner.destroy();
        globalThis.setTimeout = originalSetTimeout;
        globalThis.clearTimeout = originalClearTimeout;
        Date.now = originalNow;
        if (originalDocument) Object.defineProperty(globalThis, 'document', originalDocument);
        else delete globalThis.document;
    }
}

test('each hover result uses one semantic icon and an accessible description rather than visible text', async () => fixture(({ planner, cell, icon, status }) => {
    const anchor = cell();
    planner.hoverAnchor = anchor;
    const classes = { checking: 'fa-spinner-third', suitable: 'fa-check-circle', blocked: 'fa-ban', empty: 'fa-minus-circle', error: 'fa-exclamation-circle' };
    for (const [state, iconClass] of Object.entries(classes)) {
        planner.paintHover(anchor, { state, label: `Status ${state}`, detail: 'Weitere Einzelheiten' });
        assert.equal(anchor.dataset.fit, state);
        assert.equal(anchor.dataset.fitLabel, `Status ${state}`);
        assert.equal(anchor.title, `Status ${state} · Weitere Einzelheiten`);
        assert.equal(icon(anchor).className, `far ${iconClass}`);
        assert.equal(status(anchor).textContent, anchor.title);
        assert.equal(status(anchor).id, anchor.getAttribute('aria-describedby'));
        assert.equal(status(anchor).className, 'sr-only');
        assert.equal(icon(anchor).parentElement.getAttribute('aria-hidden'), 'true');
        assert.equal(anchor.querySelectorAll('i').length, 1);
    }
}));

test('description is safely set as text and absent details do not add dangling punctuation', async () => fixture(({ planner, cell, status, icon }) => {
    const anchor = cell();
    planner.hoverAnchor = anchor;
    planner.paintHover(anchor, { state: 'unknown', label: '<script>bad()</script>', detail: '' });
    assert.equal(anchor.title, '<script>bad()</script>');
    assert.equal(status(anchor).textContent, '<script>bad()</script>');
    assert.equal(status(anchor).querySelector('script'), null);
    assert.equal(icon(anchor).className, 'far fa-question-circle');
}));

test('real hover keeps 280ms debounce and shows checking only while a request is in flight', async () => fixture(async ({ planner, cell, hover, advance, icon, status }) => {
    const anchor = cell();
    let resolve;
    const calls = [];
    planner.request = (method, args) => { calls.push({ method, args }); return new Promise(done => { resolve = done; }); };
    hover(anchor);
    advance(279);
    assert.equal(anchor.dataset.fit, undefined);
    assert.equal(calls.length, 0);
    advance(1);
    assert.equal(anchor.dataset.fit, 'checking');
    assert.equal(icon(anchor).className, 'far fa-spinner-third');
    assert.equal(status(anchor).textContent, 'Eignung prüfen …');
    assert.deepEqual(calls, [{ method: 'previewCell', args: [1, '2027-05-13'] }]);
    resolve(suitable);
    await settled();
    assert.equal(anchor.dataset.fit, 'suitable');
    assert.equal(icon(anchor).className, 'far fa-check-circle');
    assert.equal(planner.plannerVisible, false);
    assert.equal(planner.$wire.assignmentOpen, false);
}));

test('moving inside the icon retains status and does not issue another preview', async () => fixture(async ({ planner, cell, hover, advance, icon, status }) => {
    const anchor = cell();
    let calls = 0;
    planner.request = async () => { calls++; return suitable; };
    hover(anchor);
    advance(280);
    await settled();
    planner.leaveCell({ relatedTarget: icon(anchor) });
    planner.hoverCell({ target: icon(anchor), pointerType: 'mouse' });
    advance(280);
    await settled();
    assert.equal(calls, 1);
    assert.equal(planner.hoverAnchor, anchor);
    assert.equal(status(anchor).textContent, `${suitable.label} · ${suitable.detail}`);
}));

test('leaving the cell clears only its temporary state and preserves its full clickable and accessible target', async () => fixture(({ planner, cell, hover, advance, status }) => {
    const anchor = cell();
    const label = anchor.getAttribute('aria-label'), description = anchor.getAttribute('aria-describedby');
    hover(anchor);
    planner.paintHover(anchor, suitable);
    planner.leaveCell({ relatedTarget: null });
    advance(280);
    assert.equal(planner.hoverAnchor, null);
    assert.equal(anchor.dataset.fit, undefined);
    assert.equal(anchor.dataset.fitLabel, undefined);
    assert.equal(anchor.hasAttribute('title'), false);
    assert.equal(status(anchor).textContent, '');
    assert.equal(anchor.getAttribute('aria-label'), label);
    assert.equal(anchor.getAttribute('aria-describedby'), description);
    assert.equal(anchor.hasAttribute('data-timeline-cell-action'), true);
    assert.equal(planner.pending, null);
}));

test('touch, a visible planner and detached cells never request hover previews', async () => fixture(({ planner, cell, hover, advance }) => {
    let calls = 0;
    planner.request = async () => { calls++; return suitable; };
    const anchor = cell();
    planner.hoverCell({ target: anchor, pointerType: 'touch' });
    advance(280);
    assert.equal(planner.hoverAnchor, null);
    planner.plannerVisible = true;
    hover(anchor);
    advance(280);
    assert.equal(planner.hoverAnchor, null);
    planner.plannerVisible = false;
    hover(anchor);
    anchor.remove();
    advance(280);
    assert.equal(calls, 0);
}));

test('cached results reuse the same icon and text for 15 seconds, then refresh', async () => fixture(async ({ planner, cell, hover, advance, status }) => {
    const anchor = cell();
    let calls = 0;
    planner.request = async () => { calls++; return suitable; };
    hover(anchor);
    advance(280);
    await settled();
    planner.clearHover();
    hover(anchor);
    advance(280);
    await settled();
    assert.equal(calls, 1);
    assert.equal(status(anchor).textContent, `${suitable.label} · ${suitable.detail}`);
    planner.clearHover();
    advance(15000);
    hover(anchor);
    advance(280);
    await settled();
    assert.equal(calls, 2);
}));

test('hover cache stays bounded at 40 employee/date results and evicts the oldest', async () => fixture(async ({ planner, cell, hover, advance }) => {
    planner.request = async () => suitable;
    for (let user = 1; user <= 41; user++) {
        hover(cell(user));
        advance(280);
        await settled();
    }
    assert.equal(planner.hoverCache.size, 40);
    assert.equal(planner.hoverCache.has('1:2027-05-13'), false);
    assert.equal(planner.hoverCache.has('41:2027-05-13'), true);
}));

test('late previews cannot paint a different cell and requests remain serialized', async () => fixture(async ({ planner, cell, hover, advance, status }) => {
    const first = cell(1), second = cell(2);
    const calls = [], resolves = [];
    planner.request = (method, args) => { calls.push(args[0]); return new Promise(resolve => resolves.push(resolve)); };
    hover(first);
    advance(280);
    hover(second);
    advance(280);
    assert.deepEqual(calls, [1]);
    assert.equal(first.dataset.fit, undefined);
    assert.equal(second.dataset.fit, 'checking');
    resolves.shift()(suitable);
    await settled();
    assert.deepEqual(calls, [1, 2]);
    assert.equal(status(first).textContent, '');
    assert.equal(second.dataset.fit, 'checking');
    resolves.shift()({ state: 'blocked', label: 'Keine passende Schicht', detail: 'Konfliktgründe per Klick' });
    await settled();
    assert.equal(second.dataset.fit, 'blocked');
    assert.equal(first.dataset.fit, undefined);
}));

test('destroy clears a pending preview and a late response cannot restore any cell status', async () => fixture(async ({ planner, cell, hover, advance, status }) => {
    const anchor = cell();
    let resolve;
    planner.request = () => new Promise(done => { resolve = done; });
    hover(anchor);
    advance(280);
    assert.equal(anchor.dataset.fit, 'checking');
    planner.destroy();
    assert.equal(status(anchor).textContent, '');
    resolve(suitable);
    await settled();
    assert.equal(anchor.dataset.fit, undefined);
    assert.equal(anchor.hasAttribute('title'), false);
    assert.equal(status(anchor).textContent, '');
    assert.equal(planner.inFlight, false);
}));

test('a failed hover uses the error icon, not the legitimate no-open-duties state, and remains retryable', async () => fixture(async ({ planner, cell, hover, advance, icon, status }) => {
    const anchor = cell();
    let calls = 0;
    planner.request = async () => { calls++; throw new Error('offline'); };
    hover(anchor);
    advance(280);
    await settled();
    assert.equal(anchor.dataset.fit, 'error');
    assert.equal(icon(anchor).className, 'far fa-exclamation-circle');
    assert.equal(status(anchor).textContent, 'Prüfung nicht verfügbar · Per Klick erneut versuchen.');
    assert.equal(planner.hoverCache.size, 0);
    assert.equal(planner.inFlight, false);
    planner.clearHover();
    planner.request = async () => { calls++; return { state: 'empty', label: 'Keine offenen Dienste', detail: 'Für diesen Tag ist nichts zu verteilen.' }; };
    hover(anchor);
    advance(280);
    await settled();
    assert.equal(calls, 2);
    assert.equal(anchor.dataset.fit, 'empty');
    assert.equal(icon(anchor).className, 'far fa-minus-circle');
}));

test('clicking an icon still opens the original cell anchor without an extra preview or assignment write', async () => fixture(async ({ planner, cell, hover, advance, icon, status, events }) => {
    const anchor = cell(7);
    const calls = [];
    planner.request = async (method, args) => { calls.push({ method, args }); };
    hover(anchor);
    planner.paintHover(anchor, suitable);
    planner.openPlanner({ target: icon(anchor) });
    advance(280);
    await settled();
    assert.deepEqual(calls, [{ method: 'openCell', args: [7, '2027-05-13'] }]);
    assert.equal(events[0].name, 'rt-anchor-dropdown-open');
    assert.equal(events[0].detail.anchor, anchor);
    assert.equal(planner.plannerReady, true);
    assert.equal(status(anchor).textContent, '');
    assert.equal(anchor.dataset.fit, undefined);
}));
