import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { parseHTML } from 'linkedom';
import { parseManagedHtml } from '../../resources/js/outlook-addin/managed-template.js';
import { currentComposeBodyHtml } from '../../resources/js/outlook-addin/compose-template.js';
import { u2eProbeDocument } from '../../resources/js/outlook-addin/u2e-editor-probe.js';
import { UNIVERSAL_COMPOSE_SESSION_KEY, validateUniversalComposeDocument, universalComposeStateFromBody,
    insertUniversalComposeDocument, universalComposeBlocked, universalComposeReport, universalComposePresentationEvidence } from '../../resources/js/outlook-addin/u2e-compose.js';

const fixed = u2eProbeDocument();
function document(kind = 'combined', extra = {}) {
    let html = fixed.html.replace('id="' + fixed.id + '" width="260"', 'id="' + fixed.id + '-cell" width="260"');
    if (kind === 'signature') {
        const { elements } = parseManagedHtml(html);
        const table = elements.findIndex(node => node.tag === 'table' && node.attrs.id === fixed.id);
        const first = elements.find(node => node.tag === 'tr' && node.parent === table);
        html = html.slice(0, first.start) + html.slice(first.end);
    }
    const version = fixed.id.slice('rt-sig-u2-'.length);
    return { schema: 1, kind, html, version, marker: fixed.id, header: 'u2e-' + version, sourceHash: 'a'.repeat(64), media: [], ...extra };
}
function mutate(doc, change) {
    const { document: dom } = parseHTML(doc.html); change(dom);
    return { ...doc, html: dom.toString() };
}
const success = value => ({ status: 'succeeded', value });
const failed = code => ({ status: 'failed', error: { code, message: 'private host detail' } });
const errorOf = promise => promise.then(() => null, error => error);
const flush = async until => { for (let i = 0; i < 500 && !until(); i++) await Promise.resolve(); assert.equal(until(), true); };
const readbackFixtures = new URL('../Fixtures/mail/u2e-readback/', import.meta.url);
const fixtureFile = name => fs.readFileSync(new URL(name, readbackFixtures), 'utf8');
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');

function fixture(options = {}) {
    const state = { html: '<html><head><style>p {margin:0}</style></head><body><p><br></p></body></html>',
        composeType: 'newMail', format: 'html', sender: 'employee@example.invalid', sessions: new Map(), bodyReads: 0,
        bodyWrites: [], headerWrites: [], sessionReads: [], sessionWrites: [], guardCalls: 0, forbidden: [], ...options.state };
    let office;
    const item = {
        from: { getAsync: cb => cb(success({ emailAddress: state.sender })) },
        getComposeTypeAsync: cb => cb(success({ composeType: state.composeType })),
        sessionData: {
            getAsync(key, callback) {
                state.sessionReads.push(key);
                if (options.sessionRead?.({ key, callback, state, item, office }) === false) return;
                callback(success(state.sessions.get(key) ?? ''));
            },
            setAsync(key, value, callback) {
                state.sessionWrites.push({ key, value });
                if (options.sessionWrite?.({ key, value, callback, state, item, office }) === false) return;
                state.sessions.set(key, value); callback(success());
            },
        },
        internetHeaders: { setAsync(headers, callback) {
            state.headerWrites.push(headers);
            if (options.headerWrite?.({ headers, callback, state, item, office }) === false) return;
            callback(success());
        } },
        body: {
            getTypeAsync: cb => cb(success(state.format)),
            getAsync(format, callback) {
                assert.equal(format, 'html'); state.bodyReads++;
                if (options.bodyRead?.({ callback, state, item, office }) === false) return;
                callback(success(state.html));
            },
            setAsync: () => state.forbidden.push('body-replacement'),
        },
        send: () => state.forbidden.push('send'),
        addFileAttachmentFromBase64Async: () => state.forbidden.push('attachment'),
        disableClientSignatureAsync: () => state.forbidden.push('disable-native'),
    };
    function bodyWrite(method) {
        return (html, writeOptions, callback) => {
            state.bodyWrites.push({ method, html, options: writeOptions });
            if (options.bodyWrite) return options.bodyWrite({ method, html, callback, state, item, office });
            let point;
            if (method === 'prependAsync') {
                const body = /<body\b[^>]*>/i.exec(state.html);
                point = body ? body.index + body[0].length : 0;
            } else if (state.composeType !== 'newMail') {
                const prefix = currentComposeBodyHtml(state.html, state.composeType);
                const hr = prefix && /<hr\b[^>]*>\s*$/i.exec(prefix);
                point = hr ? hr.index : prefix?.length;
            } else {
                point = /<\/body\s*>/i.exec(state.html)?.index ?? state.html.length;
            }
            assert.equal(typeof point, 'number');
            state.html = state.html.slice(0, point) + html + state.html.slice(point);
            callback(success());
        };
    }
    item.body.prependAsync = bodyWrite('prependAsync');
    item.body.setSignatureAsync = bodyWrite('setSignatureAsync');
    office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: {
        platform: options.platform ?? 'PC', requirements: { isSetSupported: (set, version) => set === 'Mailbox' && version === '1.8' },
        mailbox: { item, userProfile: { emailAddress: 'employee@example.invalid' } },
    } };
    const binding = { schema: 1, mailboxAddress: 'employee@example.invalid', senderAddress: 'employee@example.invalid', allowedSenderAddresses: ['employee@example.invalid'] };
    const beforeWrite = () => { state.guardCalls++; options.guard?.({ state, item, office, binding }); };
    const insert = (doc = document(), extra = {}) => insertUniversalComposeDocument(office, item, doc, { binding, beforeWrite, ...extra });
    return { office, item, state, binding, beforeWrite, insert };
}

