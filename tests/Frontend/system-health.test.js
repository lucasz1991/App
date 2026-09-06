import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { systemHealth } from '../../resources/js/system-health.js';

function row(id = 'database', overrides = {}) {
    return {
        id, label: id, group: 'Anwendung', settings_tab: 'system', settings_section: 'system',
        status: 'not_checked', evidence: 'connection', message: 'Noch nicht geprüft', details: [],
        checked_at: null, duration_ms: null, fresh: false, source: 'cache', run_id: null, pending: false,
        ...overrides,
    };
}

function harness(rows = [row()], wire = {}) {
    const calls = [];
    const controller = systemHealth({ initialRows: rows });
    controller.$wire = {
        refreshSnapshot: async () => { calls.push(['snapshot']); return structuredClone(rows); },
        checkOne: async (id, force) => {
            calls.push(['check', id, force]);
            return row(id, { status: 'ok', fresh: true, source: 'live', checked_at: new Date().toISOString() });
        },
        pollCheck: async (id, runId) => {
            calls.push(['poll', id, runId]);
            return row(id, { status: 'ok', fresh: true, checked_at: new Date().toISOString() });
        },
        ...wire,
    };
    return { controller, calls };
}

const drain = () => new Promise(resolve => setImmediate(resolve));

test('hidden mount and repeated inactive effects do not start any check', async () => {
    const { controller, calls } = harness();
    controller.setOverviewActive(false);
    await controller.run(true);
    assert.equal(calls.length, 0);
    assert.equal(controller.timer, null);
});

test('actual activation refreshes snapshot and checks only expired results sequentially', async () => {
    const { controller, calls } = harness([row('fresh', { fresh: true, status: 'ok' }), row('expired'), row('new')]);
    controller.setOverviewActive(true);
    await drain();
    assert.deepEqual(calls, [['snapshot'], ['check', 'expired', false], ['check', 'new', false]]);
    assert.equal(controller.busy, false);
    assert.equal(controller.progress, 100);
    assert.equal(controller.timer, null, 'no expiry timer or permanent polling');
    controller.setOverviewActive(true);
    await drain();
    assert.equal(calls.length, 3, 'remaining on overview does not rerun checks');
});

test('each new tab activation reads current cache; cached results do not repeat probes', async () => {
    const { controller, calls } = harness([row('cache', { fresh: true, status: 'ok' })]);
    controller.setOverviewActive(true);
    await drain();
    controller.setOverviewActive(false);
    controller.setOverviewActive(true);
    await drain();
    assert.deepEqual(calls, [['snapshot'], ['snapshot']]);
});

test('force all and single actions bypass cache only for intended ids', async () => {
    const { controller, calls } = harness([row('one', { fresh: true }), row('two', { fresh: true })]);
    controller.overviewActive = true;
    await controller.run(true, 'two');
    assert.deepEqual(calls, [['snapshot'], ['check', 'two', true]]);
    calls.length = 0;
    await controller.run(true);
    assert.deepEqual(calls, [['snapshot'], ['check', 'one', true], ['check', 'two', true]]);
});

test('closing the tab discards a late response and does not start following checks', async () => {
    let finish;
    const { controller, calls } = harness([row('one'), row('two')], {
        checkOne: () => new Promise(resolve => { finish = resolve; }),
    });
    controller.setOverviewActive(true);
    await drain();
    controller.setOverviewActive(false);
    finish(row('one', { status: 'ok', fresh: true }));
    await drain();
    assert.equal(controller.rows[0].status, 'not_checked');
    assert.equal(calls.length, 1);
    assert.equal(controller.busy, false);
});

test('rapid reactivation serializes the new snapshot behind the old active request', async () => {
    let finish;
    let entered = false;
    const { controller, calls } = harness([row()], {
        checkOne: async () => {
            if (!entered) {
                entered = true;
                return new Promise(resolve => { finish = resolve; });
            }
            return row('database', { status: 'ok', fresh: true });
        },
    });
    controller.setOverviewActive(true);
    await drain();
    controller.setOverviewActive(false);
    controller.setOverviewActive(true);
    await drain();
    assert.equal(calls.length, 1, 'second snapshot waits until the prior request ends');
    finish(row('database', { status: 'error', fresh: true }));
    await drain();
    assert.equal(calls.length, 2);
    assert.equal(controller.rows[0].status, 'ok', 'stale response did not overwrite new activation');
});

