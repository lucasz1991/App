import assert from 'node:assert/strict';
import test from 'node:test';
import {
    isTemplateInsertionBlocked,
    markedTemplateHtml,
    nativeComposeTemplate,
    NATIVE_TEMPLATE_MARKER,
    prependTemplate,
    readTemplateState,
    templateStateFromBody,
    validateTemplateInsertionPayload,
    TEMPLATE_INSERT_LIMITS,
    TEMPLATE_MARKER,
} from '../../resources/js/outlook-addin/compose-template.js';

const success = (value) => ({ status: 'succeeded', value });
const rejectedCode = (promise) => promise.then(() => null, (error) => error);
const flush = async (until = () => false) => {
    for (let index = 0; index < 120 && !until(); index += 1) await Promise.resolve();
};

const nativeHtml = `<!-- ${NATIVE_TEMPLATE_MARKER} --><p>Template body only</p><span id="${NATIVE_TEMPLATE_MARKER}"></span>`;

test('native compose projection selects only explicit compose fields and leaves full legacy document unchanged', () => {
    const legacyMedia = [{ base64: 'TQ==' }];
    const composeMedia = [{ base64: 'Tg==' }];
    const original = Object.freeze({ id: 'template-a', signatureMode: 'native',
        html: '<p>Legacy with embedded signature</p>', media: legacyMedia,
        composeHtml: nativeHtml, composeMedia });
    const selected = nativeComposeTemplate(original);
    assert.notEqual(selected, original);
    assert.equal(selected.html, nativeHtml);
    assert.equal(selected.media, composeMedia);
    assert.equal(selected.signatureMode, 'native');
    assert.equal(selected.id, original.id);
    assert.equal(original.html, '<p>Legacy with embedded signature</p>');
    assert.equal(original.media, legacyMedia);
    assert.equal(Object.isFrozen(selected), true);
});

test('legacy documents have no native projection and explicit unknown modes never fall back', () => {
    assert.equal(nativeComposeTemplate({ html: '<p>Legacy</p>', media: [] }), null);
    assert.equal(nativeComposeTemplate(null), null);
    for (const signatureMode of [null, '', 'embedded', 'NATIVE', 'unknown']) {
        assert.throws(() => nativeComposeTemplate({ signatureMode, composeHtml: nativeHtml, composeMedia: [] }),
            { code: 'NATIVE_TEMPLATE_INVALID' });
    }
});

test('native projection fails closed for missing, malformed, mixed or oversized compose artifacts', () => {
    const valid = { signatureMode: 'native', html: '<p>Do not use fallback</p>', media: [],
        composeHtml: nativeHtml, composeMedia: [] };
    const invalid = [
        { composeHtml: undefined }, { composeHtml: '' }, { composeHtml: '<p>Missing marker</p>' },
        { composeHtml: `<!--${NATIVE_TEMPLATE_MARKER}-OTHER-->` },
        { composeHtml: `${nativeHtml}<!--${TEMPLATE_MARKER}-->` },
        { composeHtml: `${nativeHtml}${'x'.repeat(TEMPLATE_INSERT_LIMITS.htmlLength)}` },
        { composeMedia: undefined }, { composeMedia: {} }, { composeMedia: [null] },
        { composeMedia: [{ base64: 'not base64!' }] },
    ];
    for (const changes of invalid) {
        assert.throws(() => nativeComposeTemplate({ ...valid, ...changes }), { code: 'NATIVE_TEMPLATE_INVALID' });
    }
});

test('native marker retains the old idempotency prefix without adding an embedded signature marker', () => {
    assert.equal(nativeHtml.includes(TEMPLATE_MARKER), true);
    assert.equal(markedTemplateHtml(nativeHtml), nativeHtml);
    for (const html of [nativeHtml, `<!--${NATIVE_TEMPLATE_MARKER}-->`, `<span id='${NATIVE_TEMPLATE_MARKER}'></span>`]) {
        const state = templateStateFromBody(html, 'newMail');
        assert.equal(state.present, true);
        assert.equal(state.legacySignatureEmbedded, false);
    }
});

