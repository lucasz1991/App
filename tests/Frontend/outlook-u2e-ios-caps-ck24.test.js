import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody, insertUniversalComposeDocument,
    universalComposeReport, UNIVERSAL_COMPOSE_SESSION_KEY } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalDiagnosticReplayHtml, validateUniversalRedactedFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const read = name => JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/' + name, import.meta.url), 'utf8'));
const base = read('source.json'), manifests = read('ios.ck24.manifest.json');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
function pair(name = 'ios.synthetic.redacted.json') {
    const fixture = read(name); assert.ok(validateUniversalRedactedFixture(fixture));
    const expected = universalDiagnosticReplayHtml(fixture.expectedHtml), actual = universalDiagnosticReplayHtml(fixture.html);
    const version = /rt-sig-u2-([0-9a-f]{24})/.exec(expected)[1];
    const source = validateUniversalComposeDocument({ ...base, kind: 'signature', html: expected, version,
        marker: 'rt-sig-u2-' + version, header: 'u2e-' + version, sourceHash: sha(expected) });
    return { fixture, source, actual };
}
const state = (html, source, host = 'OutlookIOS') => universalComposeStateFromBody(source, html, 'newMail', { host });
function mutate(change) {
    const p = pair(), { document } = parseHTML(p.actual), caps = [...document.querySelectorAll('td.rt-dispo-cap')];
    change(caps[0], caps[0].querySelector('div'), document, caps);
    return { ...p, actual: document.toString() };
}

test('C-K24 authenticated native inputs remain immutable history rather than new v2.5 acceptance', () => {
    for (const meta of manifests.pairs) {
        const fixture = read(meta.file); assert.ok(validateUniversalRedactedFixture(fixture));
        assert.equal(sha(fixture.html), meta.actualSha256); assert.equal(sha(fixture.expectedHtml), meta.expectedSha256);
        assert.equal(meta.host, 'OutlookIOS'); assert.equal(meta.checkpoint, 'caps.structure');
        const own = state(universalDiagnosticReplayHtml(fixture.html), read('signature.json'));
        assert.equal(own.present, true); assert.equal(own.current, false); assert.equal(own.checkpoint, 'state.version');
        assert.throws(() => pair(meta.file), { code: 'U2E_SOURCE_INVALID' });
    }
});

test('synthetic v2.5 iOS projection remains current only with canonical iOS context', () => {
    const p = pair(); assert.equal(state(p.actual, p.source).current, true);
    for (const host of [null, 'Outlook', 'OutlookWebApp', 'OutlookAndroid', 'unknown', 'ios', 'outlookios']) {
        assert.equal(state(p.actual, p.source, host).checkpoint, 'caps.structure', String(host));
    }
    assert.equal(universalComposeStateFromBody(p.source, p.actual, 'newMail').checkpoint, 'caps.structure');
});

test('C-K24 accepts the exact empty/NBSP mobile wrapper while keeping source admission strict', () => {
    for (const value of ['', '&nbsp;', '&#160;', '&#xa0;', '\u00a0']) {
        const p = mutate((_td, div) => { div.innerHTML = value; });
        assert.equal(state(p.actual, p.source).current, true, value);
    }
    const sourceDom = parseHTML(base.html).document;
    for (const td of sourceDom.querySelectorAll('td.rt-dispo-cap')) {
        td.innerHTML = '<div class="rt-dispo-cap" dir="ltr" style="line-height:8px;font-size:1px;font-family:arial, sans-serif;color:#142231">&nbsp;</div>';
    }
    const changedSource = { ...base, html: sourceDom.toString() };
    assert.throws(() => validateUniversalComposeDocument(changedSource), { code: 'U2E_SOURCE_INVALID' });
    assert.throws(() => validateUniversalComposeDocument({ ...changedSource, host: 'OutlookIOS' }), { code: 'U2E_SOURCE_INVALID' });
    assert.equal(universalComposeStateFromBody(changedSource, pair().actual, 'newMail', { host: 'OutlookIOS' }).checkpoint, 'source.invalid');
    const plain = mutate((td, div) => { div.remove(); td.innerHTML = '&nbsp;'; });
    assert.equal(state(plain.actual, plain.source).current, true);
});

