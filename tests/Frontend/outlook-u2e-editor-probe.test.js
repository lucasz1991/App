import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';
import { parseManagedHtml, managedTemplateContract } from '../../resources/js/outlook-addin/managed-template.js';
import { U2E_PROBE_SUBJECT, U2E_PROBE_MAILBOX, U2E_PROBE_SESSION_KEY, U2E_PROBE_ID,
    U2E_PROBE_HTML_SHA256, U2E_PROBE_REFERENCE_SHA256, U2E_PROBE_MAX_UTF16,
    u2eProbeMethod, u2eProbeContextAvailable, u2eProbeDocument, inspectU2eProbe } from '../../resources/js/outlook-addin/u2e-editor-probe.js';

const fixed = u2eProbeDocument();
const binding = { schema: 1, mailboxAddress: U2E_PROBE_MAILBOX, senderAddress: U2E_PROBE_MAILBOX, allowedSenderAddresses: [U2E_PROBE_MAILBOX] };
const office = (platform = 'PC') => ({ context: { platform, mailbox: { userProfile: { emailAddress: U2E_PROBE_MAILBOX },
    diagnostics: { hostName: platform === 'iOS' ? 'OutlookIOS' : 'Outlook' }, item: { body: { prependAsync() {}, setSignatureAsync() {} } } } } });
const mutate = fn => { const { document } = parseHTML(fixed.html); fn(document); return document.toString(); };
const inspect = html => inspectU2eProbe(html);
const rejects = (html, stage = null) => {
    const result = inspect(html);
    assert.equal(result.confirmed, false); assert.equal(result.report.valid, false); assert.equal(result.root, null);
    if (stage) assert.equal(result.report.stage, stage);
    return result.report;
};

