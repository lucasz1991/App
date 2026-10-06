import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import * as compose from '../../resources/js/outlook-addin/compose-template.js';
import * as guard from '../../resources/js/outlook-addin/mailbox-guard.js';
import * as writes from '../../resources/js/outlook-addin/office-write.js';
import * as diagnostics from '../../resources/js/outlook-addin/diagnostics.js';

const ADDRESS = 'employee@company.example';
const MARKER = 'RT-SIGNATURE-MANAGED-V1';
const VERSION = '0123456789abcdef';
const COMBINED_HTML = `<!--${compose.COMBINED_TEMPLATE_MARKER}--><table data-rt-compose-document="combined-v1"><tbody><tr><td><p>Guten Tag,</p><p><br></p><p>Mit freundlichen Grüßen,</p></td></tr><tr><td><!--${MARKER}--><!--RT-SIGNATURE-VERSION:${VERSION}--><p>Personal signature</p><img src="cid:train-content" width="600" height="75"></td></tr></tbody></table>`;
const NATIVE_HTML = `<!--${compose.NATIVE_TEMPLATE_MARKER}--><p>Separate template</p>`;
const OLD_SIGNATURE = `<!--${MARKER}--><table data-host-signature="1"><tbody><tr><td>Old native signature</td></tr></tbody></table>`;
const PAIRED_SIGNATURE = `<!--${MARKER}--><!--RT-SIGNATURE-VERSION:${VERSION}--><p>Paired mobile signature</p>`;
const MOBILE_HTML = COMBINED_HTML.replace('combined-v1', 'combined-native-v1')
    .replace('<p>Guten Tag,</p>', `<!--RT-MOBILE-COMPOSE-VERSION:${VERSION}--><p>Guten Tag,</p>`);
const TYPED = '<p>Already typed &amp; preserved <b>exactly</b>.</p>';
const QUOTE = `<div id="divRplyFwdMsg"><blockquote>${COMBINED_HTML}<p>Original message &amp; history.</p></blockquote></div>`;
const MEDIA = Object.freeze({ name: 'train.gif', contentId: 'train-content', base64: 'TQ==' });
const success = (value) => ({ status: 'succeeded', value });
const failure = (code) => ({ status: 'failed', error: { code, message: 'Private host message' } });
const flush = async (until = () => false) => {
    for (let i = 0; i < 300 && !until(); i += 1) await Promise.resolve();
};

function documentContract(changes = {}) {
    return {
        id: 'template-a', key: 'template-a', isDefault: true,
        signatureMode: 'native', html: '<p>Legacy complete document; never use as fallback</p>', media: [],
        composeHtml: NATIVE_HTML, composeMedia: [],
        composeDocumentMode: 'combined-v1', combinedComposeHtml: COMBINED_HTML, combinedComposeMedia: [MEDIA],
        signature: { html: PAIRED_SIGNATURE, media: [] },
        ...changes,
    };
}

function hostFixture({ mobile = false, composeType = 'newMail', body = TYPED + OLD_SIGNATURE, session = '' } = {}) {
    const state = {
        body, nativeSignature: body.includes(OLD_SIGNATURE) ? OLD_SIGNATURE : '', session,
        sender: ADDRESS, log: [], prepends: [], signatures: [], attachments: [],
        sessionWrites: [], callbacks: {}, notifications: [],
    };
    const item = {
        from: { getAsync(cb) { cb(success({ emailAddress: state.sender })); } },
        getComposeTypeAsync(cb) { cb(success({ composeType, coercionType: 'html' })); },
        sessionData: {
            getAsync(_key, cb) { cb(success(state.session)); },
            setAsync(_key, value, cb) { state.session = value; state.sessionWrites.push(value); cb(success()); },
        },
        body: {
            getTypeAsync(cb) { state.log.push('type-read'); cb(success('html')); },
            getAsync(_format, options, complete) {
                const cb = typeof options === 'function' ? options : complete;
                const currentOnly = typeof options === 'object' && options?.bodyMode === 1;
                const html = currentOnly ? state.body.split('<div id="divRplyFwdMsg">')[0] : state.body;
                cb(success(html));
            },
            prependAsync(html, _options, cb) {
                state.log.push('prepend'); state.prepends.push(html);
                state.body = html + state.body; cb(success());
            },
            setSignatureAsync(html, _options, cb) {
                state.log.push(html === '' ? 'native-clear' : 'native-signature'); state.signatures.push(html);
                if (state.nativeSignature) state.body = state.body.replace(state.nativeSignature, '');
                state.nativeSignature = html;
                if (html) state.body = html + state.body;
                cb(success());
            },
            setAsync() { throw new Error('Full body replacement is forbidden'); },
        },
        getAttachmentsAsync(cb) { cb(success(state.attachments.map((name) => ({ name, isInline: true })))); },
        addFileAttachmentFromBase64Async(_base64, name, options, cb) {
            assert.equal(options.isInline, true);
            state.log.push(`media:${name}`); state.attachments.push(name); cb(success(`attachment-${name}`));
        },
        notificationMessages: { replaceAsync(_key, value, cb) { state.notifications.push(value); cb(success()); } },
    };
    const office = {
        AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        MailboxEnums: { BodyMode: { HostConfig: 1 } },
        actions: { associate() {} }, onReady() {},
        context: {
            platform: mobile ? 'iOS' : 'PC', requirements: { isSetSupported() { return true; } },
            mailbox: { item, userProfile: { emailAddress: ADDRESS },
                diagnostics: { hostName: mobile ? 'OutlookIOS' : 'OutlookWebApp', hostVersion: mobile ? '5.2635.0' : '1.0' } },
        },
    };
    const binding = { schema: 1, mailboxAddress: ADDRESS, senderAddress: ADDRESS, allowedSenderAddresses: [ADDRESS] };
    const bootstrap = {
        marker: MARKER, binding, automaticTemplateId: 'template-a', templates: [documentContract()],
        signature: { html: `<!--${MARKER}--><p>Global signature; not the paired selection</p>`, media: [] },
    };
    return { state, item, office, bootstrap, binding };
}

