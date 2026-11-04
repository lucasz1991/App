import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';
import { universalTaskpaneStatus } from '../../resources/js/outlook-addin/u2e-taskpane-status.js';

const pane = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
const markup = await readFile(new URL('../../resources/views/outlook-addin/taskpane.blade.php', import.meta.url), 'utf8');
const failure = code => Object.assign(new Error('PRIVATE-MAIL-TEXT'), { code });
const report = (extra = {}) => ({ state: 'uncertain', code: 'SIGNATURE_LAYOUT_UNCONFIRMED', originalCode: 'SIGNATURE_LAYOUT_UNCONFIRMED',
    kind: 'combined', phase: 'readback', step: 'readback.material', checkpoint: 'evidence.borderColors',
    at: '2026-11-04T08:54:09.000Z', bodyWriteStarted: true, bodyWriteSucceeded: true, ...extra });

function load(name, context) {
    const start = new RegExp(`^(?:export )?(?:async )?function ${name}\\(`, 'm').exec(pane);
    assert.ok(start, `${name} exists`);
    const tail = pane.slice(start.index).replace(/^export /, '');
    const end = /\r?\n(?:(?:export )?(?:async )?function [A-Za-z_$]|(?:let|const|class) [A-Za-z_$])/.exec(tail);
    return vm.runInContext(`(${end ? tail.slice(0, end.index) : tail}\n)`, context);
}

function fixture() {
    const { document } = parseHTML(markup);
    const item = {};
    const calls = { auth: 0, telemetry: 0, bodyReads: 0, diagnostics: 0, invalidation: 0, writes: 0 };
    const state = { settingsMode: false, busy: false, authenticated: true, bootstrapReady: true, configReady: true,
        signatureCurrent: false, itemChangedMonitoringReady: true, templates: [{}], selectedTemplateId: 'one' };
    const payload = { universalSignature: { kind: 'signature' }, binding: {} };
    const reports = new WeakMap();
    const blocked = new WeakSet();
    const context = vm.createContext({ Office: { context: { mailbox: { item } } }, document,
        taskpaneState: state, currentBootstrap: payload, currentConfig: {}, lastActionError: null,
        signatureStateRevision: 0, mailboxItemRevision: 0, bootstrapStateRevision: 0, observedComposeItem: null,
        universalStatusByItem: new WeakMap(), universalTaskpaneStatus,
        universalComposeReport: target => reports.get(target) || null,
        universalComposeBlocked: target => blocked.has(target),
        isTemplateInsertionBlocked: () => false, isStandaloneSignatureInsertionBlocked: () => false,
        hasUncertainWrite: () => false, u2eTransportProbeReport: () => null,
        headProbeAvailable: () => false, u2eProbeAvailable: () => false,
        selectedTemplate: () => ({}), supportsTemplatePrepend: () => true,
        syncAutomaticPreferences() {}, syncHeadProbeButton() {}, renderSelectedTemplate() {},
        safeErrorCode: error => error?.code || 'UNAVAILABLE', codedError: failure,
        authenticationWasLost: error => error?.code === 'HTTP_401', userMessage: error => `Aktueller Fehler: ${error.code}`,
        invalidateBootstrap: () => { calls.invalidation++; },
        recordDiagnostic: () => { calls.diagnostics++; },
        diagnoseStep: async (_step, action) => action(), displayAccount() {},
        acquireAccessToken: async () => { calls.auth++; return 'synthetic'; }, loadBootstrap: async () => payload,
        captureComposeTarget: () => ({ item: context.Office.context.mailbox.item, revision: context.mailboxItemRevision }),
        assertComposeTarget: target => { if (target.item !== context.Office.context.mailbox.item) throw failure('COMPOSE_ITEM_CHANGED'); return target.item; },
        assertWriteTarget: async target => context.assertComposeTarget(target), acceptBootstrap: async () => {},
        reportUniversalFailure: async () => { calls.telemetry++; },
        readUniversalSessionReport: async () => null,
        readBodyHtml: async () => { calls.bodyReads++; return 'synthetic-body'; }, readComposeType: async () => 'newMail',
        universalComposeStateFromBody: () => ({ current: true, present: true, kind: 'combined' }),
    });
    for (const name of ['elements', 'setStatus', 'insertionBlocked', 'showUniversalStatus', 'updateDisabledStates',
        'syncActionState', 'handleFailure', 'withAuthenticatedBootstrap', 'refreshSignatureCurrentState', 'handleMailboxItemChanged']) {
        context[name] = load(name, context);
    }
    context.setBusy = value => { state.busy = value; context.updateDisabledStates(); };
    const button = document.querySelector('[data-outlook-action="template"]');
    context.syncActionState();
    return { context, document, item, state, calls, reports, blocked, button };
}

