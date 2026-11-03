import assert from 'node:assert/strict';
import test from 'node:test';
import {
    emptyU2eProbeBody,
    insertU2eTransportProbe,
    u2eTransportProbeReport,
} from '../../resources/js/outlook-addin/u2e-transport-probe.js';
import {
    U2E_PROBE_MAILBOX,
    U2E_PROBE_SESSION_KEY,
    U2E_PROBE_SUBJECT,
    u2eProbeDocument,
} from '../../resources/js/outlook-addin/u2e-editor-probe.js';

const success = value => ({ status: 'succeeded', value });
const failed = code => ({ status: 'failed', error: { code, message: 'private host message' } });
const rejected = promise => promise.then(() => null, error => error);
const flush = async (until = () => false) => {
    for (let index = 0; index < 300 && !until(); index++) await Promise.resolve();
    assert.equal(until(), true, 'expected Office bridge checkpoint was reached');
};

function fixture(options = {}) {
    const state = {
        html: '<html><head><meta charset="utf-8"></head><body><p><br></p></body></html>',
        subject: U2E_PROBE_SUBJECT, to: [{ emailAddress: U2E_PROBE_MAILBOX }], cc: [], bcc: [],
        attachments: [], composeType: 'newMail', format: 'html', sender: U2E_PROBE_MAILBOX,
        sessions: new Map(), sessionReads: [], sessionWrites: [], writes: [], bodyReads: 0,
        guardCalls: 0, forbidden: [], ...options.state,
    };
    let office;
    const write = method => (html, writeOptions, callback) => {
        state.writes.push({ method, html, options: writeOptions });
        if (options.write) return options.write({ method, html, callback, state, item, office });
        state.html = method === 'prependAsync' ? html + state.html : html;
        callback(success());
    };
    const item = {
        from: { getAsync: callback => callback(success({ emailAddress: state.sender })) },
        subject: { getAsync: callback => callback(success(state.subject)) },
        to: { getAsync: callback => callback(success(state.to)) },
        cc: { getAsync: callback => callback(success(state.cc)) },
        bcc: { getAsync: callback => callback(success(state.bcc)) },
        getAttachmentsAsync: callback => callback(success(state.attachments)),
        getComposeTypeAsync: callback => callback(success({ composeType: state.composeType })),
        sessionData: {
            getAsync(key, callback) {
                state.sessionReads.push(key);
                if (options.sessionRead?.({ key, callback, state, item, office }) === false) return;
                callback(success(state.sessions.get(key) ?? ''));
            },
            setAsync(key, value, callback) {
                state.sessionWrites.push({ key, value });
                if (options.sessionWrite?.({ key, value, callback, state, item, office }) === false) return;
                state.sessions.set(key, value);
                callback(success());
            },
        },
        body: {
            getTypeAsync: callback => callback(success(state.format)),
            getAsync(format, callback) {
                assert.equal(format, 'html');
                state.bodyReads++;
                if (options.bodyRead?.({ callback, state, item, office }) === false) return;
                callback(success(state.html));
            },
            prependAsync: write('prependAsync'),
            setSignatureAsync: write('setSignatureAsync'),
            setAsync: () => state.forbidden.push('replace-body'),
        },
        addFileAttachmentFromBase64Async: () => state.forbidden.push('attach'),
        disableClientSignatureAsync: () => state.forbidden.push('disable-native'),
        send: () => state.forbidden.push('send'),
    };
    office = {
        AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        context: {
            platform: options.platform ?? 'PC',
            mailbox: { item, userProfile: { emailAddress: U2E_PROBE_MAILBOX } },
            requirements: { isSetSupported: () => true },
        },
    };
    const binding = { schema: 1, mailboxAddress: U2E_PROBE_MAILBOX, senderAddress: U2E_PROBE_MAILBOX,
        allowedSenderAddresses: [U2E_PROBE_MAILBOX] };
    const beforeWrite = () => {
        state.guardCalls++;
        options.guard?.({ state, item, office, binding });
    };
    const insert = extra => insertU2eTransportProbe(office, item, { binding, beforeWrite, ...extra });
    return { office, item, state, binding, beforeWrite, insert };
}

