import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import * as compose from '../../resources/js/outlook-addin/compose-template.js';
import * as guard from '../../resources/js/outlook-addin/mailbox-guard.js';
import * as writes from '../../resources/js/outlook-addin/office-write.js';
import * as diagnostics from '../../resources/js/outlook-addin/diagnostics.js';
import * as policy from '../../resources/js/outlook-addin/automatic-compose-policy.js';
import { nativeOfficeHtml, officeCarrier, standaloneHtml } from './outlook-standalone-fixture.js';

const source = (await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8'))
    .replace(/^import\s[\s\S]*?\sfrom\s['"][^'"]+['"];\r?$/gm, '');
const succeeded = value => ({ status: 'succeeded', value });
const ADDRESS = 'employee@company.example';
const MARKER = 'RT-SIGNATURE-MANAGED-V1';
const VERSION = '0123456789abcdef';
const NATIVE = `<!--${compose.NATIVE_TEMPLATE_MARKER}--><p>Guten Tag,</p><p>Mit freundlichen Grüßen,</p><img src="cid:template-cid" width="44" height="44">`;
const SIGNATURE = `<!--${MARKER}--><!--RT-SIGNATURE-VERSION:${VERSION}--><p>Personal signature</p><img src="cid:signature-cid" width="180" height="30">`;
const COMBINED = `<div data-rt-compose-document="combined-v1"><!--${compose.COMBINED_TEMPLATE_MARKER}--><p>Guten Tag,</p><p>Mit freundlichen Grüßen,</p>${SIGNATURE}</div>`;
const MOBILE = COMBINED.replace('combined-v1', 'combined-native-v1') + `<!--RT-MOBILE-COMPOSE-VERSION:${VERSION}-->`;
const TYPED = '<p>Already typed &amp; <b>kept byte-identical</b>.</p>';
const QUOTED = '<div id="divRplyFwdMsg"><blockquote><p>Original quoted message.</p></blockquote></div>';
const HOST_SIGNATURE = '<div data-host-signature="1">Existing Outlook-owned signature.</div>';
const media = name => ({ name: `${name}.png`, contentId: `${name}-cid`, base64: 'TQ==' });
const MODES = { both: [true, true], signature: [true, false], template: [false, true], neither: [false, false] };
const flush = async until => { for (let index = 0; index < 250 && !until(); index += 1) await Promise.resolve(); };

function payloadFor(mode, composeType) {
    const [signature, template] = MODES[mode];
    const payload = {
        marker: MARKER, automaticInsertionEnabled: signature || template,
        automaticInsertion: { schema: 2, composeType, signature, template },
        binding: { schema: 1, mailboxAddress: ADDRESS, senderAddress: ADDRESS, allowedSenderAddresses: [ADDRESS] },
    };
    if (signature) payload.signature = { html: SIGNATURE, media: [media('signature')] };
    if (template) {
        payload.automaticTemplateId = 'template-a';
        payload.templates = [{ id: 'template-a', key: 'template-a', isDefault: true, signatureMode: 'native',
            composeHtml: NATIVE, composeMedia: [media('template')],
            ...(signature ? { html: '<p>Legacy full document; forbidden as template-only fallback.</p>', media: [],
                composeDocumentMode: 'combined-v1', combinedComposeHtml: COMBINED, combinedComposeMedia: [media('signature')],
                mobileComposeDocumentMode: 'combined-native-v1', mobileComposeHtml: MOBILE, mobileComposeMedia: [media('signature')],
                mobileComposeVersion: VERSION, signature: { html: SIGNATURE, media: [media('signature')] } } : {}),
        }];
    } else if (signature) {
        payload.automaticTemplateId = null;
        payload.templates = [];
    }
    return payload;
}

function harness({ mode = 'both', composeType = 'newMail', mobile = false, holdNative = false, session = '' } = {}, mutate = () => {}) {
    const state = { body: TYPED + HOST_SIGNATURE + (composeType === 'newMail' ? '' : QUOTED), native: HOST_SIGNATURE,
        bodyReads: 0, media: [], prepends: [], signatures: [], replacements: [], session, requests: [], callback: null };
    const item = {
        from: { getAsync: callback => callback(succeeded({ emailAddress: ADDRESS })) },
        getComposeTypeAsync: callback => callback(succeeded({ composeType, coercionType: 'html' })),
        sessionData: {
            getAsync: (_key, callback) => callback(succeeded(state.session)),
            setAsync(_key, value, callback) { state.session = value; callback(succeeded()); },
        },
        getAttachmentsAsync: callback => callback(succeeded(state.media.map(name => ({ name, isInline: true })))),
        addFileAttachmentFromBase64Async(_base64, name, _options, callback) { state.media.push(name); callback(succeeded(name)); },
        body: {
            getTypeAsync(callback) { state.bodyReads += 1; callback(succeeded('html')); },
            getAsync(_type, options, complete) {
                state.bodyReads += 1;
                const callback = typeof options === 'function' ? options : complete;
                callback(succeeded(options?.bodyMode === 1 ? state.body.split('<div id="divRplyFwdMsg">')[0] : state.body));
            },
            prependAsync(html, _options, callback) { state.prepends.push(html); state.body = html + state.body; callback(succeeded()); },
            setSignatureAsync(html, _options, callback) {
                state.signatures.push(html);
                const finish = () => {
                    if (state.native) state.body = state.body.replace(state.native, '');
                    state.native = html;
                    state.body = html + state.body;
                    callback(succeeded());
                };
                if (holdNative) state.callback = finish;
                else finish();
            },
            setAsync(html, _options, callback) { state.replacements.push(html); callback(succeeded()); },
        },
    };
    const office = {
        AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        MailboxEnums: { BodyMode: { HostConfig: 1 } }, onReady() {}, actions: { associate() {} },
        context: { platform: mobile ? 'iOS' : 'PC', requirements: { isSetSupported: () => true },
            mailbox: { item, userProfile: { emailAddress: ADDRESS },
                diagnostics: { hostName: mobile ? 'OutlookIOS' : 'OutlookWebApp', hostVersion: mobile ? '5.2635.0' : '16.0' } } },
    };
    const payload = payloadFor(mode, composeType);
    mutate({ payload, item, office, state });
    const config = { marker: MARKER, ready: true, auth: { clientId: 'synthetic', authority: 'https://login.example.test/tenant', scopes: ['api://test/read'] },
        endpoints: { bootstrap: 'https://company.example/bootstrap' } };
    const context = vm.createContext({
        ...compose, ...guard, ...writes, ...diagnostics, ...policy, Office: office,
        URL, AbortController, setTimeout, clearTimeout, console: { info() {} },
        RAILTIME_OUTLOOK_CONFIG_URL: 'https://company.example/config',
        InteractionRequiredAuthError: class extends Error {},
        createNestablePublicClientApplication: async () => ({ getAllAccounts: () => [{ username: ADDRESS }], acquireTokenSilent: async () => ({ accessToken: 'synthetic' }) }),
        fetch: async (url, options) => {
            state.requests.push({ url, options });
            return { ok: true, json: async () => String(url).includes('/bootstrap') ? payload : config };
        },
    });
    vm.runInContext(source + '\nglobalThis.apply = applyPublishedContent;', context);
    return { state, item, office, payload, apply: () => context.apply(item) };
}

test('production automatic signature-only caller uses the restored personal native path without a template/body rewrite', async () => {
    const h = harness({ mode: 'signature' }, ({ payload, item, state }) => {
        payload.signature = { html: standaloneHtml(), media: [] };
        item.body.setSignatureAsync = (html, _options, callback) => {
            state.signatures.push(html); if (state.native) state.body = state.body.replace(state.native, '');
            state.native = nativeOfficeHtml(html); state.body = state.native + state.body; callback(succeeded());
        };
        item.body.prependAsync = (html, _options, callback) => { state.prepends.push(html); state.body = officeCarrier(html) + state.body; callback(succeeded()); };
    });
    assert.equal(await h.apply(), 'applied');
    assert.equal(h.state.signatures.length, 1); assert.equal(h.state.prepends.length, 1);
    assert.match(h.state.prepends[0], /RT-NATIVE-LAYOUT-RESTORE-V1/);
    assert.deepEqual(h.state.replacements, []); assert.ok(h.state.body.includes(TYPED));
    assert.equal(compose.standaloneSignatureLayoutIsCurrent(h.state.body, h.state.body, VERSION), true);
});

test('legacy separate native template plus personal signature retains its established two-part route', async () => {
    const h = harness({ mode: 'both' }, ({ payload }) => {
        delete payload.templates[0].composeDocumentMode;
        payload.templates[0].signature = { html: standaloneHtml(), media: [] };
    });
    assert.equal(await h.apply(), 'applied');
    assert.equal(h.state.prepends.length, 1); assert.match(h.state.prepends[0], /NATIVE-SIGNATURE/);
    assert.equal(h.state.signatures.length, 1); assert.match(h.state.signatures[0], /data-rt-personal-flow/);
    assert.doesNotMatch(h.state.signatures[0], /id="rt-native-layout-/);
    assert.ok(h.state.body.includes(TYPED));
});

for (const mobile of [false, true]) for (const composeType of ['newMail', 'reply', 'forward']) for (const mode of Object.keys(MODES)) {
    test(`v2 ${mobile ? 'mobile' : 'desktop'} ${composeType} ${mode} routes only authorized artifacts`, async () => {
        const h = harness({ mode, composeType, mobile });
        const result = await h.apply();
        assert.equal(result, mode === 'neither' ? 'disabled' : 'applied');
        const request = h.state.requests.find(entry => entry.url.includes('/bootstrap'));
        assert.equal(request.options.headers['X-RailTime-Automatic-Policy'], '2');
        assert.equal(request.options.headers['X-RailTime-Compose-Type'], composeType);
        assert.equal(request.options.headers['X-RailTime-Outlook-Context'], 'event');
        assert.deepEqual(h.state.replacements, [], 'automatic insertion never replaces the entire body');
        assert.ok(h.state.body.includes(TYPED));
        if (composeType !== 'newMail') assert.ok(h.state.body.includes(QUOTED));
        if (mode === 'neither') {
            assert.equal(h.state.bodyReads, 0);
            assert.deepEqual(h.state.media, []);
            assert.deepEqual(h.state.signatures, []);
            assert.deepEqual(h.state.prepends, []);
        } else if (mode === 'signature') {
            assert.deepEqual(h.state.media, ['signature.png']);
            assert.deepEqual(h.state.signatures, [SIGNATURE.replaceAll('cid:signature-cid', 'cid:signature.png')]);
            assert.deepEqual(h.state.prepends, []);
            assert.ok(!h.state.body.includes('Guten Tag,'));
        } else if (mode === 'template') {
            assert.deepEqual(h.state.media, ['template.png']);
            const inserted = mobile ? h.state.signatures : h.state.prepends;
            assert.equal(inserted.length, 1);
            assert.ok(inserted[0].includes('Guten Tag,'));
            assert.ok(!inserted[0].includes(MARKER));
            assert.ok(!inserted[0].includes('Personal signature'));
            if (mobile) {
                assert.match(inserted[0], /data-rt-compose-document="template-native-v1"/);
                assert.deepEqual(h.state.prepends, []);
            } else {
                assert.deepEqual(h.state.signatures, [], 'desktop template-only never clears or rewrites a signature');
                assert.ok(h.state.body.includes(HOST_SIGNATURE));
            }
        } else {
            assert.deepEqual(h.state.media, ['signature.png']);
            if (mobile) {
                assert.equal(h.state.signatures.length, 1);
                assert.match(h.state.signatures[0], /combined-native-v1/);
                assert.deepEqual(h.state.prepends, []);
            } else {
                assert.deepEqual(h.state.signatures, ['']);
                assert.equal(h.state.prepends.length, 1);
                assert.match(h.state.prepends[0], /combined-v1/);
            }
        }
    });
}

test('compose reader uses only supported API capabilities and converts missing, failure, throw, invalid and timeout to unknown', async () => {
    const base = { AsyncResultStatus: { Succeeded: 'succeeded' }, context: { requirements: { isSetSupported: () => true } } };
    for (const item of [{}, { getComposeTypeAsync: callback => callback({ status: 'failed' }) },
        { getComposeTypeAsync() { throw new Error('unavailable'); } }, { getComposeTypeAsync: callback => callback(succeeded({ composeType: 'unknown' })) },
        { getComposeTypeAsync() {} }]) {
        assert.equal(await policy.readAutomaticComposeType(base, item, { timeoutMs: 2 }), 'unknown');
    }
    assert.equal(await policy.readAutomaticComposeType({ ...base, context: { requirements: { isSetSupported: () => false } } },
        { getComposeTypeAsync() { throw new Error('must not be invoked'); } }), 'unknown');
    for (const [hostVersion, expected] of [['4.2351.9', 'unknown'], ['4.2352.0', 'reply'], ['5.2635.0', 'reply'], ['missing', 'unknown']]) {
        const office = { ...base, context: { platform: 'iOS', requirements: { isSetSupported: () => false },
            mailbox: { diagnostics: { hostName: 'OutlookIOS', hostVersion } } } };
        assert.equal(await policy.readAutomaticComposeType(office, { getComposeTypeAsync: callback => callback(succeeded({ composeType: 'reply' })) }), expected);
    }
});

for (const mutate of [h => { delete h.payload.automaticInsertion; }, h => { h.payload.automaticInsertion.schema = 1; },
    h => { h.payload.automaticInsertion.composeType = 'reply'; }, h => { h.payload.automaticInsertion.signature = 'true'; },
    h => { h.payload.automaticInsertionEnabled = undefined; }, h => { delete h.item.getComposeTypeAsync; },
    h => { h.item.getComposeTypeAsync = callback => callback({ status: 'failed' }); },
    h => { h.item.getComposeTypeAsync = callback => callback(succeeded({ composeType: 'ambiguous' })); }]) {
    test('missing, ambiguous or mismatched compose policy fails closed before reading body or preparing media', async () => {
        const h = harness({}, mutate);
        assert.equal(await h.apply(), 'skipped');
        assert.equal(h.state.bodyReads, 0);
        assert.deepEqual(h.state.media, []);
        assert.deepEqual(h.state.signatures, []);
        assert.deepEqual(h.state.prepends, []);
    });
}

test('mobile template-only ownership preserves edited slot text even after its HTML markers disappear', async () => {
    const h = harness({ mode: 'template', mobile: true, session: '1' }, ({ state }) => { state.body = TYPED; state.native = ''; });
    assert.equal(await h.apply(), 'already-present');
    assert.equal(h.state.body, TYPED);
    assert.deepEqual(h.state.media, []);
    assert.deepEqual(h.state.signatures, []);
});

test('template-only mobile uses existing uncertain-write quarantine and a late success never starts another write', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = harness({ mode: 'template', mobile: true, holdNative: true });
    const operation = h.apply();
    await flush(() => h.state.callback !== null);
    assert.equal(h.state.signatures.length, 1);
    t.mock.timers.tick(30000);
    assert.equal(await operation, 'uncertain');
    assert.equal(await h.apply(), 'uncertain');
    h.state.callback();
    await flush(() => h.state.session === '1');
    assert.equal(h.state.signatures.length, 1);
    assert.deepEqual(h.state.prepends, []);
    assert.ok(h.state.body.includes(TYPED));
});

test('signature-free contracts reject embedded signatures, combined fallback and unknown mobile document modes', async () => {
    for (const composeHtml of [`${NATIVE}${SIGNATURE}`, `${NATIVE}${COMBINED}`, `${NATIVE}<!--RT-MOBILE-COMPOSE-VERSION:${VERSION}-->`]) {
        const h = harness({ mode: 'template', mobile: true }, ({ payload }) => { payload.templates[0].composeHtml = composeHtml; });
        await assert.rejects(h.apply(), { code: 'NATIVE_TEMPLATE_INVALID' });
        assert.deepEqual(h.state.media, []);
        assert.deepEqual(h.state.signatures, []);
    }
    assert.throws(() => compose.mobileTemplateOnlyComposeDocument({ signatureMode: 'native', composeHtml: NATIVE + 'x'.repeat(30000), composeMedia: [] }), { code: 'SIGNATURE_TOO_LARGE' });
});

test('a compose-type change after authenticated policy selection stops before the first mutation', async () => {
    let reads = 0;
    const h = harness({ mode: 'template' }, ({ item }) => {
        item.getComposeTypeAsync = callback => callback(succeeded({ composeType: ++reads === 1 ? 'newMail' : 'reply', coercionType: 'html' }));
    });
    assert.equal(await h.apply(), 'skipped');
    assert.deepEqual(h.state.media, []);
    assert.deepEqual(h.state.signatures, []);
    assert.deepEqual(h.state.prepends, []);
});

test('ambiguous defaults and missing signature-free artifacts never fall back to a disabled signature', async () => {
    const duplicate = harness({ mode: 'template' }, ({ payload }) => { payload.templates.push({ ...payload.templates[0] }); });
    assert.equal(await duplicate.apply(), 'skipped');
    assert.deepEqual(duplicate.state.media, []);
    const missing = harness({ mode: 'template' }, ({ payload }) => { delete payload.templates[0].composeHtml; });
    await assert.rejects(missing.apply(), { code: 'NATIVE_TEMPLATE_INVALID' });
    assert.deepEqual(missing.state.signatures, []);
    assert.deepEqual(missing.state.media, []);
});