test('trusted source envelopes are immutable and do not accept arbitrary delivery or media metadata', () => {
    const original = document(), valid = validateUniversalComposeDocument(original);
    assert.equal(valid.kind, 'combined'); assert.equal(valid.html, original.html);
    assert.ok(Object.isFrozen(valid)); assert.ok(Object.isFrozen(valid.media));
    assert.equal(validateUniversalComposeDocument(valid), valid);
    for (const extra of [{ schema: 2 }, { kind: 'template' }, { version: 'a'.repeat(16) }, { marker: 'foreign' }, { header: 'other' },
        { sourceHash: 'not-hashed' }, { media: [{ base64: 'x' }] }, { arbitraryHtml: true }, { html: fixed.html.repeat(3) }]) {
        assert.throws(() => validateUniversalComposeDocument({ ...document(), ...extra }), { code: 'U2E_SOURCE_INVALID' });
    }
    assert.equal(validateUniversalComposeDocument(document('signature')).kind, 'signature');
});

test('the source validates the approved geometry and excludes hidden or active body content', () => {
    const mutations = [
        dom => dom.querySelector('.rt-col-direct').setAttribute('width', '281'),
        dom => dom.querySelector('.rt-col-dispo').removeAttribute('rowspan'),
        dom => dom.querySelector('.rt-train').setAttribute('height', '84'),
        dom => dom.querySelector('.rt-train').setAttribute('src', 'https://evil.example/other.png'),
        dom => dom.querySelector('.rt-dispo').remove(),
        dom => dom.querySelector('.rt-col-person').removeAttribute('id'),
        dom => dom.querySelector('.rt-shell').setAttribute('align', 'left'),
        dom => dom.querySelector('.rt-shell').setAttribute('style', 'width:1040px;display:none'),
        dom => dom.querySelector('.rt-shell').setAttribute('onclick', 'unsafe()'),
        dom => dom.querySelector('.rt-shell').insertAdjacentHTML('afterbegin', '<style>body{display:none}</style>'),
        dom => dom.querySelector('.rt-shell').setAttribute('dir', 'rtl'),
    ];
    for (const change of mutations) assert.throws(() => validateUniversalComposeDocument(mutate(document(), change)), { code: 'U2E_SOURCE_INVALID' });
});

test('the redacted real 22:37 serialization is current against its independently projected source', async () => {
    const manifest = JSON.parse(fixtureFile('manifest.json'));
    for (const [name, evidence] of Object.entries(manifest.artifacts)) {
        const content = fixtureFile(name);
        assert.equal(sha256(content), evidence.sha256);
        assert.equal(Buffer.byteLength(content), evidence.bytes);
    }
    const source = JSON.parse(fixtureFile('source.json')), actual = fixtureFile('new-outlook-2237.redacted.html');
    validateUniversalComposeDocument(source);
    assert.equal(universalComposeStateFromBody(source, actual, 'newMail').current, true);
    assert.deepEqual(universalComposePresentationEvidence(actual), manifest.presentation.outlook);
    assert.equal((actual.match(/\bbgcolor=/g) || []).length, 0, 'actual Outlook used CSS background colors');
    assert.equal((actual.match(/<img\b/g) || []).length, 13);
    const f = fixture({ bodyWrite({ callback, state }) { state.html = actual; callback(success()); } });
    assert.equal((await f.insert(source)).status, 'applied');
    assert.equal(f.state.bodyWrites.length, 1);
    for (const change of [
        dom => dom.querySelector('table[width="247"]').setAttribute('style', 'background-color:rgb(0,0,255)'),
        dom => dom.querySelector('img[src$="14d7943c88.png"]').setAttribute('height', '84'),
        dom => dom.querySelector('td[rowspan="2"]').removeAttribute('rowspan'),
        dom => { const shell = dom.querySelector('table'); shell.removeAttribute('align'); shell.setAttribute('style', 'width:1040px'); },
        dom => dom.querySelector('a').setAttribute('href', 'https://mail.example.test/changed'),
    ]) assert.equal(universalComposeStateFromBody(source, mutate({ html: actual }, change).html, 'newMail').current, false);
    assert.equal(universalComposeStateFromBody(source, actual.replace('Fixture legal', 'Changed legal'), 'newMail').current, false);
});