test('empty technical bodies allow only whitespace and harmless blank editor wrappers', () => {
    for (const html of ['', '<p><br></p>', '<div><span>&nbsp;&#160;&#x00a0;</span></div>',
        '<html><head><meta charset="utf-8"><style>p { margin:0 }</style></head><body><p><br></p></body></html>']) {
        assert.equal(emptyU2eProbeBody(html), true, html);
    }
    for (const html of [null, '<p>User text</p>', '<!--RT-FLUID-LAYOUT-V17B-->',
        '<div id="divRplyFwdMsg"><p>Quote</p></div>', '<table><tr><td></td></tr></table>',
        '<img src="https://example.test/image.png">', '<script></script>', '<input>', '<iframe></iframe>',
        '<head></head><head></head>', 'x'.repeat(100001)]) {
        assert.equal(emptyU2eProbeBody(html), false, String(html).slice(0, 80));
    }
});

for (const platform of ['PC', 'OfficeOnline', 'Mac', 'iOS', 'Android']) {
    test(`a fixed IT-only probe performs exactly one permitted ${platform} write and confirms readback`, async () => {
        const f = fixture({ platform });
        const result = await f.insert({ html: '<p>Arbitrary caller markup</p>', method: 'setAsync',
            sourceDocument: { html: '<p>Caller source</p>' } });
        assert.equal(result.state, 'complete');
        assert.equal(result.attempted, true);
        assert.equal(f.state.writes.length, 1);
        assert.equal(f.state.writes[0].method, ['iOS', 'Android'].includes(platform) ? 'setSignatureAsync' : 'prependAsync');
        assert.equal(f.state.writes[0].html, u2eProbeDocument().html);
        assert.deepEqual(f.state.writes[0].options, { coercionType: 'html' });
        assert.deepEqual(f.state.forbidden, []);
        assert.equal(f.state.sessionWrites.length, 2);
        assert.match(f.state.sessionWrites[0].value, /^pending:u2e:[a-f0-9]{32}$/);
        assert.deepEqual(f.state.sessionWrites[1], { key: U2E_PROBE_SESSION_KEY, value: '1' });
        assert.equal(f.state.sessions.get(U2E_PROBE_SESSION_KEY), '1');
        const report = u2eTransportProbeReport(f.item);
        report.state = 'caller-mutated';
        assert.equal(u2eTransportProbeReport(f.item).state, 'complete');
        assert.doesNotMatch(JSON.stringify(result), /Arbitrary caller|Caller source|private host message/);
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
        assert.equal(f.state.writes.length, 1);
    });
}

test('unknown items have no probe report', () => {
    assert.equal(u2eTransportProbeReport({}), null);
    assert.equal(u2eTransportProbeReport(null), null);
});

const ineligible = [
    ['wrong exact subject', state => { state.subject += ' extra'; }],
    ['different subject case', state => { state.subject = state.subject.toLowerCase(); }],
    ['no recipient', state => { state.to = []; }],
    ['second recipient', state => { state.to.push({ emailAddress: U2E_PROBE_MAILBOX }); }],
    ['external recipient', state => { state.to[0].emailAddress = 'other@example.test'; }],
    ['recipient display string', state => { state.to = U2E_PROBE_MAILBOX; }],
    ['Cc recipient', state => { state.cc = [{ emailAddress: U2E_PROBE_MAILBOX }]; }],
    ['Bcc recipient', state => { state.bcc = [{ emailAddress: U2E_PROBE_MAILBOX }]; }],
    ['attachment', state => { state.attachments = [{ id: 'existing' }]; }],
    ['reply', state => { state.composeType = 'reply'; }],
    ['forward', state => { state.composeType = 'forward'; }],
    ['plaintext', state => { state.format = 'text'; }],
];
for (const [label, change] of ineligible) {
    test(`${label} prevents both persistent claim and body mutation`, async () => {
        const f = fixture(); change(f.state);
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_NOT_ELIGIBLE' });
        assert.deepEqual(f.state.writes, []);
        assert.deepEqual(f.state.sessionWrites, []);
        assert.equal(u2eTransportProbeReport(f.item), null);
    });
}

test('actual sender and current compose item are checked independently of the IT binding', async () => {
    const sender = fixture({ state: { sender: 'other@example.test' } });
    await assert.rejects(sender.insert(), { code: 'SENDER_MISMATCH' });
    assert.deepEqual(sender.state.writes, []);
    assert.deepEqual(sender.state.sessionWrites, []);
    const changed = fixture();
    changed.office.context.mailbox.item = { body: { prependAsync() {} } };
    await assert.rejects(changed.insert(), { code: 'COMPOSE_ITEM_CHANGED' });
    assert.deepEqual(changed.state.writes, []);
    assert.deepEqual(changed.state.sessionWrites, []);
});

