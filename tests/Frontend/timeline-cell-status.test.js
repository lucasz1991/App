import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { timelineFocusPreviewPosition, timelinePlanning } from '../../resources/js/timeline-planning-actions.js';

const settled = () => new Promise(resolve => setImmediate(resolve));
const suitable = { state: 'suitable', label: '2 passend', detail: 'Vorläufig geprüft · zum Auswählen klicken' };

async function fixture(run) {
    const { document } = parseHTML('<div id="timeline"></div>');
    const root = document.getElementById('timeline');
    const events = [], timers = new Map();
    let now = 100000, nextTimer = 1;
    const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    const originalComputedStyle = Object.getOwnPropertyDescriptor(globalThis, 'getComputedStyle');
    const originalSetTimeout = globalThis.setTimeout, originalClearTimeout = globalThis.clearTimeout, originalNow = Date.now;
    globalThis.document = document;
    globalThis.getComputedStyle = () => ({ getPropertyValue: () => '148px' });
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
    const focusCell = (user = 7, shift = 81, revision = 12, date = '2027-05-13') => {
        root.classList.add('rt-personnel-timeline');
        const body = document.createElement('div');
        body.className = 'rt-personnel-timeline-body';
        Object.defineProperties(body, { clientLeft: { value: 1 }, clientWidth: { value: 650 } });
        const track = document.createElement('div');
        track.className = 'rt-personnel-timeline-track';
        root.append(body);
        body.append(track);
        const day = document.createElement('div');
        day.className = 'rt-personnel-timeline-day';
        day.dataset.focusFit = 'eligible';
        const button = document.createElement('button');
        button.className = 'rt-timeline-cell-action rt-timeline-focus-target';
        Object.assign(button.dataset, { timelineProposal: '', focus: 'true', user: String(user), shift: String(shift), revision: String(revision), date });
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-label', 'Mitarbeiter fuer ausgewaehlte Schicht einteilen');
        button.title = 'Keine Planungskonflikte';
        day.append(button);
        track.append(day);
        const preview = document.createElement('div');
        preview.className = 'rt-timeline-focus';
        Object.assign(preview.dataset, { timeStart: '90', timeWidth: '8' });
        preview.setAttribute('aria-hidden', 'true');
        preview.innerHTML = '<span class="rt-personnel-timeline-time rt-timeline-focus-preview"><span class="rt-personnel-timeline-time-text">22:00 - 06:00</span></span><span class="rt-personnel-timeline-duration-clip"><span class="rt-timeline-focus-duration"></span></span>';
        track.append(preview);
        const badge = preview.querySelector('.rt-timeline-focus-preview');
        preview.style.setProperty('--time-label-offset', '94%');
        preview.style.setProperty('--time-badge-width', '110px');
        preview.style.getPropertyPriority = () => '';
        const geometry = { bodyLeft: 0, overlayLeft: -1452, overlayWidth: 2100, cellLeft: 149, cellRight: 449, textWidth: 84 };
        body.getBoundingClientRect = () => ({ left: geometry.bodyLeft });
        preview.getBoundingClientRect = () => ({ left: geometry.overlayLeft, width: geometry.overlayWidth });
        button.getBoundingClientRect = () => ({ left: geometry.cellLeft, right: geometry.cellRight });
        badge.querySelector('.rt-personnel-timeline-time-text').getBoundingClientRect = () => ({ width: geometry.textWidth });
        return { day, button, preview, badge, body, geometry };
    };
    try { await run({ planner, events, root, document, cell, focusCell, advance, timers, icon, status, hover }); }
    finally {
        planner.destroy();
        globalThis.setTimeout = originalSetTimeout;
        globalThis.clearTimeout = originalClearTimeout;
        Date.now = originalNow;
        if (originalDocument) Object.defineProperty(globalThis, 'document', originalDocument);
        else delete globalThis.document;
        if (originalComputedStyle) Object.defineProperty(globalThis, 'getComputedStyle', originalComputedStyle);
        else delete globalThis.getComputedStyle;
    }
}

const previewGeometry = { overlayLeft: 0, overlayWidth: 2100, cellLeft: 600, cellRight: 900, viewportLeft: 148, viewportRight: 948, startPercent: 30, widthPercent: 2, textWidth: 84 };

test('focus preview normal short shift keeps a readable centered label inside its hovered day', () => {
    assert.deepEqual(timelineFocusPreviewPosition(previewGeometry), { offset: 655, badgeWidth: 110 });
});

