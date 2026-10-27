import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate, replaceManagedTemplate, standaloneSignatureLayoutIsCurrent, TEMPLATE_INSERT_LIMITS } from '../../resources/js/outlook-addin/compose-template.js';
import { standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { wasSignatureWriteConfirmed } from '../../resources/js/outlook-addin/office-write.js';
import { nativeOfficeHtml, officeCarrier, standaloneHtml, STANDALONE_VERSION } from './outlook-standalone-fixture.js';

const ok = value => ({ status: 'succeeded', value });
const flush = async (until = () => false) => { for (let i = 0; i < 200 && !until(); i++) await Promise.resolve(); };

function fixture() {
    const before = '<p>User text &amp; formatting</p>';
    const after = '<p>Outside after</p><div id="divRplyFwdMsg"><blockquote>' + standaloneHtml() + '</blockquote></div>';
    const state = { signature: '', carrier: '', before, after, writes: [], prepends: [], sessions: new Map([['railtime.template.inserted.v1', 'template-untouched']]), fullWrites: 0 };
    const body = () => state.before + state.carrier + state.signature + state.after;
    const item = {
        getComposeTypeAsync(cb) { cb(ok({ composeType: 'newMail' })); },
        sessionData: { getAsync(key, cb) { cb(ok(state.sessions.get(key) || '')); }, setAsync(key, value, cb) { state.sessions.set(key, value); cb(ok()); } },
        body: {
            getTypeAsync(cb) { cb(ok('html')); }, getAsync(_type, cb) { cb(ok(body())); },
            setSignatureAsync(html, _options, cb) { state.writes.push(html); state.signature = nativeOfficeHtml(html); cb(ok()); },
            prependAsync(html, _options, cb) { state.prepends.push(html); state.carrier = officeCarrier(html) + state.carrier; cb(ok()); },
            setAsync() { state.fullWrites++; throw Error('Never rewrite the message body'); },
        },
    };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform: 'OfficeOnline', requirements: { isSetSupported() { return true; } }, mailbox: { item, diagnostics: { hostName: 'OutlookWebApp' } } } };
    return { state, item, office, body, run: (html = standaloneHtml(), options = {}, guard = () => {}) => insertStandaloneSignature(office, item, html, guard, options) };
}

test('native-only restores exact ID CSS after classes/STYLE disappear, preserving every outside byte', async () => {
    const h = fixture(); await h.run();
    assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1); assert.equal(h.state.fullWrites, 0);
    assert.ok(h.body().startsWith(h.state.before)); assert.ok(h.body().endsWith(h.state.after));
    assert.equal(h.state.sessions.get('railtime.template.inserted.v1'), 'template-untouched');
    assert.equal(wasSignatureWriteConfirmed(h.item), true);
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), STANDALONE_VERSION), true);
    assert.equal(isStandaloneSignatureInsertionBlocked(h.item), false);
});

test('external v4 carrier blocks migration before any write because received selector downgrades can affect every image', async () => {
    const h = fixture(); await h.run(standaloneHtml(STANDALONE_VERSION, false));
    const snapshot = h.body(); const sessions = new Map(h.state.sessions);
    assert.match(h.state.carrier, />tbody>tr>td>div>img/);
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), STANDALONE_VERSION), false);
    await assert.rejects(h.run(standaloneHtml('3123456789abcdef', true)), { code: 'SIGNATURE_LAYOUT_LEGACY_CARRIER' });
    assert.equal(h.body(), snapshot); assert.deepEqual(h.state.sessions, sessions);
    assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1); assert.equal(h.state.fullWrites, 0);
    assert.equal(isStandaloneSignatureInsertionBlocked(h.item), false);
});

test('a verified v4 carrier inside the native slot is removed by the v5 native replacement with fresh IDs', async () => {
    const h = fixture(); await h.run(standaloneHtml(STANDALONE_VERSION, false));
    const oldIds = h.state.writes[0].match(/id="rt-native-layout-[^"]+"/g);
    h.state.signature = h.state.signature.replace('<td>', '<td>' + h.state.carrier); h.state.carrier = '';
    const version = '3123456789abcdef'; await h.run(standaloneHtml(version, true));
    assert.notDeepEqual(h.state.writes.at(-1).match(/id="rt-native-layout-[^"]+"/g), oldIds);
    assert.equal((h.body().match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), version), true);
    assert.equal(h.state.fullWrites, 0);
});

test('same-version layout migration cannot return current using a different safe ID shape', async () => {
    const h = fixture(); await h.run(); await h.run(standaloneHtml(STANDALONE_VERSION, true, true));
    assert.equal(h.state.writes.length, 2); assert.equal(h.state.prepends.length, 2);
    assert.equal(standaloneSignatureContract(h.body()).layoutIds.logo.endsWith('-l'), true);
});