test('a non-IT binding, missing sender bridge or missing persistent session bridge is ineligible', async () => {
    for (const mutate of [
        f => { f.binding.senderAddress = 'other@example.test'; },
        f => { f.binding.mailboxAddress = 'other@example.test'; },
        f => { f.office.context.mailbox.userProfile.emailAddress = 'other@example.test'; },
        f => { delete f.item.sessionData.getAsync; },
        f => { delete f.item.sessionData.setAsync; },
    ]) {
        const f = fixture(); mutate(f);
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_NOT_ELIGIBLE' });
        assert.deepEqual(f.state.writes, []);
        assert.deepEqual(f.state.sessionWrites, []);
    }
    const f = fixture(); delete f.item.from;
    await assert.rejects(f.insert(), { code: 'SENDER_API_UNAVAILABLE' });
    assert.deepEqual(f.state.sessionWrites, []);
});

test('existing user content, a quoted conversation or legacy V17 can never be overwritten', async () => {
    for (const html of ['<p>Unsent private user text</p>', '<div id="divRplyFwdMsg"><p>Quoted message</p></div>',
        '<span>RT-FLUID-LAYOUT-V17B</span>', '<!--RT-TEMPLATE-MANAGED-V1-->']) {
        const f = fixture({ state: { html } });
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_BODY_NOT_EMPTY' });
        assert.equal(f.state.html, html);
        assert.deepEqual(f.state.writes, []);
        assert.deepEqual(f.state.sessionWrites, []);
    }
});

test('a previously attempted probe never writes again, including opaque or completed claims', async () => {
    for (const prior of ['pending:old', 'uncertain:old', '1', 'unexpected']) {
        const f = fixture({ state: { sessions: new Map([[U2E_PROBE_SESSION_KEY, prior]]) } });
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
        assert.deepEqual(f.state.writes, []);
        assert.deepEqual(f.state.sessionWrites, []);
    }
});

test('ordinary uncertain template, signature and prior head-probe claims remain protected', async () => {
    for (const key of ['railtime.template.inserted.v1', 'railtime.signature.layout.v1', 'railtime.head-probe.v1']) {
        for (const prior of ['pending:old', 'uncertain:old', 'unknown']) {
            const f = fixture({ state: { sessions: new Map([[key, prior]]) } });
            await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
            assert.deepEqual(f.state.sessionWrites, []);
            assert.deepEqual(f.state.writes, []);
        }
    }
});

test('body and sender races after the persistent claim stop before the Office body write', async () => {
    for (const mutate of [
        state => { state.html = '<p>User typed during preparation</p>'; },
        state => { state.sender = 'other@example.test'; },
    ]) {
        let mutated = false;
        const f = fixture({ guard({ state }) {
            if (!mutated && state.sessions.has(U2E_PROBE_SESSION_KEY)) { mutate(state); mutated = true; }
        } });
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
        assert.equal(mutated, true);
        assert.deepEqual(f.state.writes, []);
        assert.match(f.state.sessions.get(U2E_PROBE_SESSION_KEY), /^pending:/);
        assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
    }
});

test('a context change during the initial body read cannot claim the old item', async () => {
    const f = fixture({ bodyRead({ callback, state, office }) {
        if (state.bodyReads === 1) {
            office.context.mailbox.item = { body: { prependAsync() {} } };
            callback(success(state.html));
            return false;
        }
    } });
    await assert.rejects(f.insert(), { code: 'COMPOSE_ITEM_CHANGED' });
    assert.equal(f.state.writes.length, 0);
    assert.equal(f.state.sessionWrites.length, 0);
    assert.equal(u2eTransportProbeReport(f.item), null);
});

test('a context change while reading back the written body cannot acknowledge that item complete', async () => {
    const f = fixture({ bodyRead({ callback, state, office }) {
        if (state.bodyReads === 3) {
            office.context.mailbox.item = { body: { prependAsync() {} } };
            callback(success(state.html));
            return false;
        }
    } });
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
    assert.equal(f.state.writes.length, 1);
    assert.equal(f.state.sessionWrites.length, 1);
    assert.match(f.state.sessions.get(U2E_PROBE_SESSION_KEY), /^pending:/);
    assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
});

test('an item switch during the last asynchronous claim check still prevents any body write', async () => {
    let probeReads = 0;
    const f = fixture({ sessionRead({ key, office }) {
        if (key === U2E_PROBE_SESSION_KEY && ++probeReads === 3) office.context.mailbox.item = { body: { prependAsync() {} } };
    } });
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
    assert.equal(probeReads >= 3, true);
    assert.equal(f.state.writes.length, 0);
    assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
});