async function runtimeHarness(options = {}, mutate = () => {}) {
    const fixture = hostFixture(options);
    const source = await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8');
    const logs = [];
    const config = { ready: true, marker: MARKER, auth: { clientId: 'test-client', authority: 'https://login.example.test/tenant', scopes: ['api://test/read'] },
        endpoints: { bootstrap: 'https://company.example/outlook-addin/bootstrap' } };
    mutate(fixture);
    const context = vm.createContext({
        ...compose, ...guard, ...writes, ...diagnostics, Office: fixture.office,
        URL, AbortController, setTimeout, clearTimeout,
        console: { info(value) { logs.push(value); } },
        RAILTIME_OUTLOOK_CONFIG_URL: 'https://company.example/outlook-addin/config.json',
        InteractionRequiredAuthError: class extends Error {},
        createNestablePublicClientApplication: async () => ({
            getAllAccounts: () => [{ username: ADDRESS }],
            acquireTokenSilent: async () => ({ accessToken: 'local-test-only-token' }),
        }),
        fetch: async (url) => ({ ok: true, json: async () => String(url).includes('bootstrap') ? fixture.bootstrap : config }),
    });
    // Execute the production source, replacing only module resolution with the
    // actual imported dependencies above; no write algorithm is copied here.
    vm.runInContext(source.replace(/^import\s[\s\S]*?\sfrom\s['"][^'"]+['"];\r?$/gm, ''), context);
    let completed = 0;
    const run = () => context.onNewMessageComposeHandler({ completed() { completed += 1; } });
    return { ...fixture, logs, run, get completed() { return completed; } };
}

async function manualHarness(options = {}) {
    const fixture = hostFixture(options);
    const source = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    const target = { item: fixture.item };
    const document = documentContract();
    const state = { statuses: [], confirms: 0, confirmed: false };
    const context = vm.createContext({
        ...compose, ...guard, ...writes, ...diagnostics, Office: fixture.office,
        scopedComposeBodyHtml: compose.currentComposeBodyHtml, setTimeout, clearTimeout,
        console, URL, AbortController,
        __manualTarget: target, __manualDocument: document, __manualBootstrap: fixture.bootstrap, __manualState: state,
    });
    vm.runInContext(source.replace(/^import\s[\s\S]*?\sfrom\s['"][^'"]+['"];\r?$/gm, '').replace(/^export /gm, ''), context);
    // Bypass only the UI/authentication shell. The real document selector,
    // insertion coordinator, guarded media work and Office write functions run.
    vm.runInContext(`
        currentConfig = { marker: '${MARKER}' };
        selectedTemplate = () => ({ document: __manualDocument, name: 'Test template', version: '1' });
        setStatus = (...value) => __manualState.statuses.push(value);
        withAuthenticatedBootstrap = (_button, operation) => operation(__manualBootstrap, __manualTarget);
        assertComposeTarget = (target) => {
            if (Office.context.mailbox.item !== target.item) throw codedError('COMPOSE_ITEM_CHANGED');
            return target.item;
        };
        assertWriteTarget = (target, binding) => assertMailboxBinding(Office, target.item, binding);
        confirmAdditionalTemplate = () => { __manualState.confirms += 1; return __manualState.confirmed; };
        rememberConfirmedInsertion = () => {};
        renderSelectedTemplate = () => {};
        globalThis.__runManual = () => insertTemplate({});
    `, context);
    return { ...fixture, manual: state, document, run: () => context.__runManual() };
}

test('combined selector selects the complete explicit document without changing legacy fields or media', () => {
    const original = Object.freeze(documentContract());
    const selected = compose.combinedComposeDocument(original);
    assert.equal(selected.html, COMBINED_HTML);
    assert.equal(selected.media, original.combinedComposeMedia);
    assert.equal(selected.composeHtml, NATIVE_HTML);
    assert.equal(original.html, '<p>Legacy complete document; never use as fallback</p>');
    assert.equal(Object.isFrozen(selected), true);
    assert.equal(compose.nativeComposeTemplate(original).html, NATIVE_HTML);
    assert.equal(compose.combinedComposeDocument({ html: '<p>Legacy</p>' }), null);
});

