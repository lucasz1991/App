import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { currentComposeBodyHtml } from '../../resources/js/outlook-addin/compose-template.js';
import { replaceUniversalComposeDocument, insertUniversalComposeDocument, universalComposeReport,
    universalComposeBlocked, UNIVERSAL_COMPOSE_SESSION_KEY } from '../../resources/js/outlook-addin/u2e-compose.js';
import { readUniversalSessionReport } from '../../resources/js/outlook-addin/u2e-session-report.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
const signature = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/signature.json', import.meta.url), 'utf8'));
const ok = value => ({ status: 'succeeded', value });
const bad = { status: 'failed', error: { code: 'test-host-failure' } };
const errorOf = promise => promise.then(() => null, error => error);
const until = async predicate => { for (let index = 0; index < 500 && !predicate(); index++) await Promise.resolve(); assert.ok(predicate()); };
const own = '<p>OLD AUTHOR TEXT</p>';
const quote = '<hr><div id="divRplyFwdMsg"><p>UNCHANGED QUOTE</p><a href="https://example.invalid/q">Quoted link</a>'
    + '<img src="cid:existing-image" width="12" height="12"></div>';

function fixture(options = {}) {
    const state = { html: own, composeType: 'newMail', sender: 'it-technik@rail-time.de', format: 'html',
        sessions: new Map(), reads: [], bodyWrites: [], headerWrites: [], sessionWrites: [], forbidden: [], ...options.state };
    const item = {
        from: { getAsync: callback => {
            options.senderRead?.({ state, item, office });
            callback(ok({ emailAddress: state.sender }));
        } },
        getComposeTypeAsync: callback => callback(ok({ composeType: state.composeType })),
        sessionData: {
            getAsync(key, callback) {
                if (options.sessionRead?.({ key, callback, state, item, office }) === false) return;
                callback(ok(state.sessions.get(key) || ''));
            },
            setAsync(key, value, callback) {
                state.sessionWrites.push({ key, value });
                if (options.sessionWrite?.({ key, value, callback, state, item, office }) === false) return;
                state.sessions.set(key, value); callback(ok());
            },
        },
        internetHeaders: { setAsync(headers, callback) {
            state.headerWrites.push(headers);
            if (options.headerWrite?.({ headers, callback, state, item, office }) === false) return;
            callback(ok());
        } },
        body: {
            getTypeAsync: callback => callback(ok(state.format)),
            getAsync(format, optionsOrCallback, callback) {
                const done = callback || optionsOrCallback;
                state.reads.push({ format, options: callback ? optionsOrCallback : null });
                if (options.bodyRead?.({ callback: done, state, item, office }) === false) return;
                done(ok(state.html));
            },
            setAsync(html, writeOptions, callback) {
                state.bodyWrites.push({ html, options: writeOptions });
                if (options.bodyWrite) return options.bodyWrite({ html, callback, state, item, office });
                state.html = html; callback(ok());
            },
            prependAsync: () => state.forbidden.push('prepend'),
            setSignatureAsync: () => state.forbidden.push('signature'),
        },
        send: () => state.forbidden.push('send'),
        saveAsync: () => state.forbidden.push('save'),
        addFileAttachmentFromBase64Async: () => state.forbidden.push('add-attachment'),
        removeAttachmentAsync: () => state.forbidden.push('remove-attachment'),
        subject: { setAsync: () => state.forbidden.push('subject') },
        to: { setAsync: () => state.forbidden.push('recipients') },
    };
    const host = options.host || 'OutlookWebApp';
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        MailboxEnums: { BodyMode: { FullBody: 0, HostConfig: 1 } }, context: {
            platform: host === 'OutlookIOS' ? 'iOS' : host === 'OutlookAndroid' ? 'Android' : 'PC',
            requirements: { isSetSupported: () => true },
            mailbox: { item, diagnostics: { hostName: host, hostVersion: '4.2538.0' }, userProfile: { emailAddress: state.sender } },
        } };
    const binding = { schema: 1, mailboxAddress: state.sender, senderAddress: state.sender, allowedSenderAddresses: [state.sender] };
    const beforeWrite = () => options.guard?.({ state, item, office });
    const replace = (document = source) => replaceUniversalComposeDocument(office, item, document, { binding, beforeWrite });
    const automatic = () => insertUniversalComposeDocument(office, item, source, { binding, beforeWrite });
    return { state, item, office, binding, replace, automatic };
}

