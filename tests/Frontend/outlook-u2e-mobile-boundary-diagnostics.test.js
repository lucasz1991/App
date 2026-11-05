import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { universalComposeBoundaryFixture, validateUniversalRedactedFixture, u2eDiagnosticHash } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { insertUniversalComposeDocument, universalComposeReport, universalComposeStateFromBody,
    UNIVERSAL_COMPOSE_SESSION_KEY } from '../../resources/js/outlook-addin/u2e-compose.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
const good = value => ({ status: 'succeeded', value });
const bad = { status: 'failed', error: { code: 'PRIVATE-HOST-ERROR' } };
const errorOf = promise => promise.then(() => null, error => error);
const current = '<div><br></div>', quoted = '<hr><div id="divRplyFwdMsg">PRIVATE-QUOTE</div><div>PRIVATE-HISTORY</div>';
const mobilePrefix = current + '<div id="ms-outlook-mobile-body-separator-line"><br></div><div id="ms-outlook-mobile-signature">Gesendet von Outlook für iOS</div>';

function fixture({ host = 'OutlookIOS', version = '4.2538.0', type = 'newMail', html = current, headerFailure = false, bodyFailure = false, scoped = false } = {}) {
    const state = { html, reads: [], writes: [], headers: [], sessions: new Map(), sessionWrites: [], forbidden: [] };
    const item = {
        from: { getAsync: cb => cb(good({ emailAddress: 'it-technik@rail-time.de' })) },
        getComposeTypeAsync: cb => cb(good({ composeType: type })),
        sessionData: { getAsync: (key, cb) => cb(good(state.sessions.get(key) || '')),
            setAsync: (key, value, cb) => { state.sessionWrites.push([key, value]); state.sessions.set(key, value); cb(good()); } },
        internetHeaders: { setAsync: (headers, cb) => { state.headers.push(headers); cb(headerFailure ? bad : good()); } },
        body: {
            getTypeAsync: cb => cb(good('html')),
            getAsync: (format, options, callback) => {
                const cb = callback || options;
                state.reads.push({ format, options: callback ? options : null });
                cb(bodyFailure ? bad : good(state.html));
            },
            setSignatureAsync: (html, options, cb) => {
                state.writes.push({ html, options });
                state.html = scoped || type === 'newMail' ? html : html + quoted;
                cb(good());
            },
            setAsync: () => state.forbidden.push('replace'), prependAsync: () => state.forbidden.push('prepend'),
        },
        send: () => state.forbidden.push('send'), saveAsync: () => state.forbidden.push('save'),
    };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, MailboxEnums: { BodyMode: { HostConfig: 1 } }, context: {
        platform: host === 'OutlookIOS' ? 'iOS' : host === 'OutlookAndroid' ? 'Android' : host === 'OutlookWebApp' ? 'OfficeOnline' : 'PC',
        requirements: { isSetSupported: () => false }, mailbox: { item, diagnostics: { hostName: host, hostVersion: version },
            userProfile: { emailAddress: 'it-technik@rail-time.de' } },
    } };
    const binding = { schema: 1, mailboxAddress: 'it-technik@rail-time.de', senderAddress: 'it-technik@rail-time.de', allowedSenderAddresses: ['it-technik@rail-time.de'] };
    return { state, item, office, binding, insert: () => insertUniversalComposeDocument(office, item, source, { binding }) };
}

test('C-K22 boundary diagnostics serialize bounded topology and finite IDs, with zero authored or quoted tokens', () => {
    const privateHtml = '<html><head><style>PRIVATE-CSS</style></head><body><div class="PRIVATE-CLASS" id="PRIVATE-ID" style="color:red">PRIVATE-TEXT'
        + '<a href="https://private.invalid/token">PRIVATE-URL</a><img src="https://private.invalid/image"><span title="PRIVATE-TITLE">secret@example.invalid</span>'
        + '<div id="x_x_ms-outlook-mobile-body-separator-line"><br></div><div id="x_ms-outlook-mobile-signature">PRIVATE-SIGNATURE</div>'
        + '<hr><div id="x_divRplyFwdMsg">PRIVATE-HEADER</div><blockquote cite="https://private.invalid/quote">PRIVATE-QUOTE</blockquote>'
        + '<div hidden><table><tr><td>PRIVATE-HIDDEN</td></tr></table></div></div></body></html>';
    const diagnostic = universalComposeBoundaryFixture(source.html, privateHtml);
    assert.equal(diagnostic.format, 'u2e-redacted-compose-boundary-v1');
    assert.match(diagnostic.html, /^<div data-rt-boundary="1"><div>/);
    assert.match(diagnostic.html, /id="ms-outlook-mobile-body-separator-line"/);
    assert.match(diagnostic.html, /<blockquote type="cite"><\/blockquote>/);
    assert.doesNotMatch(diagnostic.html, /PRIVATE|secret|@|https|class|style|href|src|title|x_/);
    assert.equal(diagnostic.html.replace(/<[^>]*>/g, ''), '');
    assert.deepEqual(validateUniversalRedactedFixture(diagnostic), diagnostic);
    assert.equal(diagnostic.sourceLength, privateHtml.length);
});