test('the actual older Word structure has finite color evidence and remains incompatible with current U2e', () => {
    const manifest = JSON.parse(fixtureFile('manifest.json')), word = fixtureFile('legacy-word.redacted.html');
    const evidence = universalComposePresentationEvidence(word);
    assert.deepEqual(evidence, manifest.presentation.word);
    assert.equal(evidence.readable, true); assert.equal(evidence.tables, 20); assert.equal(evidence.centeredTables, 0);
    assert.deepEqual(evidence.colors, { unset: 44, '#e4002b': 22, '#f3f5f7': 2 });
    const state = universalComposeStateFromBody(JSON.parse(fixtureFile('source.json')), word, 'newMail');
    assert.equal(state.current, false); assert.equal(state.present, false);
    assert.equal(manifest.legacyWord.current, false);
    assert.equal(manifest.legacyWord.incompatibilities.length, 6);
});

test('optional immutable private exports match the actual offline PHP source without storing private content', {
    skip: !process.env.U2E_PRIVATE_SOURCE_JSON || !process.env.U2E_PRIVATE_EXPORT_ROOT,
}, () => {
    const manifest = JSON.parse(fixtureFile('manifest.json'));
    const paths = {
        outlook: 'belege/umsetzung/C/pruefung-claude-2311/entwurf-neues-outlook-2237.html',
        word: 'belege/tests-2026-11-03/u2/u2-antwort-word-umgeschrieben.html',
        ios: 'belege/umsetzung/C/pruefung-claude-2311/entwurf-outlook-ios-2240.html',
    };
    const exports = {};
    for (const [name, path] of Object.entries(paths)) {
        const input = fs.readFileSync(process.env.U2E_PRIVATE_EXPORT_ROOT + '/' + path);
        assert.equal(sha256(input), manifest.inputs[name].sha256);
        exports[name] = input.toString('utf8');
        assert.deepEqual(universalComposePresentationEvidence(exports[name]), manifest.presentation[name]);
    }
    const wire = JSON.parse(execFileSync(process.env.U2E_PHP_BINARY || 'C:/xampp/php/php.exe',
        ['tests/Fixtures/mail/u2e-readback/project-private-source.php', process.env.U2E_PRIVATE_SOURCE_JSON], { encoding: 'utf8', maxBuffer: 1000000 }));
    const source = validateUniversalComposeDocument(wire.document);
    assert.equal(wire.queryCount, 0);
    assert.equal(sha256(source.html), manifest.sourceProjection.sourceHtmlSha256);
    assert.equal(universalComposeStateFromBody(source, exports.outlook, 'newMail').current, true);
    assert.equal(universalComposeStateFromBody(source, exports.word, 'newMail').current, false);
    assert.equal(universalComposeStateFromBody(source, exports.ios, 'newMail').present, false);
});

test('pure state ignores quoted U2e and recognizes a signature inside a matching combined block', () => {
    const combined = document(), signature = document('signature');
    const quoteOnly = '<html><body><p><br></p><hr><div id="divRplyFwdMsg">Old message</div><blockquote>' + combined.html + '</blockquote></body></html>';
    assert.deepEqual(universalComposeStateFromBody(combined, quoteOnly, 'reply'), { readable: true, present: false, current: false, kind: null, version: null,
        checkpoint: 'state.present', difference: { key: 'state.present', index: null, expected: true, actual: false } });
    assert.equal(universalComposeStateFromBody(signature, combined.html, 'newMail').current, true);
    assert.equal(universalComposeStateFromBody(combined, signature.html, 'newMail').current, false);
    assert.equal(universalComposeStateFromBody(combined, '<p>Unknown reply boundary</p>', 'reply').readable, false);
});

test('pure state tolerates only known Word or Rooster serialization without losing material', () => {
    const doc = document();
    let word = doc.html.replaceAll('id="' + doc.marker + '"', 'id="x_' + doc.marker + '"').replaceAll('width:260px', 'width:195pt');
    word = word.replace('<table role="presentation"', '<table class="MsoNormalTable" role="presentation"').replace(' class="rt-shell"', '');
    assert.equal(universalComposeStateFromBody(doc, word, 'newMail').current, true);
    const rooster = mutate(doc, dom => { for (const node of dom.querySelectorAll('[style],[class]')) { node.removeAttribute('style'); node.removeAttribute('class'); } }).html;
    assert.equal(universalComposeStateFromBody(doc, rooster, 'newMail').current, true);
    const entities = doc.html.replaceAll('Geschäftsführung', 'Gesch&auml;ftsf&uuml;hrung').replaceAll('löschen', 'l&ouml;schen');
    assert.equal(universalComposeStateFromBody(doc, entities, 'newMail').current, true, 'equivalent German named entities retain the same signature text');
    for (const change of [dom => dom.querySelector('.rt-train').remove(), dom => dom.querySelector('.rt-col-company').remove(),
        dom => dom.querySelector('.rt-legal').setAttribute('bgcolor', '#ffffff'), dom => dom.querySelector('.rt-contact').textContent = 'Changed contact']) {
        assert.equal(universalComposeStateFromBody(doc, mutate(doc, change).html, 'newMail').current, false);
    }
});