test('explicit incomplete or unknown combined contracts fail closed instead of selecting partial native fields', () => {
    for (const changes of [
        { composeDocumentMode: null }, { composeDocumentMode: 'unknown' }, { composeDocumentMode: 'COMBINED-V1' },
        { combinedComposeHtml: null }, { combinedComposeHtml: '' }, { combinedComposeHtml: NATIVE_HTML },
        { combinedComposeHtml: COMBINED_HTML.replace(MARKER, 'OTHER-MARKER') },
        { combinedComposeHtml: COMBINED_HTML.replace(compose.COMBINED_TEMPLATE_MARKER, `${compose.COMBINED_TEMPLATE_MARKER}-OTHER`) },
        { combinedComposeHtml: COMBINED_HTML.replace('data-rt-compose-document="combined-v1"', '') },
        { combinedComposeHtml: COMBINED_HTML + NATIVE_HTML }, { combinedComposeMedia: null },
        { combinedComposeMedia: [null] }, { combinedComposeMedia: [{ base64: 'bad!' }] },
        { combinedComposeHtml: COMBINED_HTML + 'x'.repeat(compose.TEMPLATE_INSERT_LIMITS.htmlLength) },
    ]) assert.throws(() => compose.combinedComposeDocument(documentContract(changes)), { code: 'COMBINED_TEMPLATE_INVALID' });
});

test('combined marker is recognized as one embedded-signature document, including across reopened runtimes', async () => {
    assert.deepEqual(compose.templateStateFromBody(COMBINED_HTML, 'newMail'), {
        present: true, legacySignatureEmbedded: true, readable: true, bodyLength: COMBINED_HTML.length, tooLarge: false,
    });
    const reopened = await import('../../resources/js/outlook-addin/compose-template.js?combined-marker-reopen');
    assert.equal(reopened.templateStateFromBody(COMBINED_HTML, 'newMail').legacySignatureEmbedded, true);
    assert.equal(compose.markedTemplateHtml(COMBINED_HTML), COMBINED_HTML);
});

test('desktop automatic compose attaches media, clears native ownership, then writes one complete block only', async () => {
    const h = await runtimeHarness();
    await h.run();
    assert.equal(h.completed, 1);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.prepends.length, 1);
    assert.match(h.state.prepends[0], /combined-v1/);
    assert.match(h.state.prepends[0], /cid:train\.gif/);
    assert.equal(h.state.body, h.state.prepends[0] + TYPED);
    assert.deepEqual(h.state.log.filter(value => /^(media:|native-|prepend)/.test(value)), ['media:train.gif', 'native-clear', 'prepend']);
    assert.equal(h.state.session, '1');
    await flush();
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.prepends.length, 1);
});

test('new, fully opened reply and forward preserve all typed and quoted bytes outside native ownership', async () => {
    for (const composeType of ['newMail', 'reply', 'forward']) {
        const original = TYPED + OLD_SIGNATURE + (composeType === 'newMail' ? '' : QUOTE);
        const h = await runtimeHarness({ composeType, body: original });
        await h.run();
        assert.equal(h.state.prepends.length, 1, composeType);
        assert.equal(h.state.body, h.state.prepends[0] + original.replace(OLD_SIGNATURE, ''), composeType);
        assert.deepEqual(h.state.signatures, [''], composeType);
        assert.equal(h.completed, 1);
    }
});

test('simultaneous and subsequent event activations share one insertion without rewriting edited content', async () => {
    const h = await runtimeHarness();
    await Promise.all([h.run(), h.run()]);
    h.state.body = h.state.body.replace('<p><br></p>', '<p>User edits inside combined document</p>');
    const edited = h.state.body;
    await h.run();
    assert.equal(h.completed, 3);
    assert.equal(h.state.body, edited);
    assert.equal(h.state.prepends.length, 1);
    assert.deepEqual(h.state.signatures, ['']);
    assert.deepEqual(h.state.attachments, ['train.gif']);
});

test('current combined or legacy embedded marker stops a newly loaded automatic runtime before mutations', async () => {
    for (const body of [COMBINED_HTML + TYPED, `<!--${compose.TEMPLATE_MARKER}--><p>Historical managed body</p>${TYPED}`]) {
        const h = await runtimeHarness({ body });
        await h.run();
        assert.equal(h.state.body, body);
        assert.deepEqual(h.state.prepends, []);
        assert.deepEqual(h.state.signatures, []);
        assert.deepEqual(h.state.attachments, []);
    }
});

test('invalid explicit desktop combined artifact never falls back to a partial template or native signature', async () => {
    const h = await runtimeHarness({}, ({ bootstrap }) => {
        bootstrap.templates[0].combinedComposeHtml = '<p>Invalid explicit combined artifact</p>';
    });
    await h.run();
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.deepEqual(h.state.prepends, []);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.attachments, []);
    assert.ok(h.logs.some(value => value.includes('COMBINED_TEMPLATE_INVALID')));
});