test('only a succeeded body write is described as inserted, with finite reason and original time', () => {
    const known = universalTaskpaneStatus(report());
    assert.equal(known.tone, 'warning'); assert.equal(known.title, 'Eingefügt, Darstellung nicht bestätigt');
    assert.match(known.detail, /Rahmenfarben der Signatur/); assert.match(known.detail, /09:54:09/);
    assert.match(known.detail, /Das Add-in sperrt den Versand nicht/); assert.equal(known.blocked, true);
    assert.doesNotMatch(known.detail, /versandbereit|korrekt dargestellt|Diagnosebericht/i);
    for (const extra of [{ bodyWriteStarted: true, bodyWriteSucceeded: false },
        { bodyWriteStarted: null, bodyWriteSucceeded: null, at: null, step: 'session.read', code: 'U2E_INSERT_UNCERTAIN' }]) {
        const unknown = universalTaskpaneStatus(report(extra));
        assert.equal(unknown.tone, 'warning'); assert.equal(unknown.title, 'Einfügung nicht bestätigt');
        assert.doesNotMatch(unknown.detail, /hat das Einfügen bestätigt/);
        if (extra.at === null) assert.doesNotMatch(unknown.detail, /Zeitpunkt/);
    }
});

test('pre-body failure stays red and names the boundary check instead of guessing an old version', () => {
    const before = universalTaskpaneStatus(report({ code: 'U2E_NEW_DRAFT_REQUIRED', state: 'rejected',
        bodyWriteStarted: false, bodyWriteSucceeded: false, checkpoint: null, step: 'snapshot.region', phase: 'preflight' }));
    assert.equal(before.tone, 'error'); assert.equal(before.title, 'Aktion nicht abgeschlossen');
    assert.match(before.detail, /vor dem Einfügen/); assert.match(before.detail, /Zitatgrenze/);
    assert.doesNotMatch(before.detail, /ältere|Eingefügt/); assert.equal(before.newMessageRequired, true);
    const headerOnly = universalTaskpaneStatus(report({ code: 'U2E_INSERT_UNCERTAIN',
        bodyWriteStarted: false, bodyWriteSucceeded: false, phase: 'header', step: 'header.write', checkpoint: null }));
    assert.equal(headerOnly.tone, 'error'); assert.equal(headerOnly.blocked, true);
});

test('untrusted labels, times and stale reports never become displayed mail text or insertion evidence', () => {
    const red = universalTaskpaneStatus(report({ checkpoint: 'PRIVATE-MAIL-TEXT', step: 'PRIVATE-TOKEN', at: 'PRIVATE-MAIL-TEXT' }));
    assert.match(red.detail, /Prüfschritt nicht verfügbar/); assert.doesNotMatch(red.detail, /PRIVATE/);
    const stale = universalTaskpaneStatus(report(), 'U2E_SOURCE_INVALID');
    assert.equal(stale.tone, 'error'); assert.doesNotMatch(stale.detail, /Rahmenfarben|Zeitpunkt|hat das Einfügen bestätigt/);
    assert.equal(universalTaskpaneStatus(report({ state: 'complete' })), null);
    assert.equal(universalTaskpaneStatus(null, 'HTTP_401'), null);
});