test('R63 owned native-only drafts migrate with fresh IDs without acknowledging their historical mixed core', async () => {
    const h = fixture();
    const frozen = readFileSync(new URL('../Fixtures/outlook/r63-native-mixed-carrier.html', import.meta.url), 'utf8').trimEnd();
    const contract = standaloneSignatureContract(frozen);
    const carrier = contract.elements.find(node => (node.attrs.id || '').endsWith('-carrier'));
    h.state.carrier = officeCarrier(frozen.slice(carrier.start, carrier.end));
    h.state.signature = nativeOfficeHtml(frozen.slice(carrier.end));
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), STANDALONE_VERSION), false);
    await h.run();
    assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1); assert.equal(h.state.fullWrites, 0);
    assert.equal(h.state.writes[0].includes('rt-native-layout-' + '63'.repeat(16)), false);
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), STANDALONE_VERSION), true);
    assert.equal(isStandaloneSignatureInsertionBlocked(h.item), false);
});

test('R64 same-shape historical core migrates with a fresh nonce instead of falsely reporting current', async () => {
    for (const removeOwnAlignment of [false, true]) {
        const h = fixture();
        let frozen = readFileSync(new URL('../Fixtures/outlook/r64-native-two-row-carrier.html', import.meta.url), 'utf8').trimEnd();
        let contract = standaloneSignatureContract(frozen);
        if (removeOwnAlignment) {
            const person = contract.elements.find(node => node.attrs.id === contract.layoutIds.person);
            const style = person.attrs.style.split(';').filter(value => !/^\s*text-align\s*:/i.test(value)).join(';');
            const opening = frozen.slice(person.start, person.openEnd).replace(/\sstyle="[^"]*"/, ' style="' + style + '"');
            frozen = frozen.slice(0, person.start) + opening + frozen.slice(person.openEnd);
            contract = standaloneSignatureContract(frozen);
        }
        const carrier = contract.elements.find(node => (node.attrs.id || '').endsWith('-carrier'));
        h.state.carrier = officeCarrier(frozen.slice(carrier.start, carrier.end));
        h.state.signature = nativeOfficeHtml(frozen.slice(carrier.end));
        assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), contract.version), false);
        await h.run(standaloneHtml(contract.version, true, true));
        assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1); assert.equal(h.state.fullWrites, 0);
        assert.equal(h.state.writes[0].includes('rt-native-layout-' + '64'.repeat(16)), false);
        assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), contract.version), true);
    }
});

test('v5 nine-ID native insertion proves inset/footer retention and never emits a descendant selector', async () => {
    const h = fixture(); await h.run();
    const version = '5123456789abcdef';
    await h.run(standaloneHtml(version, true, true));
    const contract = standaloneSignatureContract(h.body());
    assert.equal(Object.keys(contract.layoutIds).length, 11);
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), version), true);
    const payload = h.state.prepends.at(-1);
    const css = /<style\b[^>]*>([^]*?)<\/style>/.exec(payload)[1];
    for (const [, selector] of css.replace('@media(max-width:680px){', '').matchAll(/([^{}]+)\{[^{}]*\}/g)) {
        assert.match(selector, /^#rt-native-layout-[0-9a-f]{32}-[rpcthslifwj]$/);
    }
    assert.equal(h.state.fullWrites, 0);
    const snapshot = h.body(); await h.run(standaloneHtml(version, true, true));
    assert.equal(h.body(), snapshot); assert.equal(h.state.prepends.length, 2);
});

test('same version without any carrier is repaired; only a positively retained current version becomes a no-op', async () => {
    const h = fixture(); h.state.signature = nativeOfficeHtml(standaloneHtml());
    assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), STANDALONE_VERSION), false);
    await h.run(); const snapshot = h.body(); await h.run();
    assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1); assert.equal(h.body(), snapshot);
});