test('unsupported full-template mobile APIs remain unused and only the selected paired signature is written', async () => {
    const h = await runtimeHarness({ mobile: true, composeType: 'reply', body: TYPED + QUOTE }, ({ item }) => {
        item.body.getTypeAsync = item.body.prependAsync = item.body.setAsync = () => { throw new Error('Unsupported mobile body API'); };
    });
    await h.run();
    assert.deepEqual(h.state.signatures, [PAIRED_SIGNATURE]);
    assert.deepEqual(h.state.prepends, []);
    assert.deepEqual(h.state.attachments, []);
    assert.equal(h.state.body, PAIRED_SIGNATURE + TYPED + QUOTE);
    assert.equal(h.completed, 1);
});

function enableMobileCombined({ bootstrap }) {
    Object.assign(bootstrap.templates[0], {
        mobileComposeDocumentMode: 'combined-native-v1', mobileComposeHtml: MOBILE_HTML,
        mobileComposeMedia: [MEDIA], mobileComposeVersion: VERSION,
    });
}

test('explicit mobile contract selects complete fields without changing Desktop or standalone signature', () => {
    const original = documentContract({ mobileComposeDocumentMode: 'combined-native-v1', mobileComposeHtml: MOBILE_HTML,
        mobileComposeMedia: [MEDIA], mobileComposeVersion: VERSION });
    const selected = compose.mobileCombinedComposeDocument(original);
    assert.equal(selected.html, MOBILE_HTML);
    assert.equal(original.combinedComposeHtml, COMBINED_HTML);
    assert.equal(original.signature.html, PAIRED_SIGNATURE);
    for (const changes of [{ mobileComposeDocumentMode: 'unknown' }, { mobileComposeVersion: 'bad' },
        { mobileComposeVersion: 'fedcba9876543210' }, { mobileComposeMedia: undefined },
        { mobileComposeHtml: MOBILE_HTML.replace(`RT-MOBILE-COMPOSE-VERSION:${VERSION}`, `RT-MOBILE-COMPOSE-VERSION:${VERSION}EXTRA`) },
        { mobileComposeHtml: COMBINED_HTML }, { mobileComposeHtml: MOBILE_HTML + 'x'.repeat(30000) }]) {
        assert.throws(() => compose.mobileCombinedComposeDocument({ ...original, ...changes }), { code: 'MOBILE_COMBINED_TEMPLATE_INVALID' });
    }
});

test('mobile new, full reply and forward write the entire paired document once and preserve external typed/quoted bytes', async () => {
    for (const composeType of ['newMail', 'reply', 'forward']) {
        const original = TYPED + OLD_SIGNATURE + (composeType === 'newMail' ? '' : QUOTE);
        const h = await runtimeHarness({ mobile: true, composeType, body: original }, fixture => {
            enableMobileCombined(fixture);
            fixture.item.body.getTypeAsync = fixture.item.body.prependAsync = fixture.item.body.setAsync =
                fixture.item.body.setSelectedDataAsync = () => { throw new Error('Unsupported or unsafe body mutation'); };
        });
        await h.run();
        assert.deepEqual(h.state.prepends, [], composeType);
        assert.equal(h.state.signatures.length, 1, composeType);
        assert.ok(h.state.signatures[0].includes('Guten Tag,'));
        assert.ok(h.state.signatures[0].includes('Personal signature'));
        assert.ok(h.state.signatures[0].includes('cid:train.gif'));
        assert.equal(h.state.body, h.state.signatures[0] + original.replace(OLD_SIGNATURE, ''));
        assert.deepEqual(h.state.attachments, ['train.gif']);
        assert.equal(h.state.session, '1');
        assert.equal(h.completed, 1);
    }
});

test('documented mobile SessionData exception is used even when baseline Mailbox1.11 checker returns false', async () => {
    const h = await runtimeHarness({ mobile: true, composeType: 'reply', body: TYPED + QUOTE }, fixture => {
        enableMobileCombined(fixture);
        fixture.office.context.requirements.isSetSupported = (_name, version) => version !== '1.11';
        fixture.office.context.platform = 'Android';
        fixture.office.context.mailbox.diagnostics.hostName = 'OutlookAndroid';
        fixture.item.sessionData.getAsync = (_key, cb) => cb(fixture.state.session === '' ? failure('9050') : success(fixture.state.session));
    });
    await h.run();
    assert.equal(h.state.signatures.length, 1);
    assert.match(h.state.sessionWrites[0], /^pending:/);
    assert.equal(h.state.session, '1');
});