test('an actual sender switch during the last asynchronous claim check prevents the body write', async () => {
    let probeReads = 0;
    const f = fixture({ sessionRead({ key, state }) {
        if (key === U2E_PROBE_SESSION_KEY && ++probeReads === 3) state.sender = 'other@example.test';
    } });
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
    assert.equal(probeReads >= 3, true);
    assert.equal(f.state.writes.length, 0);
    assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
});

for (const [label, damage] of [
    ['missing image', html => html.replace(/<img\b[^>]*>/, '')],
    ['missing table', html => html.replace(/<table\b[^>]*>/, '<div>').replace('</table>', '</div>')],
]) {
    test(`${label} after accepted Office write fails layout confirmation and never retries`, async () => {
        const f = fixture({ write({ html, callback, state }) { state.html = damage(html); callback(success()); } });
        await assert.rejects(f.insert(), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED' });
        assert.equal(f.state.writes.length, 1);
        assert.match(f.state.sessions.get(U2E_PROBE_SESSION_KEY), /^pending:/);
        assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
        assert.equal(f.state.writes.length, 1);
    });
}

test('a definite Office failure conservatively retains the probe claim without automatic retries', async () => {
    const f = fixture({ write({ callback }) { callback(failed(5001)); } });
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
    assert.equal(f.state.writes.length, 1);
    assert.equal(f.state.sessionWrites.length, 1);
    assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
});

test('duplicate write callbacks cannot perform a second insertion or reverse the accepted result', async () => {
    const f = fixture({ write({ html, callback, state }) {
        state.html = html; callback(success()); callback(failed(5001)); callback(success());
    } });
    assert.equal((await f.insert()).state, 'complete');
    assert.equal(f.state.writes.length, 1);
    assert.equal(f.state.sessionWrites.length, 2);
});

test('a missing write callback remains uncertain after timeout and a late success cannot enable retry', async context => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    let late;
    const f = fixture({ write({ html, callback, state }) { state.html = html; late = callback; } });
    const outcome = rejected(f.insert());
    await flush(() => Boolean(late));
    context.mock.timers.tick(30000);
    assert.equal((await outcome).code, 'U2E_PROBE_UNCONFIRMED');
    assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
    late(success());
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
    assert.equal(f.state.writes.length, 1);
    assert.equal(f.state.sessionWrites.length, 1);
});

test('a missing claim callback is already an attempted operation and late completion cannot write the body', async context => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    let late;
    const f = fixture({ sessionWrite({ key, value, callback, state }) {
        if (key === U2E_PROBE_SESSION_KEY) { state.sessions.set(key, value); late = callback; return false; }
    } });
    const outcome = rejected(f.insert());
    await flush(() => Boolean(late));
    context.mock.timers.tick(10000);
    assert.equal((await outcome).code, 'U2E_PROBE_UNCONFIRMED');
    late(success());
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
    assert.deepEqual(f.state.writes, []);
});

test('a definite claim failure is conservatively locked even if no body call was made', async () => {
    const f = fixture({ sessionWrite({ callback }) { callback(failed(5001)); return false; } });
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
    assert.equal(f.state.writes.length, 0);
    assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
    await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
});

test('a claimed operation cannot be acknowledged complete without confirmed terminal session storage', async () => {
    for (const confirm of [false, true]) {
        const f = fixture({ sessionWrite({ value, callback }) {
            if (value === '1') { callback(confirm ? success() : failed(5001)); return false; }
        } });
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_UNCONFIRMED' });
        assert.equal(f.state.writes.length, 1);
        assert.equal(u2eTransportProbeReport(f.item).state, 'uncertain');
        assert.match(f.state.sessions.get(U2E_PROBE_SESSION_KEY), /^pending:/);
        await assert.rejects(f.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
        assert.equal(f.state.writes.length, 1);
    }
});

test('a new runtime wrapper still honors the durable claim after the first wrapper completed', async () => {
    const first = fixture();
    assert.equal((await first.insert()).state, 'complete');
    const second = fixture({ state: { sessions: first.state.sessions } });
    await assert.rejects(second.insert(), { code: 'U2E_PROBE_ALREADY_ATTEMPTED' });
    assert.equal(second.state.writes.length, 0);
    assert.deepEqual(second.state.sessionWrites, []);
});