test('real manual failure renders the same yellow reason and disables both writers without retry telemetry', async () => {
    const f = fixture(); f.reports.set(f.item, report());
    await f.context.withAuthenticatedBootstrap(f.button, async (_bootstrap, _target, universal) => universal({}, async () => {
        f.calls.writes++; throw failure('SIGNATURE_LAYOUT_UNCONFIRMED');
    }));
    assert.equal(f.document.querySelector('[data-outlook-status]').dataset.tone, 'warning');
    assert.match(f.document.querySelector('[data-outlook-pane-feedback]').textContent, /Rahmenfarben der Signatur/);
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Eingefügt, Darstellung nicht bestätigt');
    assert.ok(f.document.querySelector('[data-outlook-pane-feedback]').textContent.includes(
        f.document.querySelector('[data-outlook-status-detail]').textContent));
    assert.equal(f.button.disabled, true);
    assert.equal(f.document.querySelector('[data-outlook-action="signature"]').disabled, true);
    assert.equal(f.document.querySelector('[data-outlook-new-message-help]').hidden, false);
    await f.context.withAuthenticatedBootstrap(f.button, () => { throw new Error('A retry must not run'); });
    assert.deepEqual([f.calls.auth, f.calls.writes, f.calls.telemetry], [1, 1, 1]);
});

test('old pending recovery is unknown, has no invented time, and still blocks a second click', async () => {
    const f = fixture(); f.reports.set(f.item, report({ code: 'U2E_INSERT_UNCERTAIN', step: 'session.read',
        checkpoint: null, at: null, bodyWriteStarted: null, bodyWriteSucceeded: null }));
    await f.context.withAuthenticatedBootstrap(f.button, async (_bootstrap, _target, universal) => universal({}, async () => {
        throw failure('U2E_INSERT_UNCERTAIN');
    }));
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Einfügung nicht bestätigt');
    assert.doesNotMatch(f.document.querySelector('[data-outlook-status-detail]').textContent, /Zeitpunkt|hat das Einfügen bestätigt/);
    assert.equal(f.button.disabled, true);
    await f.context.withAuthenticatedBootstrap(f.button, () => assert.fail('pending draft retried'));
    assert.equal(f.calls.auth, 1); assert.equal(f.calls.telemetry, 1);
});

test('refresh recovers known metadata even when the body is unreadable and never unlocks it on a null read', async () => {
    const f = fixture();
    f.context.readUniversalSessionReport = async () => report();
    f.context.readBodyHtml = async () => { throw failure('COMPOSE_BODY_UNREADABLE'); };
    await f.context.refreshSignatureCurrentState();
    assert.equal(f.button.disabled, true);
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Eingefügt, Darstellung nicht bestätigt');
    f.context.readUniversalSessionReport = async () => null;
    await f.context.refreshSignatureCurrentState();
    assert.equal(f.button.disabled, true);
    assert.match(f.document.querySelector('[data-outlook-status-detail]').textContent, /09:54:09/);
});

test('an item switch during session recovery stops before reading the old body and resets new-item buttons', async () => {
    const f = fixture(); let release;
    f.context.readUniversalSessionReport = () => new Promise(resolve => { release = resolve; });
    const pending = f.context.refreshSignatureCurrentState();
    f.context.Office.context.mailbox.item = {};
    f.context.handleMailboxItemChanged();
    release(report()); await pending;
    f.state.bootstrapReady = true; f.context.syncActionState();
    assert.equal(f.calls.bodyReads, 0); assert.equal(f.button.disabled, false);
    assert.equal(f.document.querySelector('[data-outlook-action="signature"]').disabled, false);
    assert.equal(f.document.querySelector('[data-outlook-new-message-help]').hidden, true);
});

test('a later authentication or sender failure is not masked by an earlier yellow report', () => {
    for (const code of ['HTTP_401', 'SENDER_MISMATCH']) {
        const f = fixture(); f.context.universalStatusByItem.set(f.item, universalTaskpaneStatus(report()));
        f.context.showUniversalStatus(f.item);
        f.context.handleFailure(failure(code), true);
        assert.equal(f.document.querySelector('[data-outlook-status]').dataset.tone, 'error');
        assert.match(f.document.querySelector('[data-outlook-status-detail]').textContent, new RegExp(code));
        assert.equal(f.calls.invalidation, 1);
    }
});

test('new-message affordance uses native instructions and never creates or sends a message', () => {
    const f = fixture();
    const help = f.document.querySelector('[data-outlook-new-message-help]');
    assert.match(help.querySelector('summary').textContent, /Neue Nachricht in Outlook öffnen/);
    assert.match(help.textContent, /Verwenden Sie in Outlook „Neue Nachricht“/);
    assert.equal(help.querySelector('button,a'), null);
    assert.doesNotMatch(pane, /displayNewMessageForm|\.sendAsync\(/);
    assert.match(markup, /\.pane-feedback\[data-tone="warning"\]/);
});