test('only template markers classify embedded signatures; separate current-message signature markers are allowed', () => {
    const signature = '<!--RT-SIGNATURE-MANAGED-V1--><!--RT-SIGNATURE-VERSION:0123456789abcdef-->';
    assert.equal(templateStateFromBody(nativeHtml + signature, 'newMail').legacySignatureEmbedded, false);
    assert.equal(templateStateFromBody(signature, 'newMail').present, false);
    assert.equal(templateStateFromBody(signature, 'newMail').legacySignatureEmbedded, false);
    for (const html of [
        `<!--${TEMPLATE_MARKER}-->`, `${nativeHtml}<!--${TEMPLATE_MARKER}-->`,
        `<!--${NATIVE_TEMPLATE_MARKER}-OTHER-->`, `<!--${NATIVE_TEMPLATE_MARKER}:OTHER-->`,
    ]) {
        assert.equal(templateStateFromBody(html, 'newMail').legacySignatureEmbedded, true);
    }
});

test('native state scopes known replies but treats ambiguous quote boundaries conservatively', () => {
    const oldQuote = `<div id="divRplyFwdMsg"><!--${TEMPLATE_MARKER}-->Old mail</div>`;
    assert.equal(templateStateFromBody(nativeHtml + oldQuote, 'reply').legacySignatureEmbedded, false);
    assert.equal(templateStateFromBody(nativeHtml + oldQuote, 'forward').legacySignatureEmbedded, false);
    assert.equal(templateStateFromBody(`<p>New reply</p>${oldQuote}`, 'reply').present, false);
    assert.equal(templateStateFromBody(nativeHtml, 'reply').legacySignatureEmbedded, true);
    assert.equal(templateStateFromBody(nativeHtml, null).legacySignatureEmbedded, true);
    assert.equal(templateStateFromBody(null, 'newMail').legacySignatureEmbedded, true);
});

test('pure insertion preflight uses the existing validator without rewriting already marked native HTML', () => {
    assert.equal(validateTemplateInsertionPayload(nativeHtml, []), nativeHtml);
    assert.equal(validateTemplateInsertionPayload('<p>Legacy</p>', []), markedTemplateHtml('<p>Legacy</p>'));
    assert.throws(() => validateTemplateInsertionPayload(nativeHtml, [{ base64: 'invalid!' }]),
        { code: 'TEMPLATE_MEDIA_INVALID' });
    assert.throws(() => validateTemplateInsertionPayload('x'.repeat(TEMPLATE_INSERT_LIMITS.htmlLength + 1), []),
        { code: 'TEMPLATE_TOO_LARGE' });
});

function fixture({ supported = true, session = '' } = {}) {
    const state = {
        session, sessionReads: 0, sessionWrites: [], bodyReads: 0,
        mediaWrites: 0, prepends: [], requirements: [], html: '<p>Original text</p>',
    };
    const item = {
        sessionData: {
            getAsync(_key, callback) {
                state.sessionReads += 1;
                callback(success(state.session));
            },
            setAsync(_key, value, callback) {
                state.sessionWrites.push(value);
                state.session = value;
                callback(success());
            },
        },
        body: {
            getAsync(_format, callback) {
                state.bodyReads += 1;
                callback(success(state.html));
            },
            getTypeAsync(callback) { callback(success('html')); },
            prependAsync(html, _options, callback) {
                state.prepends.push(html);
                state.html = html + state.html;
                callback(success());
            },
        },
        getComposeTypeAsync(callback) { callback(success({ composeType: 'newMail' })); },
    };
    const office = {
        AsyncResultStatus: { Succeeded: 'succeeded' },
        CoercionType: { Html: 'html' },
        context: {
            platform: 'PC',
            mailbox: { item },
            requirements: {
                isSetSupported(name, version) {
                    state.requirements.push([name, version]);
                    return supported;
                },
            },
        },
    };
    const media = () => { state.mediaWrites += 1; };
    return { office, item, state, media };
}