test('C-K22 untrusted boundary fixture rejects text, attributes, comments, malformed roots and unknown marker IDs', () => {
    const original = universalComposeBoundaryFixture(source.html, '<div><br></div>');
    for (const html of [
        '<div data-rt-boundary="1">SECRET</div>', '<div data-rt-boundary="1"><!--PRIVATE--></div>',
        '<div data-rt-boundary="1"><div style="color:red"></div></div>', '<div data-rt-boundary="1"><a></a></div>',
        '<div data-rt-boundary="1"><span id="PRIVATE"></span></div>', '<div data-rt-boundary="1"><div id="x_divrplyfwdmsg"></div></div>',
        '<div data-rt-boundary="1"><span type="cite"></span></div>', '<div data-rt-boundary="1"><div data-rt-boundary="1"></div></div>',
        '<div data-rt-boundary="1"><br></div><div></div>', '<div data-rt-boundary="1">' + '<div>'.repeat(64) + '</div>'.repeat(64) + '</div>',
    ]) assert.equal(validateUniversalRedactedFixture({ ...original, html, sha256: u2eDiagnosticHash(html) }), null, html);
    assert.equal(validateUniversalRedactedFixture({ ...original, sourceLength: 500001 }), null);
    assert.equal(validateUniversalRedactedFixture({ ...original, sha256: '0'.repeat(64) }), null);
    assert.equal(validateUniversalRedactedFixture({ ...original, private: 'PRIVATE' }), null);
    assert.equal(universalComposeBoundaryFixture(source.html, '<div>'.repeat(65) + '</div>'.repeat(65)), null);
    assert.equal(universalComposeBoundaryFixture(source.html, '<br>'.repeat(4096)), null);
});

test('C-K22 boundary validators require canonical tokens without gaps, alternate spellings or self-closing forms', () => {
    const original = universalComposeBoundaryFixture(source.html, '<p></p><br>');
    const wrap = inner => '<div data-rt-boundary="1">' + inner + '</div>';
    for (const inner of ['<p></p><br>', '<blockquote id="divrplyfwdmsg" type="cite"></blockquote>',
        '<blockquote type="cite" id="divrplyfwdmsg"><hr></blockquote>']) {
        const html = wrap(inner);
        assert.ok(validateUniversalRedactedFixture({ ...original, html, sha256: u2eDiagnosticHash(html) }));
    }
    for (const inner of ['<p></p> <br>', '<p></p>\n<br>', '<p></p>\t<br>', '<p></p> ', '<P></P>',
        '<div id=divrplyfwdmsg></div>', "<div id='divrplyfwdmsg'></div>", '<div id="div&#114;plyfwdmsg"></div>',
        '<blockquote type="c&#105;te"></blockquote>', '<br/>', '<hr />', '<p/>', '<span />',
        '<p></p id="divrplyfwdmsg">', '<br></br>', '<div id="divrplyfwdmsg" id="stopspelling"></div>']) {
        const html = wrap(inner);
        assert.equal(validateUniversalRedactedFixture({ ...original, html, sha256: u2eDiagnosticHash(html) }), null, inner);
    }
});

test('C-K22 unknown mobile/OWA reply boundaries capture topology before any session, header or body mutation', async () => {
    for (const host of ['OutlookIOS', 'OutlookAndroid', 'OutlookWebApp']) for (const type of ['reply', 'forward']) {
        const f = fixture({ host, version: '', type, html: '<div id="ms-outlook-mobile-body-separator-line"><br></div><div>PRIVATE-USER@private.invalid</div>' });
        const error = await errorOf(f.insert()), report = universalComposeReport(f.item);
        assert.equal(error.code, 'U2E_NEW_DRAFT_REQUIRED');
        assert.equal(report.phase, 'preflight'); assert.equal(report.step, 'snapshot.region'); assert.equal(report.checkpoint, 'scope.boundary');
        assert.equal(report.fixture.format, 'u2e-redacted-compose-boundary-v1');
        assert.equal(report.fixture.html.includes('ms-outlook-mobile-body-separator-line'), true);
        assert.doesNotMatch(JSON.stringify(report) + JSON.stringify(error), /PRIVATE-USER|private.invalid/);
        assert.deepEqual(f.state.headers, []); assert.deepEqual(f.state.sessionWrites, []); assert.deepEqual(f.state.writes, []);
    }
});