test('manual selection replaces authored content in one nonempty write and is repeatable even for the same current source', async () => {
    const f = fixture();
    assert.equal((await f.replace()).status, 'applied');
    assert.equal(f.state.html, source.html);
    assert.equal((await f.replace()).status, 'applied');
    assert.equal(f.state.html, source.html);
    assert.deepEqual(f.state.bodyWrites.map(write => write.html), [source.html, source.html]);
    assert.equal(f.state.bodyWrites.every(write => write.options.coercionType === 'html'), true);
    assert.match(f.state.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), new RegExp('^applied:' + source.version + ':combined$'));
    assert.equal(universalComposeBlocked(f.item), false);
    assert.equal(universalComposeReport(f.item).state, 'complete');
    assert.deepEqual(f.state.forbidden, []);
});

test('old, changed and multiple own signatures do not prohibit an explicit full own-region replacement', async () => {
    const old = source.html.replaceAll(source.version, 'a'.repeat(24));
    for (const html of [old, own + old, old + source.html, source.html.replace('width="1040"', 'width="700"') + own]) {
        const f = fixture({ state: { html } });
        f.state.sessions.set(UNIVERSAL_COMPOSE_SESSION_KEY, 'applied:' + 'a'.repeat(24) + ':combined');
        assert.equal((await f.replace()).current, true);
        assert.equal(f.state.html, source.html);
        assert.equal(f.state.bodyWrites.length, 1);
    }
});

test('automatic insertion remains conservative and cannot be converted into replacement through an options flag', async () => {
    const f = fixture();
    assert.equal((await errorOf(insertUniversalComposeDocument(f.office, f.item, source,
        { binding: f.binding, manualReplacement: true }))).code, 'U2E_NEW_DRAFT_REQUIRED');
    assert.equal(f.state.html, own); assert.equal(f.state.bodyWrites.length, 0);
    await f.replace();
    assert.equal((await f.automatic()).status, 'already-present');
    assert.equal(f.state.bodyWrites.length, 1);
});

test('reply and forward replacement preserve the exact quote suffix and enclosing document context', async () => {
    const head = '<html><head><style>.quoted {color:red}</style></head><body class="word">';
    const context = '<div class="quoted"><div style="font-family:Arial">';
    const history = quote + source.html.replaceAll(source.version, 'b'.repeat(24)) + '</div></div></body></html>';
    for (const composeType of ['reply', 'forward']) {
        const f = fixture({ state: { composeType, html: head + context + own + history } });
        assert.equal((await f.replace()).status, 'applied');
        assert.equal(f.state.html, head + source.html + context + history);
        assert.equal(f.state.html.endsWith(history), true);
        assert.doesNotMatch(f.state.html, /OLD AUTHOR TEXT/);
        assert.equal(f.state.bodyWrites.length, 1);
        assert.deepEqual(f.state.forbidden, []);
        assert.equal((await f.replace()).status, 'applied');
        assert.equal(f.state.html, head + source.html + context + history);
    }
});

test('fragment replies keep their inherited quote wrappers and prefixed native marker', async () => {
    const history = quote.replace('divRplyFwdMsg', 'x_x_divRplyFwdMsg') + '</div>';
    const f = fixture({ state: { composeType: 'reply', html: '<div style="color:blue">' + own + history } });
    await f.replace();
    assert.equal(f.state.html, source.html + '<div style="color:blue">' + history);
});

test('the captured redacted Word reply boundary retains its complete original suffix', async () => {
    const html = fs.readFileSync(new URL('../Fixtures/mail/u2e-word-inline-boundary/word-inline.redacted.html', import.meta.url), 'utf8');
    const current = currentComposeBodyHtml(html, 'reply');
    assert.ok(current !== null);
    const history = html.slice(current.length);
    const f = fixture({ host: 'Outlook', state: { html, composeType: 'reply' } });
    assert.equal((await f.replace()).status, 'applied');
    assert.equal(f.state.bodyWrites[0].html.endsWith(history), true);
    assert.ok(f.state.html.includes('<style>p.MsoNormal { margin:0; }</style>'));
    assert.equal((await f.replace()).status, 'applied');
    assert.equal(f.state.bodyWrites[1].html.endsWith(history), true);
});