test('mobile capability, unknown/text coercion and session read failure stop before any media/body mutation', async () => {
    const mutations = [
        fixture => { fixture.office.context.mailbox.diagnostics.hostVersion = '4.2424.0'; },
        fixture => { fixture.office.context.mailbox.diagnostics.hostVersion = '999999999999999999999.2635.0'; },
        fixture => { fixture.office.context.mailbox.diagnostics.hostName = 'Unknown'; },
        fixture => { delete fixture.item.sessionData.setAsync; },
        fixture => { fixture.item.getComposeTypeAsync = cb => cb(success({ composeType: 'reply', coercionType: 'text' })); },
        fixture => { fixture.item.getComposeTypeAsync = cb => cb(success({ composeType: 'reply' })); },
        fixture => { fixture.item.sessionData.getAsync = (_key, cb) => cb(failure('9999')); },
    ];
    for (const mutate of mutations) {
        const h = await runtimeHarness({ mobile: true }, fixture => { enableMobileCombined(fixture); mutate(fixture); });
        await h.run();
        assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
        assert.deepEqual(h.state.attachments, []);
        assert.deepEqual(h.state.signatures, []);
        assert.deepEqual(h.state.prepends, []);
        assert.equal(h.completed, 1);
    }
});

test('invalid explicit mobile contract never falls back to separate template or partial signature', async () => {
    const h = await runtimeHarness({ mobile: true }, fixture => {
        enableMobileCombined(fixture);
        fixture.bootstrap.templates[0].mobileComposeHtml = '<p>Invalid mobile combined</p>';
    });
    await h.run();
    assert.deepEqual(h.state.attachments, []);
    assert.deepEqual(h.state.signatures, []);
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.ok(h.logs.some(value => value.includes('MOBILE_COMBINED_TEMPLATE_INVALID')));
});

test('simultaneous mobile activations share ownership and edited native combined content survives same/reopened runtime', async () => {
    const h = await runtimeHarness({ mobile: true }, enableMobileCombined);
    await Promise.all([h.run(), h.run()]);
    h.state.body = h.state.body.replace('<p><br></p>', '<p>User has edited this native-owned row</p>');
    const edited = h.state.body;
    await h.run();
    assert.equal(h.state.body, edited);
    assert.equal(h.state.signatures.length, 1);
    assert.equal(h.completed, 3);
    const reopened = await runtimeHarness({ mobile: true, body: edited }, enableMobileCombined);
    await reopened.run();
    assert.equal(reopened.state.body, edited);
    assert.deepEqual(reopened.state.signatures, []);
    assert.deepEqual(reopened.state.attachments, []);
});

test('completed mobile native-slot session preserves edited text even after every template marker is removed', async () => {
    for (const [baselineSupported, defaultStillAvailable] of [[true, true], [false, true], [true, false], [false, false]]) {
        const edited = '<p>All metadata removed while editing; this text remains native-owned.</p>';
        const h = await runtimeHarness({ mobile: true, body: edited, session: '1' }, fixture => {
            enableMobileCombined(fixture);
            fixture.state.nativeSignature = edited;
            if (!defaultStillAvailable) { fixture.bootstrap.automaticTemplateId = null; fixture.bootstrap.templates = []; }
            fixture.office.context.requirements.isSetSupported = (_name, version) => version !== '1.11' || baselineSupported;
        });
        await h.run();
        assert.equal(h.state.body, edited);
        assert.deepEqual(h.state.signatures, []);
        assert.deepEqual(h.state.attachments, []);
        assert.equal(h.state.session, '1');
    }
});

test('lost mobile version/host/setter capability cannot mean absent native-slot ownership', async () => {
    for (const mutate of [
        fixture => { delete fixture.office.context.mailbox.diagnostics.hostVersion; },
        fixture => { fixture.office.context.mailbox.diagnostics.hostName = 'Unknown'; },
        fixture => { delete fixture.item.sessionData.setAsync; },
        fixture => { delete fixture.item.sessionData.getAsync; },
    ]) {
        const edited = '<p>Still native-owned after capability information disappears.</p>';
        const h = await runtimeHarness({ mobile: true, body: edited, session: '1' }, fixture => {
            fixture.bootstrap.templates = []; fixture.bootstrap.automaticTemplateId = null;
            fixture.state.nativeSignature = edited;
            fixture.office.context.requirements.isSetSupported = (_name, version) => version !== '1.11';
            mutate(fixture);
        });
        await h.run();
        assert.equal(h.state.body, edited);
        assert.deepEqual(h.state.signatures, []);
        assert.deepEqual(h.state.attachments, []);
    }
});

test('a changed sender or foreign session claim after mobile media preparation prevents the native document write', async () => {
    for (const mutate of [
        fixture => { fixture.state.sender = 'other@company.example'; },
        fixture => { fixture.state.session = 'pending:foreign-runtime'; },
    ]) {
        const h = await runtimeHarness({ mobile: true }, fixture => {
            enableMobileCombined(fixture);
            const original = fixture.item.addFileAttachmentFromBase64Async;
            fixture.item.addFileAttachmentFromBase64Async = (...args) => { original(...args); mutate(fixture); };
        });
        await h.run();
        assert.deepEqual(h.state.attachments, ['train.gif']);
        assert.deepEqual(h.state.signatures, []);
        assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
        assert.match(h.state.session, /^pending:/);
    }
});

test('current combined arriving during media work blocks a second native write', async () => {
    const h = await runtimeHarness({ mobile: true }, fixture => {
        enableMobileCombined(fixture);
        const original = fixture.item.addFileAttachmentFromBase64Async;
        fixture.item.addFileAttachmentFromBase64Async = (...args) => {
            original(...args); fixture.state.body = MOBILE_HTML + fixture.state.body;
        };
    });
    await h.run();
    assert.deepEqual(h.state.signatures, []);
    assert.equal(h.state.body, MOBILE_HTML + TYPED + OLD_SIGNATURE);
});