test('C-K22 boundary fixture is restricted to the pilot and known hosts', async () => {
    const outside = fixture({ host: 'Outlook', type: 'reply', html: '<div>PRIVATE</div>' });
    await errorOf(outside.insert()); assert.equal(universalComposeReport(outside.item).fixture, undefined);
    const f = fixture({ type: 'forward', html: '<div>PRIVATE</div>' });
    f.binding.mailboxAddress = 'other@example.invalid';
    f.binding.senderAddress = 'other@example.invalid'; f.binding.allowedSenderAddresses = ['other@example.invalid'];
    f.office.context.mailbox.userProfile.emailAddress = 'other@example.invalid';
    f.item.from.getAsync = cb => cb(good({ emailAddress: 'other@example.invalid' }));
    await errorOf(f.insert()); assert.equal(universalComposeReport(f.item).fixture, undefined);
});

test('C-K22 mobile replies use the guaranteed HostConfig scope consistently for preflight, comparison and readback', async () => {
    for (const host of ['OutlookIOS', 'OutlookAndroid']) {
        const f = fixture({ host, type: 'reply', scoped: true });
        assert.equal((await f.insert()).current, true);
        assert.equal(f.state.reads.length, 3);
        assert.deepEqual(f.state.reads.map(value => value.options), [{ bodyMode: 1 }, { bodyMode: 1 }, { bodyMode: 1 }]);
        assert.equal(f.state.headers.length, 0); assert.equal(universalComposeReport(f.item).headerWritten, false);
        assert.equal(f.state.writes.length, 1); assert.deepEqual(f.state.forbidden, []);
        assert.match(f.state.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), /^applied:/);
        assert.equal(universalComposeStateFromBody(source, source.html, 'reply').current, false);
        assert.equal(universalComposeStateFromBody(source, source.html, 'reply', { office: f.office, currentMessageOnly: true }).current, true);
    }
});

test('C-K22 forwards retain structural quote proof; old or unknown hosts cannot borrow reply-only scope', async () => {
    const f = fixture({ type: 'forward', html: mobilePrefix + quoted });
    assert.equal((await f.insert()).current, true);
    assert.equal(f.state.reads.length, 3); assert.equal(f.state.reads.every(value => value.options === null), true);
    assert.equal(f.state.html.endsWith(quoted), true);
    for (const host of ['OutlookWebApp', 'Outlook', 'OutlookIOS']) {
        const other = fixture({ host, version: '', type: 'reply' });
        assert.equal(universalComposeStateFromBody(source, source.html, 'reply', { office: other.office, currentMessageOnly: true }).current, false);
        const error = await errorOf(other.insert()); assert.equal(error.code, 'U2E_NEW_DRAFT_REQUIRED');
        assert.equal(other.state.reads[0].options, null);
    }
    assert.equal(universalComposeStateFromBody(source, source.html, 'forward', { office: f.office, currentMessageOnly: true }).current, false);
});

test('C-K22 a failed HostConfig read never silently retries FullBody or mutates the message', async () => {
    const f = fixture({ type: 'reply', scoped: true, bodyFailure: true });
    assert.equal((await errorOf(f.insert())).code, 'U2E_CONTEXT_UNREADABLE');
    assert.deepEqual(f.state.reads.map(value => value.options), [{ bodyMode: 1 }]);
    assert.deepEqual(f.state.sessionWrites, []); assert.deepEqual(f.state.headers, []); assert.deepEqual(f.state.writes, []);
});

test('C-K22 mobile preserves header-free rule2 routing with an available or absent header API', async () => {
    for (const host of ['OutlookIOS', 'OutlookAndroid']) for (const missing of [false, true]) {
        const f = fixture({ host });
        if (missing) delete f.item.internetHeaders;
        else f.item.internetHeaders.setAsync = () => { throw new Error('Mobile must never stamp the desktop-rule header'); };
        assert.equal((await f.insert()).status, 'applied');
        assert.deepEqual(f.state.headers, []);
        assert.equal(universalComposeReport(f.item).headerWritten, false);
        assert.equal(f.state.writes.length, 1);
        assert.deepEqual(f.state.forbidden, []);
    }
});