test('manual readback accepts current Word and Rooster serializations without requiring source HTML bytes', async () => {
    for (const [host, name] of [['Outlook', 'word'], ['OutlookWebApp', 'rooster']]) {
        const html = fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/' + name + '.synthetic.html', import.meta.url), 'utf8');
        const f = fixture({ host, bodyWrite({ callback, state }) { state.html = html; callback(ok()); } });
        assert.equal((await f.replace()).status, 'applied');
        assert.equal(universalComposeReport(f.item).state, 'complete');
        assert.equal(f.state.bodyWrites[0].html, source.html);
    }
});

test('mobile Message Compose cannot replace its body even when the bridge exposes setAsync', async () => {
    for (const host of ['OutlookIOS', 'OutlookAndroid']) for (const composeType of ['newMail', 'reply', 'forward']) {
        const f = fixture({ host, state: { composeType, html: own + (composeType === 'newMail' ? '' : quote) } });
        assert.equal((await errorOf(f.replace())).code, 'U2E_API_UNAVAILABLE');
        assert.equal(f.state.reads.length, 0);
        assert.equal(f.state.bodyWrites.length + f.state.headerWrites.length + f.state.sessionWrites.length, 0);
        assert.deepEqual(f.state.forbidden, []);
    }
});

test('a reply without a provable quote never feeds apparently scoped GET content to full SET', async () => {
    for (const host of ['Outlook', 'OutlookWebApp']) {
        const f = fixture({ host, state: { composeType: 'reply', html: '<div>Only current reply returned</div>' } });
        assert.equal((await errorOf(f.replace())).code, 'U2E_NEW_DRAFT_REQUIRED');
        assert.equal(f.state.bodyWrites.length, 0);
        assert.equal(f.state.headerWrites.length, 0);
        assert.equal(f.state.sessionWrites.length, 0);
        assert.equal(f.state.reads[0].options, null);
        assert.equal(universalComposeReport(f.item).bodyWriteStarted, false);
    }
});

test('signature-only source, invalid source, missing setAsync, non-HTML and unknown quote stop before any mutation', async () => {
    for (const arrange of [
        f => ({ document: signature, code: 'U2E_DOCUMENT_INVALID' }),
        f => ({ document: { ...source, html: '' }, code: 'U2E_SOURCE_INVALID' }),
        f => { delete f.item.body.setAsync; return { code: 'U2E_API_UNAVAILABLE' }; },
        f => { f.state.format = 'text'; return { code: 'U2E_HTML_REQUIRED' }; },
        f => { f.state.composeType = 'forward'; return { code: 'U2E_NEW_DRAFT_REQUIRED' }; },
    ]) {
        const f = fixture(), expected = arrange(f);
        assert.equal((await errorOf(f.replace(expected.document || source))).code, expected.code);
        assert.equal(f.state.html, own);
        assert.equal(f.state.bodyWrites.length + f.state.headerWrites.length + f.state.sessionWrites.length, 0);
    }
});

test('preexisting pending state blocks manual replacement across a freshly loaded runtime', async () => {
    const f = fixture({ state: { html: source.html } });
    f.state.sessions.set(UNIVERSAL_COMPOSE_SESSION_KEY, 'pending:' + source.version + ':' + 'a'.repeat(32));
    const fresh = await import('../../resources/js/outlook-addin/u2e-compose.js?manual-pending-reload');
    assert.equal((await errorOf(fresh.replaceUniversalComposeDocument(f.office, f.item, source, { binding: f.binding }))).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 0);
    assert.equal(f.state.headerWrites.length, 0);
    assert.equal(fresh.universalComposeBlocked(f.item), true);
});

test('a pending old legacy insertion also blocks the explicit manual entry', async () => {
    const f = fixture();
    f.state.sessions.set('railtime.template.inserted.v1', 'pending:old-operation');
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 0);
});