test('session-only presence stays conservative until forceBody confirms native template ownership', async () => {
    const { office, item, state } = fixture({ session: '1' });
    state.html = nativeHtml;
    const quick = await readTemplateState(office, item);
    assert.equal(quick.present, true);
    assert.equal(quick.legacySignatureEmbedded, true);
    assert.equal(state.bodyReads, 0);
    const verified = await readTemplateState(office, item, { forceBody: true });
    assert.equal(verified.present, true);
    assert.equal(verified.legacySignatureEmbedded, false);
    assert.equal(state.bodyReads, 1);
    state.html = '<p>Marker removed by client</p>';
    const missingMarker = await readTemplateState(office, item, { forceBody: true });
    assert.equal(missingMarker.present, true);
    assert.equal(missingMarker.legacySignatureEmbedded, true);
    assert.deepEqual(state.sessionWrites, []);
});

test('native template keeps duplicate protection and separate signature ownership after a confirmed prepend', async () => {
    const { office, item, state } = fixture();
    await prependTemplate(office, item, nativeHtml);
    assert.equal(state.session, '1');
    assert.equal((await readTemplateState(office, item)).legacySignatureEmbedded, true);
    const actual = await readTemplateState(office, item, { forceBody: true });
    assert.equal(actual.present, true);
    assert.equal(actual.legacySignatureEmbedded, false);
    await assert.rejects(prependTemplate(office, item, nativeHtml), { code: 'TEMPLATE_ALREADY_INSERTED' });
    assert.equal(state.prepends.length, 1);
});

test('unconfirmed signature write during preparation preserves the same pending template claim', async () => {
    const { office, item, state } = fixture();
    await assert.rejects(prependTemplate(office, item, nativeHtml, undefined, {
        beforeInsert: () => { throw Object.assign(new Error('Uncertain signature'), { code: 'SIGNATURE_INSERT_UNCERTAIN' }); },
    }), { code: 'SIGNATURE_INSERT_UNCERTAIN' });
    assert.match(state.session, /^pending:/);
    assert.equal(state.sessionWrites.length, 1);
    assert.equal(state.prepends.length, 0);
    assert.equal(isTemplateInsertionBlocked(item), true);
    await assert.rejects(prependTemplate(office, item, nativeHtml), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    const reopened = await import('../../resources/js/outlook-addin/compose-template.js?native-signature-pending');
    assert.equal((await reopened.readTemplateState(office, item)).uncertain, true);
});

test('Mailbox 1.10 never calls existing unsupported SessionData stubs and keeps local duplicate protection', async () => {
    const { office, item, state, media } = fixture({ supported: false });
    item.sessionData.getAsync = item.sessionData.setAsync = () => {
        throw new Error('Unsupported stub must not be invoked');
    };
    await prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media });
    assert.ok(state.requirements.length > 0);
    assert.ok(state.requirements.every(([name, version]) => name === 'Mailbox' && version === '1.11'));
    assert.equal(state.bodyReads, 1);
    assert.equal(state.mediaWrites, 1);
    assert.equal(state.prepends.length, 1);
    assert.deepEqual(state.sessionWrites, []);
    await assert.rejects(prependTemplate(office, item, '<p>Duplicate</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });
});

test('failed initial session read is accurately diagnosed, writes nothing and permits a safe retry', async () => {
    const { office, item, state, media } = fixture();
    const originalRead = item.sessionData.getAsync;
    item.sessionData.getAsync = (_key, callback) => callback({
        status: 'failed', error: { code: 9018, message: 'Private host details must not be exposed' },
    });
    const snapshot = await readTemplateState(office, item);
    assert.equal(snapshot.errorCode, 'COMPOSE_SESSION_UNREADABLE');
    assert.equal(snapshot.uncertain, false);
    assert.equal(snapshot.officeCode, '9018');
    const error = await rejectedCode(prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media }));
    assert.equal(error.code, 'COMPOSE_SESSION_UNREADABLE');
    assert.equal(error.phase, 'session-read');
    assert.equal(error.reason, 'failed');
    assert.equal(error.officeCode, '9018');
    assert.doesNotMatch(JSON.stringify(error), /Private host/);
    assert.equal(state.bodyReads, 0);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
    assert.deepEqual(state.sessionWrites, []);
    assert.equal(isTemplateInsertionBlocked(item), false);
    item.sessionData.getAsync = originalRead;
    await prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media });
    assert.equal(state.bodyReads, 1);
    assert.equal(state.mediaWrites, 1);
    assert.equal(state.prepends.length, 1);
    assert.equal(state.session, '1');
});