test('focus preview overnight center outside a visible day clamps its label without moving true duration geometry', () => {
    const geometry = { ...previewGeometry, startPercent: 41, widthPercent: 5 };
    assert.deepEqual(timelineFocusPreviewPosition(geometry), { offset: 845, badgeWidth: 110 });
    assert.equal(geometry.startPercent, 41);
    assert.equal(geometry.widthPercent, 5);
});

test('focus preview multi-day duration never creates a badge wider than the hovered visible day', () => {
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, startPercent: 20, widthPercent: 60 }), { offset: 750, badgeWidth: 300 });
});

test('focus preview narrow left and right day fragments use available viewport without clipping readable times', () => {
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, cellLeft: 100, cellRight: 180, startPercent: 0, widthPercent: 2 }), { offset: 203, badgeWidth: 110 });
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, cellLeft: 900, cellRight: 1000, startPercent: 90, widthPercent: 2 }), { offset: 893, badgeWidth: 110 });
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, viewportRight: 238, cellLeft: 100, cellRight: 180, startPercent: 0, widthPercent: 2 }), { offset: 193, badgeWidth: 90 });
});

test('focus preview respects actual text width, shifted loaded track and a fragment wide enough for its badge', () => {
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, textWidth: 120 }), { offset: 668, badgeWidth: 136 });
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, cellLeft: 800, cellRight: 1000, startPercent: 41, widthPercent: 5 }), { offset: 893, badgeWidth: 110 });
    assert.deepEqual(timelineFocusPreviewPosition({ ...previewGeometry, overlayLeft: -1452, cellLeft: 149, cellRight: 449, viewportRight: 651, startPercent: 90, widthPercent: 8 }), { offset: 1817, badgeWidth: 168 });
});

test('focus preview rejects missing, malformed, zero and offscreen geometry', () => {
    for (const change of [
        { overlayWidth: 0 }, { cellRight: 600 }, { viewportRight: 148 }, { overlayLeft: NaN },
        { widthPercent: 0 }, { startPercent: -1 }, { startPercent: 90, widthPercent: 20 },
        { textWidth: -1 }, { cellLeft: 1000, cellRight: 1100 }, { overlayLeft: 1000 },
    ]) assert.equal(timelineFocusPreviewPosition({ ...previewGeometry, ...change }), null);
});

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

test('filter invalidation discards an unresolved hover result and the same employee/day must request fresh data', async () => fixture(async ({ planner, cell, hover, advance, status }) => {
    const anchor = cell();
    const calls = [], resolves = [];
    planner.request = (method, args) => {
        calls.push({ method, args });
        return new Promise(resolve => resolves.push(resolve));
    };
    hover(anchor);
    advance(280);
    assert.equal(calls.length, 1);
    assert.equal(anchor.dataset.fit, 'checking');
    planner.invalidate();
    assert.equal(planner.hoverCache.size, 0);
    assert.equal(anchor.dataset.fit, undefined);
    resolves.shift()({ state: 'blocked', label: 'Alter Filter', detail: 'Veraltetes Ergebnis' });
    await settled();
    assert.equal(planner.hoverCache.size, 0, 'a late pre-filter result must not refill the cleared cache');
    assert.equal(status(anchor).textContent, '');
    hover(anchor);
    advance(280);
    assert.deepEqual(calls.map(call => call.method), ['previewCell', 'previewCell']);
    assert.equal(anchor.dataset.fit, 'checking', 'same-key fresh hover is not answered from the old cache');
    resolves.shift()({ ...suitable, detail: 'Aktueller Filter' });
    await settled();
    assert.equal(planner.hoverCache.size, 1);
    assert.equal(planner.hoverCache.get('1:2027-05-13').value.detail, 'Aktueller Filter');
    assert.equal(status(anchor).textContent, '2 passend · Aktueller Filter');
    assert.equal(planner.plannerVisible, false);
    assert.equal(planner.$wire.assignmentOpen, false);
}));