test('definite failed mobile signature callback releases its own session, no partial fallback; retry prepares no duplicate media', async () => {
    const h = await runtimeHarness({ mobile: true }, fixture => {
        enableMobileCombined(fixture);
        fixture.item.body.setSignatureAsync = (_html, _options, cb) => cb(failure('HOST_FAILED'));
    });
    await h.run();
    assert.equal(h.state.session, '');
    assert.equal(compose.isTemplateInsertionBlocked(h.item), false);
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    h.item.body.setSignatureAsync = (html, _options, cb) => {
        h.state.signatures.push(html); h.state.body = html + TYPED; cb(success());
    };
    await h.run();
    assert.equal(h.state.signatures.length, 1);
    assert.deepEqual(h.state.attachments, ['train.gif']);
});

test('uncertain mobile native signature is quarantined; late callback never starts a later body write', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    for (const lateResult of [success(), failure('LATE_FAIL')]) {
        const h = await runtimeHarness({ mobile: true }, fixture => {
            enableMobileCombined(fixture);
            fixture.item.body.setSignatureAsync = (html, _options, cb) => {
                fixture.state.signatures.push(html); fixture.state.callbacks.mobile = cb;
            };
        });
        const running = h.run();
        await flush(() => h.state.callbacks.mobile);
        assert.ok(h.state.callbacks.mobile);
        t.mock.timers.tick(30000);
        await running;
        assert.equal(h.completed, 1);
        assert.equal(compose.isTemplateInsertionBlocked(h.item), true);
        assert.match(h.state.session, /^pending:/);
        await h.run();
        assert.equal(h.state.signatures.length, 1);
        h.state.callbacks.mobile(lateResult);
        await flush();
        await h.run();
        assert.equal(h.state.signatures.length, 1);
        assert.deepEqual(h.state.prepends, []);
        assert.equal(h.completed, 3);
        assert.equal(compose.isTemplateInsertionBlocked(h.item), lateResult.status !== 'succeeded');
    }
});

test('mobile55second event expiry during inline media work never starts a late native signature', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let started = 0;
    const h = await runtimeHarness({ mobile: true }, fixture => {
        enableMobileCombined(fixture);
        fixture.bootstrap.templates[0].mobileComposeMedia = Array.from({ length: 15 }, (_x, i) => ({ ...MEDIA, name: `image-${i}.png`, contentId: `image-${i}` }));
        fixture.item.addFileAttachmentFromBase64Async = (_data, name, _options, cb) => {
            started += 1;
            setTimeout(() => { fixture.state.attachments.push(name); cb(success(name)); }, 4000);
        };
    });
    const running = h.run();
    await flush(() => started === 1);
    for (let i = 0; i < 13; i += 1) {
        t.mock.timers.tick(4000);
        await flush(() => started >= i + 2);
    }
    t.mock.timers.tick(3000);
    await running;
    assert.equal(h.completed, 1);
    assert.equal(started, 14);
    t.mock.timers.tick(1000);
    await flush();
    assert.deepEqual(h.state.signatures, []);
    assert.equal(started, 14);
    assert.match(h.state.session, /^pending:/);
});

test('sender mismatch stops combined automatic insertion before attachment, cleanup or prepend', async () => {
    const h = await runtimeHarness({}, ({ state }) => { state.sender = 'other@company.example'; });
    await h.run();
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.deepEqual(h.state.attachments, []);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
});

test('sender switch after media attachment prevents native cleanup and common insertion', async () => {
    const h = await runtimeHarness({}, ({ item, state }) => {
        const original = item.addFileAttachmentFromBase64Async;
        item.addFileAttachmentFromBase64Async = (...args) => { state.sender = 'other@company.example'; original(...args); };
    });
    await h.run();
    assert.deepEqual(h.state.attachments, ['train.gif']);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.match(h.state.session, /^pending:/);
});

test('compose-item switch after native cleanup prevents writing into the replacement draft', async () => {
    const h = await runtimeHarness({}, ({ item, office }) => {
        const original = item.body.setSignatureAsync;
        item.body.setSignatureAsync = (...args) => { office.context.mailbox.item = {}; original(...args); };
    });
    await h.run();
    assert.deepEqual(h.state.signatures, ['']);
    assert.deepEqual(h.state.prepends, []);
    assert.equal(h.state.body, TYPED);
    assert.match(h.state.session, /^pending:/);
});

test('definite native cleanup failure never prepends and a safe retry inserts one common document', async () => {
    const h = await runtimeHarness({}, ({ item, state }) => {
        item.body.setSignatureAsync = (_html, _options, cb) => { state.log.push('failed-clear'); cb(failure('CLEAR_FAILED')); };
    });
    await h.run();
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.deepEqual(h.state.prepends, []);
    assert.equal(h.state.session, '');
    h.item.body.setSignatureAsync = (html, _options, cb) => {
        h.state.signatures.push(html); h.state.body = h.state.body.replace(OLD_SIGNATURE, ''); cb(success());
    };
    await h.run();
    assert.equal(h.state.prepends.length, 1);
    assert.equal(h.state.body, h.state.prepends[0] + TYPED);
    assert.deepEqual(h.state.attachments, ['train.gif']);
});