test('equivalent Rooster colors and Word colors/centering use the same source and readback evidence', () => {
    const doc = document();
    const host = mutate(doc, dom => {
        for (const node of dom.querySelectorAll('[bgcolor]')) {
            const value = node.getAttribute('bgcolor');
            const rgb = [1, 3, 5].map(start => Number.parseInt(value.slice(start, start + 2), 16));
            node.setAttribute('style', (node.getAttribute('style') || '').replace(/background-color:[^;]+;?/gi, '') + ';background-color:rgb(' + rgb.join(', ') + ');');
            node.removeAttribute('bgcolor');
        }
    });
    assert.equal(universalComposeStateFromBody(doc, host.html, 'newMail').current, true);
    const word = mutate(host, dom => {
        const shell = dom.querySelector('.rt-shell'); shell.removeAttribute('align');
        shell.setAttribute('style', 'width:780pt;margin-left:auto;margin-right:auto;');
        for (const node of dom.querySelectorAll('[style]')) node.setAttribute('style', node.getAttribute('style').replaceAll('background-color:', 'background:'));
    });
    assert.equal(universalComposeStateFromBody(doc, word.html, 'newMail').current, true);
    const styledSource = validateUniversalComposeDocument(word);
    assert.equal(universalComposeStateFromBody(styledSource, doc.html, 'newMail').current, true, 'source and actual use identical color/centering derivation');
});

test('conflicting color declarations, gradients, images and false centering cannot acknowledge material', () => {
    const doc = document();
    const changes = [
        dom => { const node = dom.querySelector('.rt-dispo-head'); node.setAttribute('style', node.getAttribute('style') + ';background-color:#0000ff;'); },
        dom => { const node = dom.querySelector('.rt-dispo-head'); node.setAttribute('style', node.getAttribute('style') + ';background:linear-gradient(#e4002b,#e4002b);'); },
        dom => { const node = dom.querySelector('.rt-dispo-head'); node.setAttribute('style', node.getAttribute('style') + ';background:#e4002b url(https://example.invalid/image.png);'); },
        dom => { const node = dom.querySelector('.rt-dispo-head'); node.setAttribute('style', node.getAttribute('style') + ';background-image:url(https://example.invalid/image.png);'); },
        dom => { const node = dom.querySelector('.rt-dispo-head'); node.setAttribute('style', node.getAttribute('style') + ';background-image:linear-gradient(#e4002b,#e4002b);'); },
        dom => dom.querySelector('.rt-shell').setAttribute('style', 'width:1040px;margin:0 20px;'),
        dom => dom.querySelector('.rt-shell').setAttribute('style', 'width:1040px;margin:0 auto;float:left;'),
        dom => dom.querySelector('.rt-shell').setAttribute('align', 'left'),
        dom => { const node = dom.querySelector('.rt-shell'); node.removeAttribute('align'); node.setAttribute('style', 'width:1040px;text-align:center;'); },
    ];
    for (const change of changes) {
        const changed = mutate(doc, change);
        assert.equal(universalComposeStateFromBody(doc, changed.html, 'newMail').current, false);
        assert.throws(() => validateUniversalComposeDocument(changed), { code: 'U2E_SOURCE_INVALID' });
    }
});

test('new cell suffixes are required for sources and legacy duplicate IDs remain read-only compatible', () => {
    const doc = document();
    assert.ok(doc.html.includes('id="' + doc.marker + '-cell"'));
    const legacy = doc.html.replaceAll(doc.marker + '-cell', doc.marker);
    assert.equal(universalComposeStateFromBody(doc, legacy, 'newMail').current, true);
    assert.throws(() => validateUniversalComposeDocument({ ...doc, html: legacy }), { code: 'U2E_SOURCE_INVALID' });
    for (const keep of ['table', 'cell']) {
        const readback = mutate(doc, dom => {
            const table = dom.querySelector('.rt-shell'), cell = dom.querySelector('.rt-col-person');
            if (keep === 'table') { cell.removeAttribute('id'); table.setAttribute('id', 'x_' + doc.marker); }
            else { table.removeAttribute('id'); cell.setAttribute('id', 'x_' + doc.marker + '-cell'); }
        });
        assert.equal(universalComposeStateFromBody(doc, readback.html, 'newMail').current, true);
    }
    const wrongPlace = mutate(doc, dom => { dom.querySelector('.rt-col-person').removeAttribute('id'); dom.querySelector('.rt-col-company').setAttribute('id', doc.marker + '-cell'); });
    assert.equal(universalComposeStateFromBody(doc, wrongPlace.html, 'newMail').current, false);
    const wrongTable = mutate(doc, dom => dom.querySelector('.rt-shell').setAttribute('id', doc.marker + '-cell'));
    assert.equal(universalComposeStateFromBody(doc, wrongTable.html, 'newMail').current, false);
});