test('C-K24 does not grant generic DIV, nested, active or authored cap content', () => {
    for (const change of [
        (_td, div) => div.removeAttribute('class'), (_td, div) => div.setAttribute('class', 'foreign'),
        (_td, div) => div.setAttribute('class', 'rt-dispo-cap extra'), (_td, div) => div.setAttribute('dir', 'rtl'),
        (_td, div) => div.setAttribute('onclick', 'PRIVATE'), (_td, div) => div.setAttribute('id', 'foreign'),
        (_td, div) => { div.textContent = 'AUTHORED'; }, (_td, div) => { div.innerHTML = '<span>&nbsp;</span>'; },
        (_td, div) => { div.innerHTML = '<br>'; }, (_td, div) => { div.innerHTML = '<!--PRIVATE-->'; },
        (td, div) => td.appendChild(div.cloneNode(true)), (td, div) => { td.innerHTML = '&nbsp;' + div.outerHTML; },
        (td, div) => { td.innerHTML = div.outerHTML + '&nbsp;'; },
        (_td, div) => { div.innerHTML = '&nbsp;&nbsp;'; },
        (_td, div) => { div.style.transform = 'scale(0)'; }, (_td, div) => { div.style.padding = '1px'; },
        (_td, div) => { div.style.backgroundColor = '#ffffff'; }, (_td, div) => { div.style.fontSize = '2px'; },
        (_td, div) => { div.style.lineHeight = '9px'; }, (_td, div) => { div.style.fontFamily = 'foreign'; },
        (_td, div) => { div.style.color = '#ffffff'; }, (td) => { td.className = 'foreign'; },
    ]) {
        const p = mutate(change);
        assert.equal(state(p.actual, p.source).current, false);
    }
});

test('C-K24 preserves exact cap geometry, red material and every other evidence check', () => {
    for (const change of [
        td => { td.style.height = '9px'; }, td => { td.style.height = '0px'; }, td => { td.style.removeProperty('height'); },
        td => { td.style.lineHeight = '9px'; }, td => { td.style.padding = '1px'; }, td => { td.style.backgroundColor = '#ffffff'; },
        (_td, div) => { div.style.display = 'none'; }, (_td, div) => { div.style.opacity = '0'; },
        (_td, _div, document) => document.querySelector('img').setAttribute('width', '185'),
        (_td, _div, document) => document.querySelector('a').setAttribute('href', 'https://other.invalid/'),
        (_td, _div, document) => { document.querySelector('td.rt-col-direct').style.width = '281px'; },
        (td) => td.setAttribute('colspan', '3'),
    ]) { const p = mutate(change); assert.equal(state(p.actual, p.source).current, false); }
});

const success = value => ({ status: 'succeeded', value });
function nativeWriter(hostName = 'OutlookIOS', platform = 'iOS') {
    const p = pair(), data = { html: '<p><br></p>', sessions: new Map(), bodyWrites: 0, headerWrites: 0, forbidden: [] };
    const item = { from: { getAsync: cb => cb(success({ emailAddress: 'employee@example.invalid' })) },
        getComposeTypeAsync: cb => cb(success({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, cb) => cb(success(data.sessions.get(key) || '')),
            setAsync: (key, value, cb) => { data.sessions.set(key, value); cb(success()); } },
        internetHeaders: { setAsync: (_headers, cb) => { data.headerWrites++; cb(success()); } },
        body: { getTypeAsync: cb => cb(success('html')), getAsync: (_format, cb) => cb(success(data.html)),
            setSignatureAsync: (_html, _options, cb) => { data.bodyWrites++; data.html = p.actual; cb(success()); },
            setAsync: () => data.forbidden.push('body replacement') }, send: () => data.forbidden.push('send') };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform,
        requirements: { isSetSupported: () => true }, mailbox: { item, userProfile: { emailAddress: 'employee@example.invalid' }, diagnostics: { hostName } } } };
    const binding = { schema: 1, mailboxAddress: 'employee@example.invalid', senderAddress: 'employee@example.invalid', allowedSenderAddresses: ['employee@example.invalid'] };
    return { ...p, item, office, data, insert: () => insertUniversalComposeDocument(office, item, p.source, { binding }) };
}

test('C-K24 native writer derives canonical host, confirms material once and keeps the old mobile header contract', async () => {
    const f = nativeWriter();
    assert.equal((await f.insert()).status, 'applied');
    assert.equal(f.data.bodyWrites, 1); assert.equal(f.data.headerWrites, 0); assert.deepEqual(f.data.forbidden, []);
    assert.equal(universalComposeReport(f.item).state, 'complete'); assert.equal(universalComposeReport(f.item).code, undefined);
    assert.equal(f.data.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), 'applied:' + f.source.version + ':signature');
    assert.equal((await f.insert()).status, 'already-present'); assert.equal(f.data.bodyWrites, 1);
    for (const host of ['unknown', 'Outlook', 'OutlookAndroid']) {
        const unknown = nativeWriter(host);
        await assert.rejects(unknown.insert(), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED' });
        assert.equal(universalComposeReport(unknown.item).checkpoint, 'caps.structure');
        assert.equal(unknown.data.bodyWrites, 1);
        assert.match(unknown.data.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), /^pending:/);
        await assert.rejects(unknown.insert(), { code: 'U2E_INSERT_UNCERTAIN' });
        assert.equal(unknown.data.bodyWrites, 1);
    }
});
