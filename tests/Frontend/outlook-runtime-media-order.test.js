import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { confirmedOfficeWrite, hasUncertainWrite, wasSignatureWriteConfirmed } from '../../resources/js/outlook-addin/office-write.js';
import { hasMobileTemplateOwnership } from '../../resources/js/outlook-addin/compose-template.js';

const source = await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8');

function sourceBetween(start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first);
    assert.ok(first >= 0 && last > first, `Runtime fixture anchors: ${start}`);
    return source.slice(first, last);
}

// Execute the actual narrow runtime/attachment functions without MSAL, a build,
// browser globals, public bundle writes, network requests, or a real mailbox.
const implementation = sourceBetween('function addInlineAttachment(', 'function completeOnce(')
    + sourceBetween('async function applyPublishedContent(', '// Mobile has no compose taskpane.');
const media = (name) => ({ name, base64: 'AA==', contentId: name });

function harness(options = {}) {
    const log = [];
    const attachments = new Set(options.existing ?? []);
    const globalSignature = { html: '<p>Global signature</p>', media: [media('global.png')] };
    const signature = { html: '<p>Paired signature</p>', media: [media('signature-logo.png'), media('train.gif')] };
    const template = { html: '<p>Guten Tag,</p>', media: [media('template-mark.gif'), media('template-mark.png')] };
    const selected = options.noDefault ? null : { signature, compose: template };
    const binding = { mailboxAddress: 'pilot@example.test', senderAddress: 'pilot@example.test' };
    const bootstrap = { binding, signature: globalSignature };
    let present = false;
    let signatureCalls = 0;
    const item = {
        sessionData: { getAsync(_key, cb) { cb({ status: 'failed', error: { code: '9050' } }); } },
        getAttachmentsAsync(callback) {
            log.push('attachment-enumeration');
            callback({ status: 'succeeded', value: [...attachments].map((name) => ({ name, isInline: true })) });
        },
        addFileAttachmentFromBase64Async(_bytes, name, _options, callback) {
            log.push(`attachment:${name}`);
            if (options.uncertainAttachment === name) throw { code: 'HOST_BRIDGE_UNCERTAIN' };
            attachments.add(name);
            callback({ status: 'succeeded', value: name });
            if (options.itemChangesAfterAttachment === name) office.context.mailbox.item = {};
        },
        body: {
            setSignatureAsync(html, _options, callback) {
                log.push(`signature:${html}`);
                signatureCalls++;
                callback(options.firstSignatureFails && signatureCalls === 1
                    ? { status: 'failed', error: { code: '5001' } }
                    : { status: 'succeeded' });
            },
        },
    };
    const office = {
        context: { platform: options.mobile ? 'iOS' : 'PC', mailbox: { item } },
        AsyncResultStatus: { Succeeded: 'succeeded' },
        CoercionType: { Html: 'html' },
    };
    const context = vm.createContext({
        Office: office, setTimeout, clearTimeout,
        console: { info() {} }, LOG_PREFIX: '[fixture]', AUTH_TIMEOUT_MS: 15000,
        confirmedInlineMedia: new WeakMap(), pendingNativeSignatures: new WeakMap(),
        confirmedOfficeWrite, hasUncertainWrite, wasSignatureWriteConfirmed, hasMobileTemplateOwnership,
        isMobileComposeHost: () => options.mobile === true,
        codedError: (code) => Object.assign(new Error(code), { code }),
        safeErrorCode: (error) => error?.code || 'UNAVAILABLE',
        reportRuntimePhase() {}, recordDiagnostic() {},
        diagnoseStep: (_phase, operation) => operation(),
        withDeadline: (operation) => operation(),
        loadConfig: async () => ({ marker: 'RT-SIGNATURE-MANAGED-V1' }),
        acquireTokenSilently: async () => 'fixture-token',
        loadBootstrap: async () => bootstrap,
        assertMailboxBinding: async (_office, target, actual) => {
            assert.equal(target, item);
            assert.equal(actual, binding);
            log.push('binding-check');
        },
        isTemplateInsertionBlocked: () => false,
        readTemplateState: async () => ({ readable: true, present, legacySignatureEmbedded: false }),
        supportsTemplatePrepend: () => !options.mobile,
        automaticTemplate: () => selected,
        nativeComposeTemplate: (entry) => entry.compose,
        validatedDocument: (document) => document,
        validateTemplateInsertionPayload: (_html, documents) => {
            log.push(`validated:${documents.map(({ name }) => name).join(',')}`);
        },
        prependTemplate: async (_office, target, html, assertTarget, prepare) => {
            assert.equal(target, item);
            assert.equal(html, template.html);
            log.push(`claimed:${prepare.media.map(({ name }) => name).join(',')}`);
            await assertTarget();
            await prepare.beforeInsert();
            await assertTarget();
            log.push('template-body-write');
            if (options.uncertainTemplate) throw { code: 'TEMPLATE_INSERT_UNCERTAIN' };
            present = true;
        },
    });
    vm.runInContext(implementation + '\nglobalThis.apply = applyPublishedContent;', context);
    return { apply: () => context.apply(item), log, signature, template, globalSignature };
}

