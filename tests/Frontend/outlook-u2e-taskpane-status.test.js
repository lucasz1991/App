import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';
import { universalTaskpaneStatus } from '../../resources/js/outlook-addin/u2e-taskpane-status.js';
import * as bundleVersion from '../../resources/js/outlook-addin/u2e-bundle-version.js';
import { compactComposeMessage, isUnwrittenPreflight } from '../../resources/js/outlook-addin/taskpane-insert-view.js';

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

function fixture({ minimal = false } = {}) {
    const { document } = parseHTML(markup);
    if (!minimal) document.querySelector('[data-outlook-template-picker]')?.removeAttribute('data-outlook-template-picker');
    const item = {};
    const calls = { auth: 0, telemetry: 0, bodyReads: 0, diagnostics: 0, invalidation: 0, writes: 0 };
    const state = { settingsMode: false, busy: false, authenticated: true, bootstrapReady: true, configReady: true,
        signatureCurrent: false, itemChangedMonitoringReady: true, templates: [{}], selectedTemplateId: 'one' };
    const payload = { universalSignature: { kind: 'signature' }, binding: {} };
    const reports = new WeakMap();
    const blocked = new WeakSet();
    const context = vm.createContext({ ...bundleVersion, Office: { context: { mailbox: { item } } }, document,
        taskpaneState: state, currentBootstrap: payload, currentConfig: {}, lastActionError: null,
        currentTokenResult: null, insertView: null, insertFeedbackTimer: null,
        setTimeout, clearTimeout, compactComposeMessage, isUnwrittenPreflight,
        requestSilentBootstrapRefresh() {}, lastBootstrapRefreshAt: 0,
        signatureStateRevision: 0, mailboxItemRevision: 0, bootstrapStateRevision: 0, observedComposeItem: null,
        pendingItemBootstrapRevision: null,
        universalStatusByItem: new WeakMap(), universalTaskpaneStatus,
        universalComposeReport: target => reports.get(target) || null,
        universalComposeBlocked: target => blocked.has(target),
        isTemplateInsertionBlocked: () => false, isStandaloneSignatureInsertionBlocked: () => false,
        hasUncertainWrite: () => false, u2eTransportProbeReport: () => null,
        headProbeAvailable: () => false, u2eProbeAvailable: () => false,
        selectedTemplate: () => ({}), supportsTemplatePrepend: () => true, isMobileComposeHost: () => false,
        syncAutomaticPreferences() {}, syncHeadProbeButton() {}, renderSelectedTemplate() {},
        renderTemplateChoices() {}, refreshAccountOptions: async () => {}, snapshotSignatureVersion: () => '',
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
    for (const name of ['elements', 'minimalInsertMode', 'statusBlocksManual', 'setStatus', 'insertionBlocked', 'showUniversalStatus', 'updateDisabledStates',
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
    assert.match(help.textContent, /in Outlook „Neue Nachricht“/);
    assert.equal(help.querySelector('button,a'), null);
    assert.doesNotMatch(pane, /displayNewMessageForm|\.sendAsync\(/);
    assert.match(markup, /\.pane-feedback\[data-tone="warning"\]/);
});

test('incompatible bootstrap shows the actual restart hint and disables writing before any body read', async () => {
    const f = fixture();
    const accept = load('acceptBootstrap', f.context);
    const payload = { universalSignature: {}, binding: {}, addinBuild: { schema: 1, revision: 'a'.repeat(64),
        sourceTemplate: bundleVersion.U2E_SOURCE_TEMPLATE, sourceContract: 2,
        bundles: { runtime: 'b'.repeat(64), taskpane: 'c'.repeat(64) } } };
    let cause;
    try { await accept(payload); } catch (error) { cause = error; }
    assert.equal(cause.code, 'U2E_BUNDLE_STALE');
    f.context.handleFailure(cause, true);
    assert.equal(f.calls.bodyReads, 0); assert.equal(f.calls.writes, 0);
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Outlook neu starten');
    assert.match(f.document.querySelector('[data-outlook-status-detail]').textContent, /vollständig beenden/);
    assert.equal(f.button.disabled, true);
    assert.equal(f.document.querySelector('[data-outlook-action="signature"]').disabled, true);
    assert.equal(f.document.querySelector('[data-outlook-new-message-help]').hidden, true);
    assert.equal(f.calls.telemetry, 1);
});

test('a stale event marker is visible in a fresh compatible taskpane without any body write or ledger change', async () => {
    const f = fixture(), store = new Map([['railtime.u2e.compose.v1', 'pending:must-remain-unchanged']]);
    f.context.Office.AsyncResultStatus = { Succeeded: 'succeeded' };
    f.item.sessionData = {
        getAsync(key, callback) { callback({ status: 'succeeded', value: store.get(key) || '' }); },
        setAsync(key, value, callback) { store.set(key, value); callback({ status: 'succeeded' }); },
    };
    const current = { universalSignature: {}, binding: {}, addinBuild: { schema: 1, revision: 'a'.repeat(64),
        sourceTemplate: bundleVersion.U2E_SOURCE_TEMPLATE, sourceContract: 1,
        bundles: { runtime: 'b'.repeat(64), taskpane: 'c'.repeat(64) } } };
    let cause;
    try { bundleVersion.assertUniversalBundleCompatibility({ ...current, addinBuild: { ...current.addinBuild, sourceContract: 2 } }); }
    catch (error) { cause = error; }
    assert.equal(await bundleVersion.persistUniversalBundleFailure(f.context.Office, f.item, cause), true);
    // The new pane uses compatible code. It must still show the event's own
    // per-item diagnostic rather than claim that the event context was reloaded.
    const accept = load('acceptBootstrap', f.context);
    await accept(current);
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Outlook neu starten');
    assert.equal(f.button.disabled, true); assert.equal(f.calls.writes, 0);
    await f.context.refreshSignatureCurrentState();
    f.context.syncActionState();
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Outlook neu starten');
    assert.equal(store.get('railtime.u2e.compose.v1'), 'pending:must-remain-unchanged');
    assert.equal(await bundleVersion.clearUniversalBundleFailure(f.context.Office, f.item), true);
    await accept(current);
    assert.equal(f.button.disabled, false);
    assert.equal(store.get('railtime.u2e.compose.v1'), 'pending:must-remain-unchanged');
});

test('a stale event discovered during manual authentication blocks its already-started callback', async () => {
    const f = fixture();
    const saved = JSON.stringify({ schema: 1, code: 'U2E_BUNDLE_STALE', at: '2026-11-04T08:54:09.000Z', sourceContract: 2, readerContract: 1 });
    f.context.Office.AsyncResultStatus = { Succeeded: 'succeeded' };
    f.item.sessionData = { getAsync(key, callback) {
        assert.equal(key, bundleVersion.U2E_BUNDLE_SESSION_KEY);
        callback({ status: 'succeeded', value: saved });
    } };
    f.context.acceptBootstrap = load('acceptBootstrap', f.context);
    await f.context.withAuthenticatedBootstrap(f.button, async () => { f.calls.writes++; });
    assert.equal(f.calls.writes, 0); assert.equal(f.calls.telemetry, 0);
    assert.equal(f.document.querySelector('[data-outlook-status-title]').textContent, 'Outlook neu starten');
    assert.equal(f.button.disabled, true); assert.equal(f.state.busy, false);
});

test('minimal pane exposes one compact preflight message and allows only explicit manual retry before any mutation', async () => {
    const f = fixture({ minimal: true });
    const before = report({ state: 'rejected', code: 'U2E_NEW_DRAFT_REQUIRED', phase: 'preflight',
        step: 'snapshot.region', attempted: false, headerWritten: false, bodyWriteStarted: false, bodyWriteSucceeded: false });
    f.reports.set(f.item, before);
    f.context.universalStatusByItem.set(f.item, universalTaskpaneStatus(before));
    f.context.showUniversalStatus(f.item); f.context.syncActionState();
    const feedback = f.document.querySelector('[data-outlook-pane-feedback]');
    assert.match(feedback.textContent, /nicht ersetzt/);
    assert.doesNotMatch(feedback.textContent, /nicht bestätigt|Zeitpunkt|Prüfschritt/);
    assert.equal(f.document.querySelector('[data-outlook-status]').hidden, true);
    assert.equal(f.document.querySelector('[data-outlook-status]').getAttribute('aria-live'), 'off');
    assert.equal(f.button.disabled, false);
    await f.context.withAuthenticatedBootstrap(f.button, () => { f.calls.writes++; });
    assert.equal(f.calls.writes, 0, 'automatic/old entry cannot use the manual retry permission');
    await f.context.withAuthenticatedBootstrap(f.button, () => { f.calls.writes++; }, { manualReplace: true });
    assert.equal(f.calls.writes, 1);
});

test('minimal manual entry cannot clear uncertain state or write through a pending operation', async () => {
    const f = fixture({ minimal: true });
    f.reports.set(f.item, report()); f.blocked.add(f.item);
    f.context.universalStatusByItem.set(f.item, universalTaskpaneStatus(report()));
    f.context.syncActionState();
    await f.context.withAuthenticatedBootstrap(f.button, () => { f.calls.writes++; }, { manualReplace: true });
    assert.equal(f.calls.writes, 0); assert.equal(f.calls.auth, 0); assert.equal(f.button.disabled, true);
    assert.match(f.document.querySelector('[data-outlook-pane-feedback]').textContent, /Einfügung konnte nicht bestätigt werden/);
});

function manualSelectionFixture() {
    const f = fixture({ minimal: true }), document = { kind: 'combined', version: 'a'.repeat(24) };
    f.state.templates = [{ id: 'chosen', name: 'Auswahl', document: { universalCompose: document } }];
    f.context.selectedTemplate = () => f.state.templates[0];
    f.context.validateUniversalComposeDocument = value => value;
    f.context.rememberConfirmedInsertion = () => {};
    f.context.replaceUniversalComposeDocument = async (_office, item, source, options) => {
        assert.equal(item, f.item); assert.equal(source, document);
        await options.beforeWrite(); f.calls.writes++;
        return { status: 'applied' };
    };
    f.context.insertUniversalComposeDocument = async () => assert.fail('explicit selection must use replacement');
    f.insert = load('insertTemplate', f.context);
    f.options = { manualReplace: true, expectedTemplateId: 'chosen', expectedTarget: f.context.captureComposeTarget() };
    return f;
}

test('manual taskpane selection calls the replacement API again after a confirmed success', async () => {
    const f = manualSelectionFixture();
    for (let attempt = 0; attempt < 2; attempt++) {
        const result = await f.insert(f.button, f.options);
        assert.equal(result.applied, true);
        assert.equal(f.document.querySelector('[data-outlook-pane-feedback]').textContent, 'Neu eingefügt');
        clearTimeout(f.context.insertFeedbackTimer);
    }
    assert.equal(f.calls.writes, 2);
    assert.equal(f.calls.auth, 2, 'each selection refreshes authenticated source data');
});

test('manual selection never falls back to a different template after bootstrap removes its ID', async () => {
    const f = manualSelectionFixture();
    f.context.acceptBootstrap = async () => {
        f.state.templates = [{ ...f.state.templates[0], id: 'other' }];
    };
    const result = await f.insert(f.button, f.options);
    assert.equal(result.applied, false); assert.equal(f.calls.writes, 0);
    assert.equal(f.context.lastActionError.code, 'TEMPLATE_SELECTION_MISSING');
});

test('selection captured for a different compose item never replaces the current item', async () => {
    const f = manualSelectionFixture();
    f.options.expectedTarget = { item: {}, revision: 0 };
    const result = await f.insert(f.button, f.options);
    assert.equal(result.applied, false); assert.equal(f.calls.writes, 0);
    assert.equal(f.context.lastActionError.code, 'COMPOSE_ITEM_CHANGED');
});

test('a failed replacement never returns applied or exposes the success toast', async () => {
    const f = manualSelectionFixture();
    f.context.replaceUniversalComposeDocument = async () => { throw failure('U2E_NEW_DRAFT_REQUIRED'); };
    const result = await f.insert(f.button, f.options);
    assert.equal(result.applied, false); assert.equal(f.calls.writes, 0);
    assert.doesNotMatch(f.document.querySelector('[data-outlook-pane-feedback]').textContent, /Neu eingefügt/);
});