test('parallel manual selection and automatic insertion share one operation and perform only one write', async () => {
    let release;
    const f = fixture({ bodyRead({ callback, state }) {
        if (state.reads.length === 1) { release = () => callback(ok(state.html)); return false; }
    } });
    const first = f.replace();
    await until(() => release);
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal((await errorOf(f.automatic())).code, 'U2E_INSERT_UNCERTAIN');
    release();
    assert.equal((await first).status, 'applied');
    assert.equal(f.state.bodyWrites.length, 1);
});

test('sender and item changes during preparation never mutate a different target', async () => {
    for (const change of ['sender', 'item']) {
        let changed = false;
        const f = fixture({ guard({ state, office }) {
            if (!changed) { changed = true;
                if (change === 'sender') state.sender = 'someone-else@example.invalid';
                else office.context.mailbox.item = {};
            }
        } });
        assert.ok(await errorOf(f.replace()));
        assert.equal(f.state.bodyWrites.length + f.state.headerWrites.length + f.state.sessionWrites.length, 0);
    }
});

test('changed body between snapshots stops before body replacement and does not silently retry', async () => {
    const f = fixture({ bodyRead({ state }) { if (state.reads.length === 2) state.html += '<p>NEWLY TYPED TEXT</p>'; } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(universalComposeReport(f.item).originalCode, 'U2E_CONTEXT_CHANGED');
    assert.equal(universalComposeReport(f.item).bodyWriteStarted, false);
    assert.equal(f.state.bodyWrites.length, 0);
    assert.match(f.state.html, /NEWLY TYPED TEXT/);
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 0);
});

test('text typed during the final caller guard is seen by a final body snapshot', async () => {
    let changed = false;
    const f = fixture({ guard({ state }) {
        if (!changed && state.reads.length === 2) { changed = true; state.html += '<p>TYPED DURING GUARD</p>'; }
    } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(universalComposeReport(f.item).originalCode, 'U2E_CONTEXT_CHANGED');
    assert.equal(f.state.bodyWrites.length, 0);
    assert.match(f.state.html, /TYPED DURING GUARD/);
});

test('text typed during the last sender check cannot be overwritten by an older prepared payload', async () => {
    let checks = 0;
    const f = fixture({ senderRead({ state }) {
        if (state.reads.length === 2 && ++checks === 2) state.html += '<p>TYPED DURING SENDER CHECK</p>';
    } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(checks, 2);
    assert.equal(universalComposeReport(f.item).originalCode, 'U2E_CONTEXT_CHANGED');
    assert.equal(f.state.bodyWrites.length, 0);
    assert.match(f.state.html, /TYPED DURING SENDER CHECK/);
});

test('a changed compose type cannot redirect the prepared replacement', async () => {
    const f = fixture({ bodyRead({ state }) { if (state.reads.length === 1) state.composeType = 'reply'; },
        state: { html: own + quote } });
    assert.ok(await errorOf(f.replace()));
    assert.equal(f.state.bodyWrites.length, 0);
});

test('a failed header write never clears or overwrites the own body', async () => {
    const f = fixture({ headerWrite({ callback }) { callback(bad); return false; } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.html, own); assert.equal(f.state.bodyWrites.length, 0);
    assert.match(f.state.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), /^pending:/);
});

test('bridge exception after body invocation retains pending across runtime reload without a second write', async () => {
    const f = fixture({ bodyWrite({ html, state }) { state.html = html; throw new Error('private bridge detail'); } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    const report = await readUniversalSessionReport(f.office, f.item);
    assert.equal(report.bodyWriteStarted, true); assert.equal(report.bodyWriteSucceeded, false);
    const fresh = await import('../../resources/js/outlook-addin/u2e-compose.js?manual-bridge-reload');
    assert.equal((await errorOf(fresh.replaceUniversalComposeDocument(f.office, f.item, source, { binding: f.binding }))).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1);
    assert.doesNotMatch(JSON.stringify(report), /private bridge/);
});

test('a failed SDK body callback never schedules a second write or an empty clear', async () => {
    const f = fixture({ bodyWrite({ callback }) { callback(bad); } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1);
    assert.equal(f.state.bodyWrites[0].html, source.html);
    assert.equal(f.state.html, own);
});

test('material alone does not confirm replacement when authored leftovers remain outside the fresh shell', async () => {
    const f = fixture({ bodyWrite({ html, callback, state }) { state.html = '<p>UNREMOVED OLD TEXT</p>' + html; callback(ok()); } });
    assert.equal((await errorOf(f.replace())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(universalComposeReport(f.item).checkpoint, 'author.combined');
    assert.equal(universalComposeReport(f.item).step, 'readback.author');
    assert.match(f.state.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), /^pending:/);
});

test('an extra unmarked old table is not accepted as an empty benign host wrapper', async () => {
    const f = fixture({ bodyWrite({ html, callback, state }) { state.html = html + '<table><tr><td></td></tr></table>'; callback(ok()); } });
    assert.equal((await errorOf(f.replace())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(universalComposeReport(f.item).checkpoint, 'author.combined');
});

test('quote changes after the write keep the operation uncertain rather than reporting success', async () => {
    const f = fixture({ state: { composeType: 'reply', html: own + quote },
        bodyWrite({ html, callback, state }) { state.html = html.replace('UNCHANGED QUOTE', 'CHANGED QUOTE'); callback(ok()); } });
    assert.equal((await errorOf(f.replace())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(universalComposeReport(f.item).checkpoint, 'quote.bytes');
    assert.equal(f.state.bodyWrites[0].html.endsWith(quote), true);
    assert.equal(f.state.bodyWrites.length, 1);
});

test('only existing Classic lexical quote equivalence applies; OWA still rejects changed serialization', async () => {
    for (const host of ['Outlook', 'OutlookWebApp']) {
        const f = fixture({ host, state: { composeType: 'forward', html: own + quote },
            bodyWrite({ html, callback, state }) { state.html = html.replace('id="divRplyFwdMsg"', 'id=divRplyFwdMsg'); callback(ok()); } });
        if (host === 'Outlook') assert.equal((await f.replace()).status, 'applied');
        else assert.equal((await errorOf(f.replace())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(f.state.bodyWrites[0].html.endsWith(quote), true);
    }
});

test('loss of the quote boundary on readback cannot be accepted as a new empty quote', async () => {
    const f = fixture({ state: { composeType: 'reply', html: own + quote },
        bodyWrite({ callback, state }) { state.html = source.html; callback(ok()); } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(universalComposeReport(f.item).originalCode, 'U2E_NEW_DRAFT_REQUIRED');
    assert.equal(universalComposeReport(f.item).phase, 'readback');
    assert.equal(f.state.bodyWrites.length, 1);
});

test('final claim persistence failure never turns a successful body callback into repeat permission', async () => {
    const f = fixture({ sessionWrite({ key, value, callback }) {
        if (key === UNIVERSAL_COMPOSE_SESSION_KEY && value.startsWith('applied:')) { callback(bad); return false; }
    } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(universalComposeReport(f.item).step, 'session.finalize');
    assert.equal(universalComposeReport(f.item).bodyWriteSucceeded, true);
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1);
});

test('a timed-out write and its late callback cannot enable a new selection automatically', async context => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    let callback;
    const f = fixture({ bodyWrite(value) { callback = value.callback; } });
    const pending = errorOf(f.replace());
    await until(() => callback);
    context.mock.timers.tick(30001);
    assert.equal((await pending).code, 'U2E_INSERT_UNCERTAIN');
    f.state.html = source.html; callback(ok());
    assert.equal((await errorOf(f.replace())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1);
    context.mock.timers.reset();
});

test('prepared replacement exceeding the readback budget fails before claiming or writing', async () => {
    const history = '<div id="divRplyFwdMsg">' + 'x'.repeat(490000) + '</div>';
    const f = fixture({ state: { composeType: 'reply', html: own + history } });
    assert.equal((await errorOf(f.replace())).code, 'U2E_CONTEXT_UNREADABLE');
    assert.equal(f.state.bodyWrites.length + f.state.headerWrites.length + f.state.sessionWrites.length, 0);
});