test('changed versions reuse exact prior IDs and carrier, without orphan accumulation', async () => {
    const h = fixture(); await h.run();
    const ids = h.state.writes[0].match(/id="rt-native-layout-[^"]+"/g);
    for (const version of ['1123456789abcdef', '2123456789abcdef']) {
        await h.run(standaloneHtml(version));
        assert.deepEqual(h.state.writes.at(-1).match(/id="rt-native-layout-[^"]+"/g), ids);
        assert.equal(standaloneSignatureLayoutIsCurrent(h.body(), h.body(), version), true);
    }
    assert.equal(h.state.writes.length, 3); assert.equal(h.state.prepends.length, 1);
    assert.equal((h.body().match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
});

test('a retained but damaged existing carrier blocks without another native write or prepend', async () => {
    const h = fixture(); await h.run(); h.state.carrier = h.state.carrier.replace('max-width:340px!important', 'max-width:341px!important');
    await assert.rejects(h.run(standaloneHtml('1123456789abcdef')), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED' });
    assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1);
});

test('SDK damage to a previous carrier after native refresh never causes a second carrier prepend', async () => {
    const h = fixture(); await h.run(); const native = h.item.body.setSignatureAsync;
    h.item.body.setSignatureAsync = (html, options, cb) => { h.state.carrier = h.state.carrier.replace(/(<style\b[^>]*>)[\s\S]*?(<\/style>)/, '$1 $2'); native(html, options, cb); };
    await assert.rejects(h.run(standaloneHtml('1123456789abcdef')), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED' });
    assert.equal(h.state.writes.length, 2); assert.equal(h.state.prepends.length, 1); assert.equal(h.state.fullWrites, 0);
    assert.equal((h.body().match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
});

test('a carrier entirely removed by native replacement may be installed once again without accumulation', async () => {
    const h = fixture(); await h.run(); const native = h.item.body.setSignatureAsync;
    h.item.body.setSignatureAsync = (html, options, cb) => { h.state.carrier = ''; native(html, options, cb); };
    await h.run(standaloneHtml('1123456789abcdef'));
    assert.equal(h.state.writes.length, 2); assert.equal(h.state.prepends.length, 2);
    assert.equal((h.body().match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
});

test('lost or duplicated IDs after native success stop before CSS and do not mark the signature applied', async () => {
    for (const corrupt of [value => value.replace(/ id="x_rt-native-layout-[^"]*-p"/, ''), value => value + '<blockquote>' + value + '</blockquote>']) {
        const h = fixture(); h.item.body.setSignatureAsync = (html, _options, cb) => { h.state.writes.push(html); h.state.signature = corrupt(nativeOfficeHtml(html)); cb(ok()); };
        await assert.rejects(h.run(), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.prepends.length, 0); assert.equal(wasSignatureWriteConfirmed(h.item), false);
        await assert.rejects(h.run(), { code: 'SIGNATURE_INSERT_UNCERTAIN' });
        assert.equal(h.state.fullWrites, 0);
    }
});

test('successful carrier callback with empty CSS remains uncertain without native or body retry', async () => {
    const h = fixture(); h.item.body.prependAsync = (html, _options, cb) => { h.state.prepends.push(html); h.state.carrier = officeCarrier(html.replace(/(<style\b[^>]*>)[\s\S]*?(<\/style>)/, '$1 $2')); cb(ok()); };
    await assert.rejects(h.run(), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED' });
    assert.equal(wasSignatureWriteConfirmed(h.item), false); assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1);
    await assert.rejects(prependTemplate(h.office, h.item, '<p>Other template</p>'), { code: 'SIGNATURE_INSERT_UNCERTAIN' });
    await assert.rejects(replaceManagedTemplate(h.office, h.item, '<p>Other template</p>'), { code: 'SIGNATURE_INSERT_UNCERTAIN' });
});

test('unknown native outcome and late success never authorize a carrier or a completed marker', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] }); const h = fixture(); let callback;
    h.item.body.setSignatureAsync = (html, _options, cb) => { h.state.writes.push(html); callback = cb; };
    const result = h.run().then(() => null, error => error); await flush(() => callback);
    t.mock.timers.tick(TEMPLATE_INSERT_LIMITS.writeTimeoutMs); assert.equal((await result).code, 'SIGNATURE_INSERT_UNCERTAIN');
    callback(ok()); await flush(); assert.equal(h.state.prepends.length, 0); assert.equal(wasSignatureWriteConfirmed(h.item), false);
    assert.equal(isStandaloneSignatureInsertionBlocked(h.item), true);
});

test('changed body, sender/item or foreign signature session stop before native mutation', async () => {
    for (const scenario of ['body', 'target', 'session']) {
        const h = fixture(); let changed = false;
        await assert.rejects(h.run(standaloneHtml(), { beforeInsert() { if (scenario === 'body') h.state.before += '<p>Edit</p>'; if (scenario === 'target') changed = true; if (scenario === 'session') h.state.sessions.set('railtime.signature.layout.v1', 'pending:foreign'); } }, () => { if (changed) throw Error('item changed'); }));
        assert.equal(h.state.writes.length, 0); assert.equal(h.state.prepends.length, 0); assert.equal(h.state.fullWrites, 0);
    }
});

test('standalone parser rejects combined ownership, quoted owner, wrong version and ambiguous metadata', () => {
    for (const value of ['<blockquote>' + standaloneHtml() + '</blockquote>', standaloneHtml() + standaloneHtml(), standaloneHtml().replace('rt-managed-signature-v1', 'rt-managed-signature-v2'), standaloneHtml() + '<span>RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT</span>']) {
        assert.throws(() => standaloneSignatureContract(value));
    }
});