test('unknown native cleanup callback quarantines insertion; late success cannot start a prepend', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = await runtimeHarness({}, ({ item, state }) => {
        item.body.setSignatureAsync = (html, _options, cb) => { state.signatures.push(html); state.callbacks.clear = cb; };
    });
    const running = h.run();
    await flush(() => h.state.callbacks.clear);
    assert.ok(h.state.callbacks.clear);
    t.mock.timers.tick(30000);
    await running;
    assert.equal(h.completed, 1);
    assert.equal(writes.hasUncertainWrite(h.item), true);
    assert.equal(compose.isTemplateInsertionBlocked(h.item), true);
    assert.deepEqual(h.state.prepends, []);
    await h.run();
    h.state.callbacks.clear(success());
    await flush();
    await h.run();
    assert.deepEqual(h.state.signatures, ['']);
    assert.deepEqual(h.state.prepends, []);
    assert.equal(compose.isTemplateInsertionBlocked(h.item), true);
});

test('unknown common prepend never starts a late signature write or duplicates after a late callback', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = await runtimeHarness({}, ({ item, state }) => {
        item.body.prependAsync = (html, _options, cb) => { state.prepends.push(html); state.callbacks.prepend = cb; };
    });
    const running = h.run();
    await flush(() => h.state.callbacks.prepend);
    assert.ok(h.state.callbacks.prepend);
    t.mock.timers.tick(compose.TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    await running;
    await h.run();
    assert.equal(compose.isTemplateInsertionBlocked(h.item), true);
    assert.deepEqual(h.state.signatures, ['']);
    h.state.body = h.state.prepends[0] + h.state.body;
    h.state.callbacks.prepend(success());
    await flush();
    await h.run();
    assert.equal(h.state.prepends.length, 1);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.session, '1');
});

test('manual selected template uses the common block with no native signature write after prepend', async () => {
    const h = await manualHarness({ composeType: 'reply', body: TYPED + OLD_SIGNATURE + QUOTE });
    await h.run();
    assert.equal(h.state.prepends.length, 1);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.body, h.state.prepends[0] + TYPED + QUOTE);
    assert.deepEqual(h.state.log.filter(value => /^(media:|native-|prepend)/.test(value)), ['media:train.gif', 'native-clear', 'prepend']);
    assert.equal(h.manual.statuses.at(-1)[1], 'Vorlage und Signatur gemeinsam eingefügt');
});

test('manual repeat cancellation leaves the edited document and quotes unchanged and writes no cleanup', async () => {
    const h = await manualHarness();
    await h.run();
    h.state.body = h.state.body.replace('<p><br></p>', '<p>Manual edits</p>');
    const edited = h.state.body;
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_CANCELLED' });
    assert.equal(h.manual.confirms, 1);
    assert.equal(h.state.body, edited);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.prepends.length, 1);
});

test('manual malformed combined fields fail closed despite valid separate native fields', async () => {
    const h = await manualHarness();
    h.document.combinedComposeMedia = null;
    await assert.rejects(h.run(), { code: 'COMBINED_TEMPLATE_INVALID' });
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.deepEqual(h.state.attachments, []);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
});

test('manual wrong sender prevents preparation or any content mutation', async () => {
    const h = await manualHarness();
    h.state.sender = 'other@company.example';
    await assert.rejects(h.run(), { code: 'SENDER_MISMATCH' });
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.deepEqual(h.state.attachments, []);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
});

test('definite common prepend failure does not fall back to native signature and permits only a guarded complete retry', async () => {
    const h = await runtimeHarness({ composeType: 'reply', body: TYPED + OLD_SIGNATURE + QUOTE }, ({ item, state }) => {
        item.body.prependAsync = (html, _options, cb) => { state.prepends.push(html); cb(failure('PREPEND_FAILED')); };
    });
    await h.run();
    assert.equal(h.state.body, TYPED + QUOTE);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.session, '');
    h.item.body.prependAsync = (html, _options, cb) => { h.state.prepends.push(html); h.state.body = html + h.state.body; cb(success()); };
    await h.run();
    assert.equal(h.state.prepends.length, 2);
    assert.equal(h.state.body, h.state.prepends[1] + TYPED + QUOTE);
    assert.deepEqual(h.state.signatures, ['', '']);
    assert.deepEqual(h.state.attachments, ['train.gif']);
    assert.equal(h.state.session, '1');
});