test('missing requirement checker probes available session APIs without treating host exceptions as prior writes', async () => {
    const { office, item, state } = fixture();
    delete office.context.requirements;
    item.sessionData.getAsync = () => {
        throw Object.assign(new Error('Host-specific text'), { code: 'API_NOT_SUPPORTED' });
    };
    const error = await rejectedCode(prependTemplate(office, item, '<p>Template</p>'));
    assert.equal(error.code, 'COMPOSE_SESSION_UNREADABLE');
    assert.equal(error.phase, 'session-read');
    assert.equal(error.reason, 'exception');
    assert.equal(error.officeCode, 'API_NOT_SUPPORTED');
    assert.equal(isTemplateInsertionBlocked(item), false);
    assert.equal(state.bodyReads, 0);
    assert.equal(state.prepends.length, 0);
});

test('failed requirement check is fail-closed and redacts unsafe host codes', async () => {
    const { office, item, state } = fixture();
    office.context.requirements.isSetSupported = () => {
        throw Object.assign(new Error('Secret mailbox detail'), { code: 'credential=private@example.test' });
    };
    const error = await rejectedCode(prependTemplate(office, item, '<p>Template</p>'));
    assert.equal(error.code, 'COMPOSE_SESSION_UNREADABLE');
    assert.equal(error.phase, 'session-capability');
    assert.equal(error.reason, 'exception');
    assert.equal(error.officeCode, undefined);
    assert.doesNotMatch(JSON.stringify(error), /private@example|Secret mailbox/);
    assert.equal(state.sessionReads, 0);
    assert.equal(state.bodyReads, 0);
    assert.equal(state.prepends.length, 0);
});

test('initial session timeout is read-only and does not quarantine the item', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { office, item, state, media } = fixture();
    item.sessionData.getAsync = () => {};
    const outcome = rejectedCode(prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media }));
    context.mock.timers.tick(TEMPLATE_INSERT_LIMITS.readTimeoutMs);
    const error = await outcome;
    assert.equal(error.code, 'COMPOSE_SESSION_UNREADABLE');
    assert.equal(error.reason, 'timeout');
    assert.equal(isTemplateInsertionBlocked(item), false);
    assert.equal(state.bodyReads, 0);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
});

test('second session read failure before claim remains retryable and does not create a pending marker', async () => {
    const { office, item, state, media } = fixture();
    item.sessionData.getAsync = (_key, callback) => {
        state.sessionReads += 1;
        callback(state.sessionReads === 1 ? success('') : { status: 'failed', error: { code: 'READ_BUSY' } });
    };
    const error = await rejectedCode(prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media }));
    assert.equal(error.code, 'COMPOSE_SESSION_UNREADABLE');
    assert.equal(error.officeCode, 'READ_BUSY');
    assert.equal(state.bodyReads, 1);
    assert.equal(state.mediaWrites, 0);
    assert.deepEqual(state.sessionWrites, []);
    assert.equal(isTemplateInsertionBlocked(item), false);
});