test('automatic native template prepares ALL paired/template media before its first body write; signature is last', async () => {
    const fixture = harness();
    assert.equal(await fixture.apply(), 'applied');
    const names = [...fixture.signature.media, ...fixture.template.media].map(({ name }) => name);
    const firstBody = fixture.log.indexOf('template-body-write');
    assert.ok(firstBody > 0);
    assert.ok(fixture.log.includes(`claimed:${names.join(',')}`));
    assert.deepEqual(fixture.log.filter((entry) => entry.startsWith('attachment:')), names.map((name) => `attachment:${name}`));
    for (const name of names) assert.ok(fixture.log.indexOf(`attachment:${name}`) < firstBody);
    assert.deepEqual(fixture.log.filter((entry) => entry === 'template-body-write' || entry.startsWith('signature:')),
        ['template-body-write', `signature:${fixture.signature.html}`]);
    assert.ok(!fixture.log.includes(`signature:${fixture.globalSignature.html}`));
});

test('existing inline media are reused, with no image upload after the template becomes visible', async () => {
    const fixture = harness({ existing: ['signature-logo.png'] });
    assert.equal(await fixture.apply(), 'applied');
    const firstBody = fixture.log.indexOf('template-body-write');
    assert.ok(!fixture.log.includes('attachment:signature-logo.png'));
    assert.ok(!fixture.log.slice(firstBody + 1).some((entry) => entry.startsWith('attachment:')));
});

test('mobile retains paired-signature-only delivery and never prepares unused template media', async () => {
    const fixture = harness({ mobile: true });
    assert.equal(await fixture.apply(), 'applied');
    assert.deepEqual(fixture.log.filter((entry) => entry.startsWith('attachment:')),
        fixture.signature.media.map(({ name }) => `attachment:${name}`));
    assert.ok(!fixture.log.includes('template-body-write'));
    assert.equal(fixture.log.at(-1), `signature:${fixture.signature.html}`);
});

test('signature-only delivery without a default template still uses only the global signature media', async () => {
    const fixture = harness({ noDefault: true });
    assert.equal(await fixture.apply(), 'applied');
    assert.deepEqual(fixture.log.filter((entry) => entry.startsWith('attachment:')), ['attachment:global.png']);
    assert.ok(!fixture.log.includes('template-body-write'));
    assert.equal(fixture.log.at(-1), `signature:${fixture.globalSignature.html}`);
});

test('uncertain attachment preparation starts neither template nor signature body write', async () => {
    const fixture = harness({ uncertainAttachment: 'train.gif' });
    assert.equal(await fixture.apply(), 'uncertain');
    assert.ok(!fixture.log.includes('template-body-write'));
    assert.ok(!fixture.log.some((entry) => entry.startsWith('signature:')));
    assert.equal(fixture.log.filter((entry) => entry === 'attachment:train.gif').length, 1);
});

test('uncertain template result never starts the later native signature write', async () => {
    const fixture = harness({ uncertainTemplate: true });
    assert.equal(await fixture.apply(), 'uncertain');
    assert.equal(fixture.log.filter((entry) => entry.startsWith('attachment:')).length, 4);
    assert.equal(fixture.log.filter((entry) => entry === 'template-body-write').length, 1);
    assert.ok(!fixture.log.some((entry) => entry.startsWith('signature:')));
});

test('an item change during media preparation aborts before either body write', async () => {
    const fixture = harness({ itemChangesAfterAttachment: 'signature-logo.png' });
    await assert.rejects(fixture.apply(), { code: 'ITEM_CHANGED' });
    assert.ok(!fixture.log.includes('template-body-write'));
    assert.ok(!fixture.log.some((entry) => entry.startsWith('signature:')));
});

test('a definite signature failure retains paired retry ownership and reuses all prepared images', async () => {
    const fixture = harness({ firstSignatureFails: true });
    await assert.rejects(fixture.apply(), { code: 'OFFICE_WRITE_FAILED' });
    assert.equal(await fixture.apply(), 'applied');
    assert.equal(fixture.log.filter((entry) => entry.startsWith('attachment:')).length, 4);
    assert.equal(fixture.log.filter((entry) => entry === 'template-body-write').length, 1);
    assert.deepEqual(fixture.log.filter((entry) => entry.startsWith('signature:')),
        [`signature:${fixture.signature.html}`, `signature:${fixture.signature.html}`]);
});