test('manual native cleanup uncertainty prevents prepend and cannot be overridden by repeat confirmation', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = await manualHarness();
    h.item.body.setSignatureAsync = (html, _options, cb) => { h.state.signatures.push(html); h.state.callbacks.clear = cb; };
    const outcome = h.run().then(() => null, error => error);
    await flush(() => h.state.callbacks.clear);
    assert.ok(h.state.callbacks.clear);
    t.mock.timers.tick(30000);
    assert.equal((await outcome).code, 'SIGNATURE_INSERT_UNCERTAIN');
    h.manual.confirmed = true;
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    h.state.callbacks.clear(success());
    await flush();
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    assert.deepEqual(h.state.signatures, ['']);
    assert.deepEqual(h.state.prepends, []);
    assert.equal(h.manual.confirms, 0);
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
});

test('manual common prepend uncertainty cannot cause any late native signature replacement', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = await manualHarness({ composeType: 'forward', body: TYPED + OLD_SIGNATURE + QUOTE });
    h.item.body.prependAsync = (html, _options, cb) => { h.state.prepends.push(html); h.state.callbacks.prepend = cb; };
    const outcome = h.run().then(() => null, error => error);
    await flush(() => h.state.callbacks.prepend);
    assert.ok(h.state.callbacks.prepend);
    t.mock.timers.tick(compose.TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    assert.equal((await outcome).code, 'TEMPLATE_INSERT_UNCERTAIN');
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.body, TYPED + QUOTE);
    h.state.body = h.state.prepends[0] + h.state.body;
    h.state.callbacks.prepend(success());
    await flush();
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_CANCELLED' });
    assert.equal(h.state.prepends.length, 1);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.body, h.state.prepends[0] + TYPED + QUOTE);
});

test('explicit additional manual insertion runs cleanup only after approval and preserves the existing complete block', async () => {
    const h = await manualHarness({ composeType: 'reply', body: TYPED + OLD_SIGNATURE + QUOTE });
    await h.run();
    const firstBody = h.state.body;
    h.manual.confirmed = true;
    await h.run();
    assert.equal(h.manual.confirms, 1);
    assert.equal(h.state.prepends.length, 2);
    assert.deepEqual(h.state.signatures, ['', '']);
    assert.equal(h.state.body, h.state.prepends[1] + firstBody);
    assert.deepEqual(h.state.attachments, ['train.gif']);
});

test('manual mobile path rejects unsupported common insertion before attaching any unused media', async () => {
    const h = await manualHarness({ mobile: true });
    await assert.rejects(h.run(), { code: 'TEMPLATE_PREPEND_UNAVAILABLE' });
    assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
    assert.deepEqual(h.state.attachments, []);
});

test('legacy cached payload without the additive contract retains its old template-then-native-signature path', async () => {
    const h = await runtimeHarness({}, ({ bootstrap }) => {
        delete bootstrap.templates[0].composeDocumentMode;
        delete bootstrap.templates[0].combinedComposeHtml;
        delete bootstrap.templates[0].combinedComposeMedia;
    });
    await h.run();
    assert.equal(h.state.prepends.length, 1);
    assert.equal(h.state.prepends[0], NATIVE_HTML);
    assert.deepEqual(h.state.signatures, [PAIRED_SIGNATURE]);
    assert.deepEqual(h.state.log.filter(value => /^(native-|prepend)/.test(value)), ['prepend', 'native-signature']);
    assert.doesNotMatch(h.state.body, /data-rt-compose-document/);
});

test('missing native-cleanup or inline-attachment API fails before any combined preparation', async () => {
    for (const unavailable of ['setSignatureAsync', 'addFileAttachmentFromBase64Async']) {
        const h = await runtimeHarness({}, ({ item }) => {
            if (unavailable === 'setSignatureAsync') delete item.body[unavailable];
            else delete item[unavailable];
        });
        await h.run();
        assert.equal(h.state.body, TYPED + OLD_SIGNATURE);
        assert.deepEqual(h.state.signatures, []);
        assert.deepEqual(h.state.prepends, []);
        assert.deepEqual(h.state.attachments, []);
        assert.deepEqual(h.state.sessionWrites, []);
        assert.ok(h.logs.some(value => value.includes('COMPOSE_API_UNAVAILABLE')));
    }
});

test('oversized original body is not cleared or reserialized to accommodate the common document', async () => {
    const original = TYPED + OLD_SIGNATURE + 'x'.repeat(compose.TEMPLATE_INSERT_LIMITS.bodyLength);
    const h = await runtimeHarness({ body: original });
    await h.run();
    assert.equal(h.state.body, original);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
    assert.deepEqual(h.state.attachments, []);
    assert.deepEqual(h.state.sessionWrites, []);
});

test('duplicate native-cleanup callbacks are ignored and cannot repeat the common document insertion', async () => {
    const h = await runtimeHarness({}, ({ item, state }) => {
        const original = item.body.setSignatureAsync;
        item.body.setSignatureAsync = (html, options, cb) => {
            state.callbacks.clear = cb;
            original(html, options, cb);
            cb(failure('LATE_DUPLICATE'));
            cb(success());
        };
    });
    await h.run();
    h.state.callbacks.clear(success());
    await flush();
    assert.equal(h.state.prepends.length, 1);
    assert.deepEqual(h.state.signatures, ['']);
    assert.equal(h.state.body, h.state.prepends[0] + TYPED);
});