test('existing pending markers remain uncertain across runtimes and can never be dismissed by confirmation', async () => {
    const reopened = await import('../../resources/js/outlook-addin/compose-template.js?pending-session-regression');
    const { office, item, state, media } = fixture({ session: 'pending:previous-operation' });
    let confirmations = 0;
    const error = await rejectedCode(reopened.prependTemplate(office, item, '<p>Template</p>', undefined, {
        beforeInsert: media,
        confirmAdditional: () => { confirmations += 1; return true; },
    }));
    assert.equal(error.code, 'TEMPLATE_INSERT_UNCERTAIN');
    assert.equal(error.phase, 'session-read');
    assert.equal(error.reason, 'pending');
    assert.equal(confirmations, 0);
    assert.equal(state.bodyReads, 0);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
    assert.equal(state.session, 'pending:previous-operation');
    assert.deepEqual(state.sessionWrites, []);
});

test('unknown native session write result remains quarantined before media preparation', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { office, item, state, media } = fixture();
    item.sessionData.setAsync = (_key, value) => {
        state.sessionWrites.push(value);
        state.session = value;
    };
    const outcome = rejectedCode(prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media }));
    await flush(() => state.sessionWrites.length > 0);
    context.mock.timers.tick(TEMPLATE_INSERT_LIMITS.readTimeoutMs);
    const error = await outcome;
    assert.equal(error.code, 'TEMPLATE_INSERT_UNCERTAIN');
    assert.equal(error.phase, 'session-write');
    assert.equal(error.reason, 'timeout');
    assert.match(state.session, /^pending:/);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
    assert.equal(isTemplateInsertionBlocked(item), true);
    await assert.rejects(prependTemplate(office, item, '<p>Retry</p>'), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
});

test('failed session readback preserves native code and the pending claim', async () => {
    const { office, item, state, media } = fixture();
    item.sessionData.getAsync = (_key, callback) => {
        state.sessionReads += 1;
        callback(state.sessionReads < 3 ? success(state.session) : { status: 'failed', error: { code: 'READBACK_FAILED' } });
    };
    const error = await rejectedCode(prependTemplate(office, item, '<p>Template</p>', undefined, { beforeInsert: media }));
    assert.equal(error.code, 'TEMPLATE_INSERT_UNCERTAIN');
    assert.equal(error.phase, 'session-readback');
    assert.equal(error.officeCode, 'READBACK_FAILED');
    assert.match(state.session, /^pending:/);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
    assert.equal(isTemplateInsertionBlocked(item), true);
});

test('async mailbox guard rejection occurs before any session, media or body mutation', async () => {
    const { office, item, state, media } = fixture();
    const guard = async () => {
        await Promise.resolve();
        throw Object.assign(new Error('Wrong account'), { code: 'MAILBOX_NOT_AUTHORIZED' });
    };
    await assert.rejects(prependTemplate(office, item, '<p>Template</p>', guard, { beforeInsert: media }), { code: 'MAILBOX_NOT_AUTHORIZED' });
    assert.deepEqual(state.sessionWrites, []);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
    assert.equal(isTemplateInsertionBlocked(item), false);
});

test('mailbox guard is awaited again after claim-read and stops a switch during that read', async () => {
    const { office, item, state, media } = fixture();
    let allowed = true;
    item.sessionData.getAsync = (_key, callback) => {
        state.sessionReads += 1;
        if (state.sessionReads === 2) allowed = false;
        callback(success(state.session));
    };
    const guard = async () => {
        await Promise.resolve();
        if (!allowed) throw Object.assign(new Error('Changed'), { code: 'MAILBOX_NOT_AUTHORIZED' });
    };
    await assert.rejects(prependTemplate(office, item, '<p>Template</p>', guard, { beforeInsert: media }), { code: 'MAILBOX_NOT_AUTHORIZED' });
    assert.deepEqual(state.sessionWrites, []);
    assert.equal(state.mediaWrites, 0);
    assert.equal(state.prepends.length, 0);
});