test('one failing request retains safe results and continues with remaining checks', async () => {
    const { controller } = harness([row('bad'), row('good')], {
        checkOne: async id => {
            if (id === 'bad') throw new Error('sensitive network detail must never appear');
            return row(id, { status: 'ok', fresh: true });
        },
    });
    controller.overviewActive = true;
    await controller.run(false);
    assert.equal(controller.rows[1].status, 'ok');
    assert.match(controller.error, /übrigen Ergebnisse bleiben erhalten/);
    assert.doesNotMatch(controller.error, /sensitive/);
    assert.equal(controller.completed, 2);
});

test('pending jobs use poll only and stop observing after 120 seconds without inventing failure', async () => {
    const pending = row('worker', { status: 'running', fresh: true, pending: true, run_id: 'test-run', checked_at: new Date().toISOString() });
    const { controller, calls } = harness([pending]);
    controller.overviewActive = true;
    controller.rows = [pending];
    await controller.pollPending(controller.epoch);
    assert.deepEqual(calls, [['poll', 'worker', 'test-run']]);
    assert.equal(controller.rows[0].status, 'ok');
    assert.equal(controller.timer, null);

    controller.rows = [pending];
    controller.deadlines['worker:test-run'] = Date.now() - 1;
    controller.schedulePoll(controller.epoch);
    assert.equal(controller.timer, null);
    assert.equal(controller.observationEnded, true);
    assert.equal(controller.summary.label, 'Ausführung noch offen');
    assert.equal(controller.rows[0].status, 'running', 'no false worker failure');
});

test('browser hide and destroy cancel observation, visibility does not rerun the overall test', async t => {
    const listeners = new Map();
    const documentStub = {
        visibilityState: 'visible',
        addEventListener: (name, handler) => listeners.set(name, handler),
        removeEventListener: name => listeners.delete(name),
    };
    const originalDocument = globalThis.document;
    globalThis.document = documentStub;
    t.after(() => {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    });
    const { controller, calls } = harness();
    controller.init();
    controller.overviewActive = true;
    documentStub.visibilityState = 'hidden';
    listeners.get('visibilitychange')();
    assert.equal(controller.visible, false);
    documentStub.visibilityState = 'visible';
    listeners.get('visibilitychange')();
    await drain();
    assert.deepEqual(calls, []);
    controller.destroy();
    assert.equal(listeners.size, 0);
    assert.equal(controller.timer, null);
});

test('stale, disabled and configuration-only outcomes do not claim full operational readiness', () => {
    const { controller } = harness([row('old', { status: 'ok', fresh: false })]);
    assert.equal(controller.summary.status, 'not_checked');
    controller.rows = [row('disabled', { status: 'disabled', fresh: true }), row('missing', { status: 'not_configured', fresh: true })];
    assert.equal(controller.summary.status, 'warning');
    assert.equal(controller.evidenceLabel('configuration'), 'Konfiguration');
    assert.equal(controller.evidenceLabel('runtime'), 'Verarbeitung');
    assert.equal(controller.statusLabel('made-up'), 'Nicht geprüft');
});

test('markup binds actual overview state, renders escaped text and provides accessible reduced motion controls', () => {
    const blade = readFileSync(new URL('../../resources/views/livewire/admin/system-health.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/system-health.css', import.meta.url), 'utf8');
    assert.match(blade, /setOverviewActive\(typeof openTab !== 'undefined' && openTab === 'overview'\)/);
    assert.doesNotMatch(blade, /wire:poll|wire:init|x-intersect|x-html|\bfa-/);
    assert.match(blade, /role="progressbar"/);
    assert.match(blade, /role="alert"/);
    assert.match(blade, /<details/);
    assert.match(blade, /x-text="row.message"/);
    assert.match(css, /prefers-reduced-motion: reduce/);
    assert.match(css, /grid-template-columns: minmax\(0,1fr\)/);
    assert.match(css, /focus-visible/);
});
