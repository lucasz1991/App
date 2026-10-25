import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import * as automaticPolicy from '../../resources/js/outlook-addin/automatic-compose-policy.js';

// Exercise the real event coordinator with synthetic auth/Office boundaries.
// No real account, message body, token, API or network access is used.
const source = (await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8'))
    .replace(/^import\s+[\s\S]*?\s+from\s+['"][^'"]+['"];\s*$/gm, '');

function runtime({ enabled = false, composeType = 'newMail', mobile = false, bindingError = false, legacy = false } = {}) {
    const calls = { binding: 0, preflight: 0, notifications: 0, completed: 0, bootstrap: 0 };
    const events = [];
    const item = {
        getComposeTypeAsync(callback) { callback({ status: 'succeeded', value: { composeType } }); },
        body: new Proxy({}, { get() { throw new Error('Automatic policy must not access message body'); } }),
        addFileAttachmentFromBase64Async() { throw new Error('Automatic policy must not attach media'); },
        notificationMessages: { replaceAsync() { calls.notifications += 1; } },
    };
    const context = vm.createContext({
        Office: {
            AsyncResultStatus: { Succeeded: 'succeeded' },
            context: { platform: mobile ? 'iOS' : 'PC', requirements: { isSetSupported: () => true },
                mailbox: { item, diagnostics: { hostName: mobile ? 'OutlookIOS' : 'OutlookWebApp', hostVersion: '5.2635.0' } } },
            onReady() {}, actions: { associate() {} },
        },
        ...automaticPolicy,
        console, setTimeout, clearTimeout,
        __payload: { automaticInsertionEnabled: enabled,
            automaticInsertion: { schema: 2, composeType, signature: enabled, template: enabled }, binding: {
            schema: 1, mailboxAddress: 'sender@company.test', senderAddress: 'sender@company.test',
            allowedSenderAddresses: ['sender@company.test'],
        } },
        __calls: calls,
        isMobileComposeHost: () => mobile,
        diagnoseStep: (_phase, operation) => operation(),
        recordDiagnostic: (phase, outcome, detail) => events.push({ phase, outcome, code: detail?.code }),
        assertMailboxBinding: async () => {
            calls.binding += 1;
            if (bindingError) throw Object.assign(new Error('Synthetic mismatch'), { code: 'SENDER_MISMATCH' });
        },
        isTemplateInsertionBlocked: () => { calls.preflight += 1; return !legacy; },
        hasUncertainWrite: () => false,
        readTemplateState: async () => ({ readable: true, present: false }),
        hasMobileTemplateOwnership: async () => false,
        supportsTemplatePrepend: () => true,
        automaticTemplate: () => null,
        wasSignatureWriteConfirmed: () => false,
    });
    const runtimeSource = legacy ? source.replace(/    \/\/ This fresh,[\s\S]*?return 'disabled';\r?\n    }\r?\n/, '')
        .replace(/    const policy = automaticComposeDecision[\s\S]*?reportRuntimePhase\('compose-started'\);/, '    const policy = { mode: "both", signature: true, template: true };\n    reportRuntimePhase("compose-started");') : source;
    if (legacy) assert.notEqual(runtimeSource, source, 'simulate the previous cached runtime without the new policy gate');
    vm.runInContext(`${runtimeSource}
        loadConfig = async () => ({ marker: 'RT-SIGNATURE-MANAGED-V1' });
        acquireTokenSilently = async () => 'synthetic-not-a-token';
        loadBootstrap = async () => { __calls.bootstrap += 1; return __payload; };
        globalThis.applyForTest = applyPublishedContent;
    `, context);
    return { context, item, calls, events };
}

for (const mobile of [false, true]) {
    for (const composeType of ['newMail', 'reply', 'forward']) {
        test(`disabled account skips all automatic content for ${mobile ? 'mobile' : 'desktop'} ${composeType}`, async () => {
            const { context, item, calls, events } = runtime({ mobile, composeType });
            assert.equal(await context.applyForTest(item), 'disabled');
            assert.equal(calls.binding, 1, 'mailbox validation still runs before the policy gate');
            assert.equal(calls.bootstrap, 1);
            assert.equal(calls.preflight, 0, 'body ownership checks must not start');
            assert.equal(calls.notifications, 0);
            assert.ok(events.some(({ code }) => code === 'AUTOMATIC_INSERTION_DISABLED'));
        });
    }
}

test('disabled automatic event completes once without reporting a mobile insertion failure', async () => {
    const { context, calls } = runtime({ mobile: true, composeType: 'reply' });
    await context.onMessageComposeHandler({ completed() { calls.completed += 1; } });
    assert.equal(calls.completed, 1);
    assert.equal(calls.preflight, 0);
    assert.equal(calls.notifications, 0);
});

test('a confirmed enabled account retains its path while incomplete coarse flags fail closed', async () => {
    for (const enabled of [true, undefined, null, 'false', 0]) {
        const { context, item, calls } = runtime({ enabled: true });
        context.__payload.automaticInsertionEnabled = enabled;
        assert.equal(await context.applyForTest(item), enabled === true ? 'uncertain' : 'skipped');
        assert.equal(calls.preflight, enabled === true ? 1 : 0);
    }
});

test('disabled policy does not bypass the existing mailbox guard', async () => {
    const { context, item, calls } = runtime({ bindingError: true });
    await assert.rejects(context.applyForTest(item), { code: 'SENDER_MISMATCH' });
    assert.equal(calls.preflight, 0);
});

test('a disabled event does not freeze the account decision in the item cache', async () => {
    const { context, calls } = runtime();
    const event = { completed() { calls.completed += 1; } };
    await context.onNewMessageComposeHandler(event);
    await context.onNewMessageComposeHandler(event);
    assert.equal(calls.completed, 2);
    assert.equal(calls.bootstrap, 2, 'each activation checks the current authenticated account policy');
    assert.equal(calls.preflight, 0);
});

for (const mobile of [false, true]) {
    test(`a previous cached ${mobile ? 'mobile' : 'desktop'} runtime cannot insert from the minimal disabled event response`, async () => {
        const { context, item, calls } = runtime({ mobile, legacy: true });
        await assert.rejects(context.applyForTest(item), { code: 'SIGNATURE_MISSING' });
        assert.equal(calls.binding, 2);
        assert.equal(calls.preflight, 1);
        assert.equal(calls.notifications, 0);
    });
}