test('account switch after media stops body writes and prevents unauthorized session cleanup', async () => {
    const { office, item, state } = fixture();
    let allowed = true;
    const guard = async () => {
        await Promise.resolve();
        if (!allowed) throw Object.assign(new Error('Changed'), { code: 'MAILBOX_NOT_AUTHORIZED' });
    };
    await assert.rejects(prependTemplate(office, item, '<p>Template</p>', guard, {
        beforeInsert: () => { state.mediaWrites += 1; allowed = false; },
    }), { code: 'MAILBOX_NOT_AUTHORIZED' });
    assert.equal(state.mediaWrites, 1);
    assert.equal(state.prepends.length, 0);
    assert.equal(state.sessionWrites.length, 1);
    assert.match(state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(item), true);
});

test('definite additional failure restores the previous applied session after awaiting its guard', async () => {
    const { office, item, state } = fixture({ session: '1' });
    let guardCount = 0;
    await assert.rejects(prependTemplate(office, item, '<p>Additional</p>', async () => {
        await Promise.resolve(); guardCount += 1;
    }, {
        confirmAdditional: () => true,
        beforeInsert: () => { throw Object.assign(new Error('Failed'), { code: 'INLINE_ATTACHMENT_FAILED' }); },
    }), { code: 'INLINE_ATTACHMENT_FAILED' });
    assert.equal(state.session, '1');
    assert.equal(state.sessionWrites.length, 2);
    assert.ok(guardCount >= 5);
    assert.equal(state.prepends.length, 0);
    assert.equal(isTemplateInsertionBlocked(item), false);
});

test('native prepend uncertainty retains safe error details, never retries and recognizes a late success', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { office, item, state } = fixture();
    let callback;
    item.body.prependAsync = (html, _options, complete) => {
        state.prepends.push(html);
        callback = complete;
    };
    const outcome = rejectedCode(prependTemplate(office, item, '<p>Template</p>'));
    await flush(() => callback);
    context.mock.timers.tick(TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    const error = await outcome;
    assert.equal(error.code, 'TEMPLATE_INSERT_UNCERTAIN');
    assert.equal(error.phase, 'body-prepend');
    assert.equal(error.reason, 'timeout');
    await assert.rejects(prependTemplate(office, item, '<p>Retry</p>'), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    await callback(success());
    assert.equal(state.session, '1');
    assert.equal(isTemplateInsertionBlocked(item), false);
    await assert.rejects(prependTemplate(office, item, '<p>Duplicate</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });
    assert.equal(state.prepends.length, 1);
});

test('immediate native throws are labeled as exceptions, not elapsed timeouts', async () => {
    const { office, item } = fixture();
    item.body.prependAsync = () => { throw Object.assign(new Error('Private host detail'), { code: 'NATIVE_FAILURE' }); };
    const error = await rejectedCode(prependTemplate(office, item, '<p>Template</p>'));
    assert.equal(error.code, 'TEMPLATE_INSERT_UNCERTAIN');
    assert.equal(error.phase, 'body-prepend');
    assert.equal(error.reason, 'exception');
    assert.equal(error.officeCode, 'NATIVE_FAILURE');
    assert.doesNotMatch(JSON.stringify(error), /Private host detail/);
    assert.equal(isTemplateInsertionBlocked(item), true);
});

test('native success after account switch does not mutate session metadata in the changed context', async () => {
    const { office, item, state } = fixture();
    let allowed = true;
    item.body.prependAsync = (html, _options, callback) => {
        state.prepends.push(html);
        allowed = false;
        callback(success());
    };
    await prependTemplate(office, item, '<p>Template</p>', async () => {
        await Promise.resolve();
        if (!allowed) throw Object.assign(new Error('Changed'), { code: 'MAILBOX_NOT_AUTHORIZED' });
    });
    assert.equal(state.prepends.length, 1);
    assert.equal(state.sessionWrites.length, 1);
    assert.match(state.session, /^pending:/);
    await assert.rejects(prependTemplate(office, item, '<p>Duplicate</p>'), { code: 'TEMPLATE_ALREADY_INSERTED' });
});