for (const [platform, kind, method] of [['PC', 'combined', 'prependAsync'], ['OfficeOnline', 'combined', 'prependAsync'],
    ['iOS', 'combined', 'setSignatureAsync'], ['Android', 'combined', 'setSignatureAsync'], ['PC', 'signature', 'setSignatureAsync'], ['iOS', 'signature', 'setSignatureAsync']]) {
    test(platform + ' ' + kind + ' uses one expected write with a durable source-bound claim', async () => {
        const f = fixture({ platform }), doc = document(kind);
        const result = await f.insert(doc);
        assert.equal(result.status, 'applied'); assert.equal(result.current, true);
        assert.equal(f.state.bodyWrites.length, 1); assert.equal(f.state.bodyWrites[0].method, method);
        assert.equal(f.state.bodyWrites[0].html, doc.html); assert.deepEqual(f.state.forbidden, []);
        assert.equal(f.state.sessions.get(UNIVERSAL_COMPOSE_SESSION_KEY), 'applied:' + doc.version + ':' + kind);
        assert.equal(universalComposeBlocked(f.item), false);
        assert.equal(universalComposeReport(f.item).state, 'complete');
        assert.equal(f.state.headerWrites.length, ['iOS', 'Android'].includes(platform) ? 0 : 1);
        if (f.state.headerWrites.length) assert.deepEqual(f.state.headerWrites[0], { 'X-RailTime-Template': doc.header });
        assert.equal((await f.insert(doc)).status, 'already-present');
        assert.equal(f.state.bodyWrites.length, 1);
    });
}

test('a signature-only insertion preserves current user text, user images and links', async () => {
    const author = '<p>Own <strong>formatted</strong> text <a href="https://example.invalid/context">link</a><img src="https://example.invalid/attachment.png" width="40" height="30"></p>';
    const f = fixture({ state: { html: '<html><body>' + author + '</body></html>' } });
    assert.equal((await f.insert(document('signature'))).status, 'applied');
    assert.ok(f.state.html.includes(author));
});

test('only exact matching mobile host signatures are treated as empty for combined insertion', async () => {
    for (const [platform, label] of [['iOS', 'Gesendet von Outlook für iOS'], ['Android', 'Gesendet von Outlook für Android'], ['iOS', 'Get Outlook for iOS'], ['Android', 'Sent from Outlook for Android']]) {
        const f = fixture({ platform, state: { html: '<html><body><div id="Signature"><p><a href="https://aka.ms/AAb9ysg">' + label + '</a></p></div></body></html>' } });
        assert.equal((await f.insert()).status, 'applied');
        assert.equal(f.state.bodyWrites.length, 1); assert.equal(f.state.bodyWrites[0].method, 'setSignatureAsync');
        assert.equal(universalComposeReport(f.item).mobileStandardSignatureIgnored, true);
        assert.deepEqual(f.state.forbidden, []);
    }
    const bad = [
        ['PC', '<p>Gesendet von Outlook für iOS</p>'],
        ['iOS', '<p>Gesendet von Outlook für Android</p>'],
        ['iOS', '<p>Eigener Text</p><p>Gesendet von Outlook für iOS</p>'],
        ['iOS', '<p>Gesendet von Outlook für iOS</p><img src="https://example.invalid/photo.png">'],
        ['iOS', '<p><a href="https://other.example.invalid">Gesendet von Outlook für iOS</a></p>'],
        ['iOS', '<blockquote>Gesendet von Outlook für iOS</blockquote>'],
    ];
    for (const [platform, html] of bad) {
        const f = fixture({ platform, state: { html } });
        assert.equal((await errorOf(f.insert())).code, 'U2E_NEW_DRAFT_REQUIRED');
        assert.equal(f.state.bodyWrites.length, 0); assert.equal(f.state.sessionWrites.length, 0);
        const report = universalComposeReport(f.item);
        assert.equal(report.state, 'rejected'); assert.equal(report.phase, 'preflight');
        assert.equal(report.code, 'U2E_NEW_DRAFT_REQUIRED'); assert.equal(report.bodyLength, html.length);
        assert.equal(report.sourceLength, document().html.length); assert.equal(universalComposeBlocked(f.item), false);
        for (const value of Object.values(report)) assert.equal(typeof value === 'string' && (value.includes('Gesendet') || value.includes('example.invalid')), false);
    }
});

test('combined replies and forwards preserve the complete historical byte suffix including separators', async () => {
    for (const composeType of ['reply', 'forward']) {
        const quote = '<hr><div id="divRplyFwdMsg">Historical &amp; header</div><blockquote>' + document().html + '</blockquote></body></html>';
        const f = fixture({ state: { composeType, html: '<html><body><div><br></div>' + quote } });
        assert.equal((await f.insert()).status, 'applied');
        assert.ok(f.state.html.endsWith(quote));
        assert.equal(f.state.html.split(document().html).length - 1, 2, 'one own block and one unchanged quote');
    }
});

test('new combined insertion refuses current author text, foreign signatures, V17 and unknown reply boundaries', async () => {
    for (const [composeType, html] of [['newMail', '<p>Own message</p>'], ['newMail', '<div id="Signature">Existing signature</div>'],
        ['newMail', '<span>RT-FLUID-LAYOUT-V17B</span>'], ['reply', '<p>Missing quote boundary</p>']]) {
        const f = fixture({ state: { composeType, html } });
        assert.equal((await errorOf(f.insert())).code, 'U2E_NEW_DRAFT_REQUIRED');
        assert.equal(f.state.bodyWrites.length, 0); assert.equal(f.state.headerWrites.length, 0);
    }
});