test('the fixture is a fixed, immutable byte-exact U2e Editor probe with a safe mobile budget', () => {
    assert.equal(U2E_PROBE_SUBJECT, '[TEST] RailTime – U2e Office.js Block A');
    assert.equal(U2E_PROBE_SESSION_KEY, 'railtime.u2e-probe.v1');
    assert.equal(createHash('sha256').update(fixed.html).digest('hex'), U2E_PROBE_HTML_SHA256);
    assert.equal(U2E_PROBE_HTML_SHA256, 'af69f5d880a0844fbc75ecbf5647469ad16e63426f5201712d3a3f1d2dc2594a');
    assert.equal(U2E_PROBE_REFERENCE_SHA256, 'c7efab2c64890dc57386448be880122e1e284c6c35a2392fcad5ed5094f94b6b');
    assert.equal(fixed.html.length, fixed.utf16Length); assert.equal(fixed.utf16Length, 13829);
    assert.ok(fixed.utf16Length <= U2E_PROBE_MAX_UTF16); assert.ok(30000 - fixed.utf16Length >= 3000);
    assert.equal(u2eProbeDocument('<script>arbitrary input</script>'), fixed);
    assert.ok(Object.isFrozen(fixed)); assert.ok(Object.isFrozen(fixed.images)); assert.ok(Object.isFrozen(fixed.images[0]));
    assert.doesNotMatch(fixed.html, /<head\b|<style\b|<meta\b|@media|table-layout|\bmso-|\[if\b|display\s*:\s*(?:none|inline-block)|dir=["']rtl/);
    assert.equal((fixed.html.match(/<img\b/g) || []).length, 13); assert.equal((fixed.html.match(/<a\b/g) || []).length, 4);
    assert.equal((fixed.html.match(new RegExp('id="' + U2E_PROBE_ID + '"', 'g')) || []).length, 2);
    assert.ok(fixed.images.every(value => /^https:\/\/app\.rail-time\.de\/m\/[0-9a-f]{10}\.png$/.test(value.src)));
    assert.equal(inspect(fixed.html).confirmed, true);
});

test('reference markup carries all fonts on spans, exact corner-cell line heights and image-only links', () => {
    const { document } = parseHTML(fixed.html);
    assert.equal(document.querySelector('.rt-shell').getAttribute('align'), 'center');
    assert.deepEqual([...document.querySelector('.rt-shell').children].filter(node => node.localName === 'tr').map(row => row.children.length), [1, 4, 1, 1]);
    const icons = [...document.querySelectorAll('a')];
    assert.deepEqual(icons.map(link => link.querySelector('img')?.getAttribute('alt')), ['Rail Time GmbH', 'E-Mail an die Disposition', 'WhatsApp', 'Telefon']);
    assert.ok(icons.every(link => link.textContent.trim() === ''));
    assert.equal(document.querySelectorAll('.rt-dispo-corner img').length, 4);
    for (const img of document.querySelectorAll('.rt-dispo-corner img')) {
        assert.match(img.parentElement.getAttribute('style'), /font-size:1px;line-height:8px/);
    }
    for (const span of document.querySelectorAll('.rt-contact, .rt-cta-dispo, .rt-cta-time, .rt-cta-number')) {
        assert.match(span.getAttribute('style'), /font-family:/); assert.match(span.getAttribute('style'), /font-size:/);
    }
});

for (const [platform, method] of [['PC', 'prependAsync'], ['OfficeOnline', 'prependAsync'], ['Mac', 'prependAsync'], ['iOS', 'setSignatureAsync'], ['Android', 'setSignatureAsync']]) {
    test('the IT-only context selects the actual supported probe method on ' + platform, () => {
        const context = office(platform);
        assert.equal(u2eProbeMethod(context), method); assert.equal(u2eProbeContextAvailable(context, binding), true);
        assert.equal(u2eProbeContextAvailable(context, binding, method === 'prependAsync' ? 'setSignatureAsync' : 'prependAsync'), false);
        delete context.context.mailbox.item.body[method]; assert.equal(u2eProbeContextAvailable(context, binding), false);
    });
}

test('mobile host-name fallback preserves the mobile-only setSignature method', () => {
    const context = office(''); context.context.mailbox.diagnostics.hostName = 'OutlookAndroid';
    assert.equal(u2eProbeMethod(context), 'setSignatureAsync');
});

for (const [name, change] of [
    ['unbound user', context => { context.context.mailbox.userProfile.emailAddress = 'other@example.invalid'; }],
    ['mailbox', (_context, values) => { values.mailboxAddress = 'other@example.invalid'; }],
    ['sender', (_context, values) => { values.senderAddress = 'other@example.invalid'; }],
    ['schema', (_context, values) => { values.schema = 2; }],
    ['allowed sender', (_context, values) => { values.allowedSenderAddresses = []; }],
]) test('context refuses ' + name + ' without interacting with Office', () => {
    const context = office(), values = structuredClone(binding); change(context, values);
    assert.equal(u2eProbeContextAvailable(context, values), false);
});

test('a serializer may retain the table marker or the first-cell marker independently', () => {
    assert.equal(inspect(mutate(document => document.querySelector('table.rt-shell').removeAttribute('id'))).confirmed, true);
    assert.equal(inspect(mutate(document => document.querySelector('td.rt-col-person').removeAttribute('id'))).confirmed, true);
    rejects(mutate(document => document.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'))), 'marker');
});

test('Word x_ IDs, MsoNormalTable classes, a tbody, pt dimensions and head-generated CSS are accepted', () => {
    let html = fixed.html.replace(/id="rt-sig-u2-/g, 'id="x_rt-sig-u2-')
        .replace(/<table /g, '<table class="MsoNormalTable" ').replace(/ class="MsoNormalTable" ([^>]*?) class="([^"]*)"/g, ' class="MsoNormalTable $2" $1')
        .replace(/\b(width|height):(\d+(?:\.\d+)?)px/g, (_match, name, amount) => name + ':' + (Number(amount) * .75) + 'pt');
    const { document } = parseHTML(html), shell = document.querySelector('table.rt-shell');
    const body = document.createElement('tbody');
    [...shell.childNodes].forEach(child => body.appendChild(child)); shell.appendChild(body);
    html = '<html><head><meta charset="utf-8"><style>p.MsoNormal{margin:0}</style></head><body>' + document.toString() + '</body></html>';
    const result = inspect(html); assert.equal(result.confirmed, true, JSON.stringify(result.report));
});

test('Rooster serialization can lose classes and styles while preserving actual structural attributes', () => {
    const html = mutate(document => document.querySelectorAll('*').forEach(node => { node.removeAttribute('style'); node.removeAttribute('class'); }));
    const result = inspect(html); assert.equal(result.confirmed, true, JSON.stringify(result.report));
});

for (const [name, change, stage] of [
    ['second shell', document => document.appendChild(document.querySelector('.rt-shell').cloneNode(true)), 'marker'],
    ['third marker', document => document.querySelector('.rt-col-direct').setAttribute('id', U2E_PROBE_ID), 'marker'],
    ['wrong cell marker', document => { document.querySelector('.rt-col-person').removeAttribute('id'); document.querySelector('.rt-col-direct').setAttribute('id', U2E_PROBE_ID); }, 'marker'],
    ['off-center shell', document => document.querySelector('.rt-shell').setAttribute('align', 'left'), 'shell'],
    ['shell width', document => document.querySelector('.rt-shell').setAttribute('width', '1039'), 'shell'],
    ['padding', document => document.querySelector('.rt-shell').setAttribute('cellpadding', '1'), 'shell'],
    ['missing nested table', document => document.querySelector('.rt-col-direct table').replaceWith(...document.querySelector('.rt-col-direct table').childNodes), 'rows'],
    ['extra table', document => document.querySelector('.rt-text').appendChild(document.createElement('table')), 'rows'],
    ['column reordering', document => { const row = document.querySelector('.rt-col-person').parentElement; row.insertBefore(row.children[1], row.children[0]); }, 'rows'],
    ['column width', document => document.querySelector('.rt-col-direct').setAttribute('width', '281'), 'columns'],
    ['rowspan', document => document.querySelector('.rt-col-dispo').setAttribute('rowspan', '1'), 'rows'],
    ['train colspan', document => document.querySelector('.rt-train-cell').setAttribute('colspan', '4'), 'rows'],
    ['dispatch table width', document => document.querySelector('.rt-dispo').setAttribute('width', '246'), 'columns'],
    ['body stylesheet', document => document.querySelector('.rt-text').appendChild(document.createElement('style')), 'material'],
    ['meta in shell', document => document.querySelector('.rt-text').appendChild(document.createElement('meta')), 'material'],
    ['event handler', document => document.querySelector('.rt-logo').setAttribute('onerror', 'arbitrary()'), 'material'],
    ['hidden dispatch', document => document.querySelector('.rt-dispo').setAttribute('hidden', ''), 'material'],
    ['display none', document => document.querySelector('.rt-col-person').setAttribute('style', 'width:260px;display:none'), 'material'],
    ['fixed layout', document => document.querySelector('.rt-dispo').setAttribute('style', 'width:247px;table-layout:fixed'), 'material'],
    ['missing image', document => document.querySelector('.rt-logo').remove(), 'images'],
    ['extra image', document => document.querySelector('.rt-text').appendChild(document.querySelector('.rt-logo').cloneNode()), 'images'],
    ['changed image source', document => document.querySelector('.rt-logo').setAttribute('src', 'https://other.example.invalid/logo.png'), 'images'],
    ['changed image dimension', document => document.querySelector('.rt-train').setAttribute('height', '82'), 'images'],
    ['wrong logo link', document => document.querySelector('.rt-logo-link').setAttribute('href', 'https://other.example.invalid'), 'links'],
    ['extra text link', document => { const link = document.createElement('a'); link.setAttribute('href', 'https://rail-time.de'); document.querySelector('.rt-text span').appendChild(link); }, 'links'],
    ['changed red bgcolor', document => document.querySelector('.rt-dispo-actions').setAttribute('bgcolor', '#e4002c'), 'colors'],
    ['changed gray bgcolor', document => document.querySelector('.rt-legal').setAttribute('bgcolor', '#e4e8ed'), 'colors'],
    ['changed legal text', document => { document.querySelector('.rt-legal span').textContent = 'Changed legal text'; }, 'text'],
    ['changed person', document => { document.querySelector('.rt-col-person p span').textContent = 'Different person'; }, 'text'],
]) test('readback refuses material mutation: ' + name, () => rejects(mutate(change), stage));

test('a second markerless shell is still refused and cannot hide behind marker loss', () => {
    rejects(mutate(document => { const copy = document.querySelector('.rt-shell').cloneNode(true); copy.querySelectorAll('[id]').forEach(node => node.removeAttribute('id')); copy.removeAttribute('id'); document.appendChild(copy); }), 'shell');
});

for (const attributes of ['hidden', 'style="display:none"', 'style="visibility:hidden"', 'style="opacity:0"', 'style="mso-hide:all"']) {
    test('an intact table inside an invisible ancestor is not confirmed: ' + attributes, () => {
        const report = rejects('<div ' + attributes + '>' + fixed.html + '</div>', 'material');
        assert.equal(report.reason, 'hidden-ancestor');
    });
}

test('an ordinary visible outer wrapper leaves the fixed probe confirmable', () => {
    assert.equal(inspect('<div style="display:block;opacity:1">' + fixed.html + '</div>').confirmed, true);
});

test('loading the diagnostic module does not parse its source until actually requested and caches the descriptor', async () => {
    const source = await readFile(new URL('../../resources/js/outlook-addin/u2e-editor-probe.js', import.meta.url), 'utf8');
    let calls = 0;
    const context = vm.createContext({ parseManagedHtml: html => { calls++; return parseManagedHtml(html); } });
    vm.runInContext(source.replace(/^import \{ parseManagedHtml \} from '\.\/managed-template\.js';\r?\n/m, '').replace(/^export /gm, '')
        + '\nglobalThis.fixedProbe = u2eProbeDocument; globalThis.inspectProbe = inspectU2eProbe;', context);
    assert.equal(calls, 0);
    const first = context.fixedProbe(); assert.equal(calls, 1);
    assert.equal(context.fixedProbe(), first); assert.equal(calls, 1);
    assert.equal(context.inspectProbe(first.html).confirmed, true); assert.equal(calls, 2);
});

test('reports expose finite evidence only and never source text, addresses, URLs, IDs or hashes', () => {
    const result = inspect(fixed.html), serialized = JSON.stringify(result.report);
    assert.doesNotMatch(serialized, /Lucas|Zacharias|rail-time|https|rt-sig|[0-9a-f]{24}/);
    assert.equal(result.report.imageCount, 13); assert.equal(result.report.markerCount, 2);
    assert.equal(result.root.node.start >= 0, true);
});

test('parse-only facade is bounded and does not turn a U2 diagnostic into a managed V17 contract', () => {
    assert.equal(parseManagedHtml(fixed.html).elements.filter(node => node.tag === 'img').length, 13);
    for (const value of [null, {}, '<div>', '<div id="x" id="y"></div>', 'x'.repeat(500001), '<div>' + '<span></span>'.repeat(4097) + '</div>']) {
        assert.throws(() => parseManagedHtml(value)); rejects(value, 'parse');
    }
    assert.throws(() => managedTemplateContract(fixed.html));
});