test('an invalidated hover failure cannot overwrite a newer same-anchor check and queued fresh work stays serialized', async () => fixture(async ({ planner, cell, hover, advance, status }) => {
    const anchor = cell();
    const calls = [], pending = [];
    planner.request = (method, args) => {
        calls.push({ method, args });
        return new Promise((resolve, reject) => pending.push({ resolve, reject }));
    };
    hover(anchor);
    advance(280);
    planner.invalidate();
    hover(anchor);
    advance(280);
    assert.equal(calls.length, 1, 'the new generation still waits for the actual existing transport');
    pending.shift().reject(new Error('Obsolete failure'));
    await settled();
    assert.equal(calls.length, 2);
    assert.equal(anchor.dataset.fit, 'checking');
    assert.equal(status(anchor).textContent, 'Eignung prüfen …', 'the old error must not replace the new check');
    assert.equal(planner.hoverCache.size, 0);
    pending.shift().resolve(suitable);
    await settled();
    assert.equal(anchor.dataset.fit, 'suitable');
    assert.equal(planner.hoverCache.size, 1);
}));

test('destroyed planners never cache or paint a late hover response', async () => fixture(async ({ planner, cell, hover, advance, status }) => {
    const anchor = cell();
    let resolve;
    planner.request = () => new Promise(done => { resolve = done; });
    hover(anchor);
    advance(280);
    planner.destroy();
    resolve(suitable);
    await settled();
    assert.equal(planner.hoverCache.size, 0);
    assert.equal(anchor.dataset.fit, undefined);
    assert.equal(status(anchor).textContent, '');
    assert.equal(planner.inFlight, false);
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

test('eligible full-day focus target hover and keyboard focus never request previewCell or replace its selected-shift reason', async () => fixture(async ({ planner, focusCell, hover, advance, timers, events }) => {
    const { day, button, preview } = focusCell();
    const calls = [];
    planner.request = async (method, args) => { calls.push({ method, args }); return suitable; };
    assert.equal(day.dataset.focusFit, 'eligible');
    assert.equal(button.classList.contains('rt-timeline-cell-action'), true);
    assert.equal(button.hasAttribute('data-timeline-cell-action'), false, 'visual class must not accidentally opt into ordinary cell preview');
    assert.equal(button.hasAttribute('data-timeline-proposal'), true);
    hover(button);
    planner.hoverCell({ type: 'focusin', target: button });
    planner.hoverCell({ target: preview.querySelector('.rt-personnel-timeline-time-text'), pointerType: 'mouse' });
    advance(1000);
    await settled();
    assert.deepEqual(calls, []);
    assert.equal(timers.size, 0);
    assert.equal(planner.hoverAnchor, null);
    assert.equal(planner.pending, null);
    assert.equal(button.dataset.fit, undefined);
    assert.equal(button.title, 'Keine Planungskonflikte');
    assert.equal(preview.getAttribute('aria-hidden'), 'true');
    assert.equal(events.length, 0);
    assert.equal(planner.plannerVisible, false);
}));

test('clicking the full green focus cell uses the original selected shift, employee and revision with native review loading', async () => fixture(async ({ planner, focusCell, events, advance }) => {
    const { button } = focusCell(7, 81, 12);
    const calls = [];
    let resolve;
    planner.request = (method, args) => { calls.push({ method, args }); return new Promise(done => { resolve = done; }); };
    planner.openPlanner({ target: button });
    assert.deepEqual(calls, [{ method: 'openFocusCandidate', args: [81, 7, 12] }]);
    assert.equal(planner.plannerVisible, true);
    assert.equal(planner.plannerLoading, true);
    assert.equal(planner.plannerReady, false);
    assert.equal(events[0].name, 'rt-anchor-dropdown-open');
    assert.equal(events[0].detail.anchor, button);
    assert.equal(events[0].detail.id, 'rt-dropdown-timeline-planner-qa-cell');
    assert.equal(planner.$wire.assignmentOpen, false, 'opening review must not make an assignment');
    advance(1000);
    assert.equal(calls.length, 1, 'the green target does not add a hover availability request while loading');
    resolve();
    await settled();
    assert.equal(planner.plannerReady, true);
    assert.equal(planner.plannerLoading, false);
    assert.equal(events.filter(event => event.name === 'rt-anchor-dropdown-focus').length, 1);
}));

test('moving from ordinary hover into a focus cell cancels only the old preview timer and keeps click routing distinct', async () => fixture(async ({ planner, cell, focusCell, hover, advance }) => {
    const ordinary = cell(3);
    const { button } = focusCell(7, 81, 12);
    const calls = [];
    planner.request = async (method, args) => { calls.push({ method, args }); return suitable; };
    hover(ordinary);
    advance(100);
    planner.leaveCell({ relatedTarget: button });
    hover(button);
    planner.hoverCell({ type: 'focusin', target: button });
    advance(1000);
    await settled();
    assert.deepEqual(calls, [], 'the ordinary cell timer must not run after entering the already-ranked selected cell');
    assert.equal(planner.hoverAnchor, null);
    planner.openPlanner({ target: button });
    await settled();
    assert.deepEqual(calls, [{ method: 'openFocusCandidate', args: [81, 7, 12] }]);
}));

test('ordinary proposal remains openSuggestion and passive focus preview or native real-duty badges are not planner targets', async () => fixture(async ({ planner, root, document, focusCell, hover, advance, events }) => {
    const { day, preview } = focusCell();
    const duty = document.createElement('button');
    duty.className = 'rt-personnel-timeline-bar';
    duty.setAttribute('data-table-row-ignore', '');
    duty.innerHTML = '<span class="rt-personnel-timeline-time-text">08:00 - 10:00</span>';
    day.append(duty);
    const calls = [];
    planner.request = async (method, args) => { calls.push({ method, args }); };
    for (const target of [preview.querySelector('.rt-personnel-timeline-time-text'), duty.querySelector('span')]) {
        hover(target);
        planner.hoverCell({ type: 'focusin', target });
        planner.openPlanner({ target });
    }
    advance(1000);
    await settled();
    assert.deepEqual(calls, []);
    assert.equal(events.length, 0, 'native duty click remains owned by its original detail dropdown');
    assert.equal(planner.plannerVisible, false);
    const proposal = document.createElement('button');
    Object.assign(proposal.dataset, { timelineProposal: '', user: '7', shift: '82', revision: '3' });
    proposal.innerHTML = '<i class="far fa-lightbulb"></i>';
    root.append(proposal);
    hover(proposal.querySelector('i'));
    advance(1000);
    assert.deepEqual(calls, []);
    planner.openPlanner({ target: proposal.querySelector('i') });
    await settled();
    assert.deepEqual(calls, [{ method: 'openSuggestion', args: [82, 7, 3] }]);
    assert.equal(events[0].detail.anchor, proposal);
}));

test('focus hover shares clamped overnight badge and duration-clip geometry without changing real duration or measuring inactive rows', async () => fixture(({ planner, focusCell, hover, timers }) => {
    const first = focusCell(), other = focusCell(8, 82);
    let otherReads = 0;
    other.preview.getBoundingClientRect = () => { otherReads++; throw new Error('inactive row must not be measured'); };
    const duration = first.preview.querySelector('.rt-timeline-focus-duration');
    duration.style.cssText = 'left:90%;width:8%';
    const originalDuration = duration.getAttribute('style');
    planner.request = () => { throw new Error('focus preview must stay client-only'); };
    planner.refreshFocusPreview();
    assert.equal(otherReads, 0);
    hover(first.button);
    assert.equal(planner.focusPreviewAnchor, first.button);
    assert.equal(first.preview.style.getPropertyValue('--time-label-offset'), '1817px');
    assert.equal(first.preview.style.getPropertyValue('--time-badge-width'), '168px');
    assert.equal(planner.focusPreviewStyle.node, first.preview, 'paint the common parent of badge and clip');
    assert.equal(first.badge.parentElement, duration.parentElement.parentElement);
    assert.equal(first.badge.style.getPropertyValue('--time-label-offset'), undefined, 'badge must inherit the shared position');
    assert.equal(first.badge.style.getPropertyValue('--time-badge-width'), undefined, 'badge must inherit the shared width');
    assert.equal(duration.getAttribute('style'), originalDuration);
    assert.equal(timers.size, 0);
    first.geometry.overlayLeft -= 100;
    first.geometry.cellLeft -= 100;
    first.geometry.cellRight -= 100;
    planner.refreshFocusPreview();
    assert.equal(first.preview.style.getPropertyValue('--time-label-offset'), '1817px');
    assert.equal(first.preview.style.getPropertyValue('--time-badge-width'), '168px');
    assert.equal(otherReads, 0);
    assert.equal(duration.getAttribute('style'), originalDuration);
}));

test('focus keyboard preview restores shared geometry on leave, invalidate and destroy without touching badge styles', async () => fixture(({ planner, focusCell }) => {
    const { button, preview, badge } = focusCell();
    badge.style.setProperty('color', 'green');
    for (const clear of [() => planner.leaveCell({ relatedTarget: null }), () => planner.invalidate(), () => planner.destroy()]) {
        planner.hoverCell({ type: 'focusin', target: button });
        assert.equal(planner.focusPreviewAnchor, button);
        assert.equal(preview.style.getPropertyValue('--time-label-offset'), '1817px');
        clear();
        assert.equal(planner.focusPreviewAnchor, null);
        assert.equal(planner.focusPreviewStyle, null);
        assert.equal(preview.style.getPropertyValue('--time-label-offset'), '94%');
        assert.equal(preview.style.getPropertyValue('--time-badge-width'), '110px');
        assert.equal(badge.style.getPropertyValue('color'), 'green');
    }
    planner.hoverCell({ type: 'focusin', target: button });
    assert.equal(planner.focusPreviewAnchor, null, 'disposed controller cannot retain another anchor');
}));

test('focus preview clears invalid, disabled, loading and disconnected anchors without requests', async () => fixture(({ planner, root, focusCell, hover }) => {
    planner.request = () => { throw new Error('no preview request'); };
    const { button, preview, badge, geometry } = focusCell();
    hover(button);
    root.dataset.contentLoading = 'true';
    planner.refreshFocusPreview();
    assert.equal(planner.focusPreviewAnchor, null);
    assert.equal(preview.style.getPropertyValue('--time-label-offset'), '94%');
    hover(button);
    assert.equal(planner.focusPreviewAnchor, null);
    delete root.dataset.contentLoading;
    button.disabled = true;
    hover(button);
    assert.equal(planner.focusPreviewAnchor, null);
    button.disabled = false;
    geometry.overlayWidth = 0;
    hover(button);
    assert.equal(planner.focusPreviewAnchor, null);
    geometry.overlayWidth = 2100;
    preview.dataset.timeWidth = 'not-a-number';
    hover(button);
    assert.equal(planner.focusPreviewAnchor, null);
    preview.dataset.timeWidth = '8';
    hover(button);
    button.remove();
    planner.refreshFocusPreview();
    assert.equal(planner.focusPreviewAnchor, null);
    assert.equal(preview.style.getPropertyValue('--time-label-offset'), '94%');
}));

test('moving between focus cells restores earlier shared geometry and leaving inside the same anchor retains positioning', async () => fixture(({ planner, focusCell, hover }) => {
    const first = focusCell(), second = focusCell(8, 82);
    hover(first.button);
    planner.leaveCell({ relatedTarget: first.button });
    assert.equal(planner.focusPreviewAnchor, first.button);
    hover(second.button);
    assert.equal(first.preview.style.getPropertyValue('--time-label-offset'), '94%');
    assert.equal(second.preview.style.getPropertyValue('--time-label-offset'), '1817px');
    assert.equal(planner.focusPreviewAnchor, second.button);
    planner.leaveCell({ relatedTarget: null });
    assert.equal(second.preview.style.getPropertyValue('--time-label-offset'), '94%');
}));

test('focus cleanup does not overwrite newer server shared-geometry styles and refresh adopts their baseline', async () => fixture(({ planner, focusCell, hover }) => {
    const { button, preview } = focusCell();
    hover(button);
    preview.style.setProperty('--time-label-offset', '70%');
    preview.style.setProperty('--time-badge-width', '120px');
    planner.clearHover();
    assert.equal(preview.style.getPropertyValue('--time-label-offset'), '70%');
    assert.equal(preview.style.getPropertyValue('--time-badge-width'), '120px');
    hover(button);
    preview.style.setProperty('--time-label-offset', '60%');
    preview.style.setProperty('--time-badge-width', '130px');
    planner.refreshFocusPreview();
    assert.equal(preview.style.getPropertyValue('--time-label-offset'), '1817px');
    planner.clearHover();
    assert.equal(preview.style.getPropertyValue('--time-label-offset'), '60%');
    assert.equal(preview.style.getPropertyValue('--time-badge-width'), '130px');
}));

test('pointer leaving a keyboard-focused cell and focus leaving a hovered cell retain the current preview', async () => fixture(({ planner, focusCell, hover }) => {
    const { button } = focusCell();
    let focused = true, hovered = false;
    const matches = button.matches.bind(button);
    button.matches = selector => selector === ':focus-visible' ? focused : selector === ':hover' ? hovered : matches(selector);
    hover(button);
    planner.leaveCell({ type: 'pointerout', relatedTarget: null });
    assert.equal(planner.focusPreviewAnchor, button);
    focused = false;
    hovered = true;
    planner.leaveCell({ type: 'focusout', relatedTarget: null });
    assert.equal(planner.focusPreviewAnchor, button);
    hovered = false;
    planner.leaveCell({ type: 'focusout', relatedTarget: null });
    assert.equal(planner.focusPreviewAnchor, null);
}));