test('a different U2e version is not silently converted and a lost applied block is not retried', async () => {
    const doc = document();
    const changedVersion = 'b'.repeat(24);
    const previous = doc.html.replaceAll(doc.marker, 'rt-sig-u2-' + changedVersion);
    const stale = fixture({ state: { html: previous } });
    assert.equal((await errorOf(stale.insert())).code, 'U2E_NEW_DRAFT_REQUIRED');
    assert.equal(stale.state.bodyWrites.length, 0);
    const missing = fixture({ state: { sessions: new Map([[UNIVERSAL_COMPOSE_SESSION_KEY, 'applied:' + doc.version + ':combined']]) } });
    assert.equal((await errorOf(missing.insert())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(missing.state.bodyWrites.length, 0);
    assert.equal(universalComposeReport(missing.item).phase, 'preflight');
    assert.equal(universalComposeReport(missing.item).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(universalComposeReport(missing.item).sourceLength, doc.html.length);
    assert.equal(universalComposeReport(missing.item).bodyLength, missing.state.html.length);
});

test('lost IDs and a fresh session cannot turn an existing U2e block into author content and append a duplicate', async () => {
    for (const removeClasses of [false, true]) {
        const old = mutate(document(), dom => {
            for (const node of dom.querySelectorAll('[id]')) node.removeAttribute('id');
            if (removeClasses) for (const node of dom.querySelectorAll('[class]')) node.removeAttribute('class');
        }).html;
        const f = fixture({ state: { html: old } });
        assert.equal((await errorOf(f.insert(document('signature')))).code, 'U2E_NEW_DRAFT_REQUIRED');
        assert.equal(f.state.bodyWrites.length, 0); assert.equal(f.state.headerWrites.length, 0);
    }
});

test('readback refuses a second unmarked U2e material block even after class loss', () => {
    const doc = document();
    const unmarked = mutate(doc, dom => { for (const node of dom.querySelectorAll('[id],[class]')) { node.removeAttribute('id'); node.removeAttribute('class'); } }).html;
    assert.equal(universalComposeStateFromBody(doc, doc.html + unmarked, 'newMail').current, false);
    assert.throws(() => validateUniversalComposeDocument({ ...doc, html: '<p>Outside source text</p>' + doc.html }), { code: 'U2E_SOURCE_INVALID' });
});

test('uncertain legacy and universal claims stop before any new mutation', async () => {
    for (const key of ['railtime.template.inserted.v1', 'railtime.signature.layout.v1', 'railtime.head-probe.v1', 'railtime.u2e-probe.v1', UNIVERSAL_COMPOSE_SESSION_KEY]) {
        const f = fixture({ state: { sessions: new Map([[key, 'pending:old-operation']]) } });
        assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
        assert.equal(f.state.bodyWrites.length, 0); assert.equal(f.state.sessionWrites.length, 0);
    }
});

test('mailbox and sender binding is required without restricting ordinary employees or recipient lists', async () => {
    const sender = fixture({ state: { sender: 'other@example.invalid' } });
    assert.equal((await errorOf(sender.insert())).code, 'SENDER_MISMATCH');
    assert.equal(sender.state.bodyWrites.length, 0);
    const mailbox = fixture(); mailbox.office.context.mailbox.userProfile.emailAddress = 'other@example.invalid';
    assert.equal((await errorOf(mailbox.insert())).code, 'MAILBOX_MISMATCH');
    const plain = fixture({ state: { format: 'text' } });
    assert.equal((await errorOf(plain.insert())).code, 'U2E_HTML_REQUIRED');
});

test('desktop without supported header API refuses the operation; mobile never calls the header API', async () => {
    const desktop = fixture(); delete desktop.item.internetHeaders;
    assert.equal((await errorOf(desktop.insert())).code, 'U2E_HEADER_UNAVAILABLE');
    assert.equal(desktop.state.bodyWrites.length, 0); assert.equal(desktop.state.sessionWrites.length, 0);
    const mobile = fixture({ platform: 'iOS' }); delete mobile.item.internetHeaders;
    assert.equal((await mobile.insert()).status, 'applied');
});

test('material loss and quote damage after the callback keep the claim uncertain without a repair write', async () => {
    for (const damage of [html => html.replace(/<img[^>]*alt="RailTime Zug"[^>]*>/, ''), html => html.replace('width="280"', 'width="281"')]) {
        const f = fixture({ bodyWrite({ html, callback, state }) { state.html = damage(html); callback(success()); } });
        assert.equal((await errorOf(f.insert())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(f.state.bodyWrites.length, 1); assert.equal(universalComposeBlocked(f.item), true);
        assert.equal(universalComposeReport(f.item).phase, 'readback');
        assert.equal(universalComposeReport(f.item).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(universalComposeReport(f.item).bodyLength, f.state.html.length);
        assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
        assert.equal(f.state.bodyWrites.length, 1);
    }
});

test('layout failures report finite checkpoints and only the bound IT pilot receives redacted signature pairs', async () => {
    for (const pilot of [false, true]) {
        const mailbox = pilot ? 'it-technik@rail-time.de' : 'employee@example.invalid';
        const f = fixture({ state: { sender: mailbox }, bodyWrite({ html, callback, state }) {
            state.html = html.replace(/(<img[^>]*class="rt-train"[^>]*height=")83"/, '$184"'); callback(success());
        } });
        f.binding.mailboxAddress = mailbox; f.binding.senderAddress = mailbox; f.binding.allowedSenderAddresses = [mailbox];
        f.office.context.mailbox.userProfile.emailAddress = mailbox;
        const error = await errorOf(f.insert());
        assert.equal(error.code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
        const report = universalComposeReport(f.item);
        assert.equal(report.checkpoint, 'images.height'); assert.equal(report.phase, 'readback');
        assert.equal(Boolean(report.fixture), pilot); assert.equal(f.state.bodyWrites.length, 1);
        if (pilot) {
            assert.equal(report.fixture.format, 'u2e-redacted-html-v1');
            assert.doesNotMatch(JSON.stringify(report.fixture), /hier steht|Guten Tag|mailto:|https:\/\/|data:image|blockquote/);
        }
    }
});

test('first raw evidence text mismatch is hashed before redaction and author loss has its own checkpoint', async () => {
    const source = JSON.parse(fixtureFile('source.json'));
    const textLoss = fixture({ bodyWrite({ html, callback, state }) { state.html = html.replace('Fixture legal', 'Fixture LegaL'); callback(success()); } });
    assert.equal((await errorOf(textLoss.insert(source))).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    const textReport = universalComposeReport(textLoss.item);
    assert.equal(textReport.checkpoint, 'evidence.text'); assert.equal(textReport.difference.key, 'evidence.text.sha256');
    assert.match(textReport.difference.expected, /^sha256:[0-9a-f]{64}:\d+$/);
    assert.notEqual(textReport.difference.expected, textReport.difference.actual);
    assert.doesNotMatch(JSON.stringify(textReport), /Fixture legal|Fixture LegaL/);
    const authorLoss = fixture({ bodyWrite({ html, callback, state }) { state.html = html.replace('Fixture author', 'PRIVATE-AUTHOR'); callback(success()); } });
    assert.equal((await errorOf(authorLoss.insert(source))).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    const authorReport = universalComposeReport(authorLoss.item);
    assert.equal(authorReport.checkpoint, 'author.combined'); assert.doesNotMatch(JSON.stringify(authorReport), /PRIVATE-AUTHOR|Fixture author/);
});

test('first combined-write acknowledgement requires all source author text, links and images to survive', async () => {
    for (const change of [html => html.replace('hier steht der individuelle Nachrichtentext.', ''),
        html => html.replace('hier steht der individuelle Nachrichtentext.', 'Changed by host'),
        html => html.replace('Guten Tag,', '')]) {
        const f = fixture({ bodyWrite({ html, callback, state }) { state.html = change(html); callback(success()); } });
        assert.equal((await errorOf(f.insert())).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(f.state.bodyWrites.length, 1); assert.equal(universalComposeReport(f.item).state, 'uncertain');
    }
    const word = fixture({ bodyWrite({ html, callback, state }) { state.html = html.replaceAll('<p style=', '<p class="MsoNormal" style='); callback(success()); } });
    assert.equal((await word.insert()).status, 'applied');
    const edited = document().html.replace('hier steht der individuelle Nachrichtentext.', 'User edited the message afterwards.');
    assert.equal(universalComposeStateFromBody(document(), edited, 'newMail').current, true, 'later user edits are not a broken signature');
});

test('header failure is retained conservatively and cannot fall through to a body write', async () => {
    const f = fixture({ headerWrite({ callback }) { callback(failed(5001)); return false; } });
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 0); assert.equal(universalComposeBlocked(f.item), true);
    assert.equal(universalComposeReport(f.item).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(universalComposeReport(f.item).phase, 'header');
});

test('changes to user content after claiming do not overwrite that content', async () => {
    const f = fixture({ headerWrite({ state }) { state.html = '<p>Newly typed text</p>'; } });
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 0); assert.equal(f.state.html, '<p>Newly typed text</p>');
});

test('context changes during the last asynchronous claim check cannot write an old item', async () => {
    let readCount = 0;
    const f = fixture({ sessionRead({ key, office }) {
        if (key === UNIVERSAL_COMPOSE_SESSION_KEY && ++readCount === 3) office.context.mailbox.item = {};
    } });
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 0);
});

test('an item or sender switch in the initial body callback cannot claim an old context', async () => {
    for (const change of [({ office }) => { office.context.mailbox.item = {}; }, ({ state }) => { state.sender = 'other@example.invalid'; }]) {
        const f = fixture({ bodyRead(args) { if (args.state.bodyReads === 1) change(args); } });
        assert.ok(['COMPOSE_ITEM_CHANGED', 'SENDER_MISMATCH'].includes((await errorOf(f.insert())).code));
        assert.equal(f.state.sessionWrites.length, 0); assert.equal(f.state.bodyWrites.length, 0);
    }
});

test('item changes during header callback or readback cannot write or finalize an old context', async () => {
    const header = fixture({ headerWrite({ office }) { office.context.mailbox.item = {}; } });
    assert.equal((await errorOf(header.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(header.state.bodyWrites.length, 0);
    const readback = fixture({ bodyRead({ state, office }) { if (state.bodyReads === 3) office.context.mailbox.item = {}; } });
    assert.equal((await errorOf(readback.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(readback.state.bodyWrites.length, 1);
    assert.equal(readback.state.sessionWrites.length, 1, 'no terminal session update');
});

test('an accepted terminal callback without confirmed session storage cannot acknowledge completion', async () => {
    const f = fixture({ sessionWrite({ value, callback }) { if (value.startsWith('applied:')) { callback(success()); return false; } } });
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1); assert.equal(universalComposeReport(f.item).state, 'uncertain');
});

test('a claim changed by the final caller guard cannot be overwritten, written through or acknowledged', async () => {
    let changed = false;
    const beforeBody = fixture({ guard({ state }) {
        if (!changed && state.bodyReads === 2 && state.bodyWrites.length === 0) {
            changed = true; state.sessions.set(UNIVERSAL_COMPOSE_SESSION_KEY, 'pending:other-runtime');
        }
    } });
    assert.equal((await errorOf(beforeBody.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(beforeBody.state.bodyWrites.length, 0);
    let terminalChanged = false;
    const beforeComplete = fixture({ guard({ state }) {
        if (!terminalChanged && state.bodyWrites.length === 1 && state.sessionWrites.length === 1 && state.bodyReads === 3) {
            terminalChanged = true; state.sessions.set(UNIVERSAL_COMPOSE_SESSION_KEY, 'pending:other-runtime');
        }
    } });
    assert.equal((await errorOf(beforeComplete.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(beforeComplete.state.sessionWrites.length, 1);
});

test('quote mutation after an accepted write remains unconfirmed even when the own signature is intact', async () => {
    const doc = document();
    const f = fixture({ state: { composeType: 'reply', html: '<html><body><p><br></p><div id="divRplyFwdMsg">Original quote</div></body></html>' },
        bodyWrite({ html, callback, state }) { state.html = '<html><body>' + html + '<div id="divRplyFwdMsg">Changed quote</div></body></html>'; callback(success()); } });
    assert.equal((await errorOf(f.insert(doc))).code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(f.state.bodyWrites.length, 1); assert.equal(universalComposeBlocked(f.item), true);
});

test('a caller cannot change captured source bytes while preparation is asynchronous', async () => {
    const doc = document(), original = doc.html;
    const f = fixture({ headerWrite() { doc.html = '<p>Changed source</p>'; doc.header = 'changed'; } });
    assert.equal((await f.insert(doc)).status, 'applied');
    assert.equal(f.state.bodyWrites[0].html, original);
});

test('post-write read failure retains its original code, step and pre-write length without retry', async () => {
    const f = fixture({ bodyRead({ state, callback }) {
        if (state.bodyReads === 3) { callback(failed('PRIVATE-HOST-CODE')); return false; }
    } });
    const originalLength = f.state.html.length;
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    const report = universalComposeReport(f.item);
    assert.equal(report.originalCode, 'U2E_CONTEXT_UNREADABLE');
    assert.equal(report.code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(report.phase, 'readback'); assert.equal(report.step, 'snapshot.body');
    assert.equal(report.bodyLengthOrigin, 'original'); assert.equal(report.bodyLength, originalLength);
    assert.equal(report.composeType, 'newMail');
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1);
    assert.doesNotMatch(JSON.stringify(report), /PRIVATE-HOST-CODE|private host detail/);
});

test('unrecognized reply boundary after write reports the actual read length and original boundary error', async () => {
    const f = fixture({ state: { composeType: 'reply', html: '<p><br></p><div id="divRplyFwdMsg">Quote</div>' },
        bodyWrite({ html, callback, state }) { state.html = html + '<p>Unknown quote boundary</p>'; callback(success()); } });
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    const report = universalComposeReport(f.item);
    assert.equal(report.originalCode, 'U2E_NEW_DRAFT_REQUIRED');
    assert.equal(report.step, 'snapshot.region'); assert.equal(report.composeType, 'reply');
    assert.equal(report.bodyLengthOrigin, 'actual'); assert.equal(report.bodyLength, f.state.html.length);
    assert.equal(f.state.bodyWrites.length, 1); assert.equal(universalComposeBlocked(f.item), true);
    assert.equal(report.fixture, undefined);
});

test('failed final session persistence is distinguished from material readback', async () => {
    const f = fixture({ sessionWrite({ value, callback }) {
        if (value.startsWith('applied:')) { callback(failed('PRIVATE-SESSION-CODE')); return false; }
    } });
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    const report = universalComposeReport(f.item);
    assert.equal(report.originalCode, 'U2E_INSERT_UNCERTAIN');
    assert.equal(report.step, 'session.finalize'); assert.equal(report.bodyLengthOrigin, 'actual');
    assert.equal(f.state.bodyWrites.length, 1); assert.equal(universalComposeBlocked(f.item), true);
});

test('an unconfirmed write times out once and a late callback never permits a second body write', async context => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    let late;
    const f = fixture({ bodyWrite({ callback }) { late = callback; } });
    const pending = errorOf(f.insert()); await flush(() => typeof late === 'function');
    context.mock.timers.tick(30001);
    assert.equal((await pending).code, 'U2E_INSERT_UNCERTAIN');
    late(success()); assert.equal(universalComposeBlocked(f.item), true);
    assert.equal((await errorOf(f.insert())).code, 'U2E_INSERT_UNCERTAIN');
    assert.equal(f.state.bodyWrites.length, 1);
});
