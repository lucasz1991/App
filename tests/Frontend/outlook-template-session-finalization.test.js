import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { prependTemplate, insertMobileCombinedTemplate, isTemplateInsertionBlocked, readTemplateState, TEMPLATE_INSERT_LIMITS } from '../../resources/js/outlook-addin/compose-template.js';
const signature = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const mobile = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-mobile-reference/mobile-reference-v1.json', import.meta.url), 'utf8'));
const success = value => ({ status: 'succeeded', value });
const failed = () => ({ status: 'failed', error: { code: '5001', message: 'Private host text' } });
const flush = async until => { for (let i = 0; i < 400 && !until(); i++) await Promise.resolve(); assert.equal(Boolean(until()), true); };

function host({ kind = 'native', profile = 'M', mode = 'hold', claim = '' } = {}) {
    const state = { body: '<p>Existing bytes</p>', session: claim, writes: [], bodyWrites: 0, reads: 0, finalSets: 0, releaseSet: null, releaseRead: null };
    const item = { getComposeTypeAsync: cb => cb(success({ composeType: 'newMail', coercionType: 'html' })),
        sessionData: {
            getAsync(_key, cb) {
                state.reads++;
                if (state.session === '1' && mode === 'hold') { state.releaseRead = value => cb(success(value)); return; }
                if (state.session === '1' && mode === 'read-failure') { cb(failed()); return; }
                if (state.session === '1' && mode === 'read-mismatch') { state.session = 'pending:foreign-after-final'; cb(success(state.session)); return; }
                cb(success(state.session));
            },
            setAsync(_key, value, cb) {
                state.writes.push(value);
                if (value !== '1') { state.session = value; cb(success()); return; }
                state.finalSets++;
                if (mode === 'hold' || mode === 'set-timeout') { state.releaseSet = () => { state.session = '1'; cb(success()); }; return; }
                if (mode === 'set-failure') { cb(failed()); return; }
                state.session = value; cb(success());
            },
        },
        body: { getTypeAsync: cb => cb(success('html')), getAsync: (_type, cb) => cb(success(state.body)),
            prependAsync(html, _options, cb) { state.bodyWrites++; state.body = html + state.body; if (mode === 'ownership-mismatch') state.session = 'pending:foreign-before-final'; cb(success()); },
            setSignatureAsync(html, _options, cb) { state.bodyWrites++; state.body = html + state.body; cb(success()); } } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        context: { platform: kind === 'mobile' ? 'iOS' : profile === 'B' ? 'PC' : 'OfficeOnline', mailbox: { item,
            diagnostics: { hostName: kind === 'mobile' ? 'OutlookIOS' : profile === 'B' ? 'Outlook' : 'OutlookWebApp', hostVersion: '5.2635.0' } } } };
    const html = kind === 'mobile' ? mobile.template.mobileComposeHtml : kind === 'v17' ? signature.source.combined : '<p>New template</p>';
    const invoke = () => kind === 'mobile' ? insertMobileCombinedTemplate(office, item, html, () => {}, { media: [] }) : prependTemplate(office, item, html);
    return { state, item, office, invoke };
}

for (const [kind, profile] of [['native', 'M'], ['v17', 'M'], ['v17', 'B'], ['mobile', 'M']]) test(kind + profile + ' stays pending until final callback AND readback, with exactly one body write', async () => {
    const h = host({ kind, profile }); let settled = false;
    const run = h.invoke().finally(() => { settled = true; });
    await flush(() => h.state.releaseSet);
    assert.equal(settled, false); assert.equal(h.state.bodyWrites, 1); assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
    h.state.releaseSet(); await flush(() => h.state.releaseRead);
    assert.equal(settled, false); assert.equal(isTemplateInsertionBlocked(h.item), true); assert.equal(h.state.finalSets, 1);
    h.state.releaseRead('1'); await run;
    assert.equal(settled, true); assert.equal(isTemplateInsertionBlocked(h.item), false); assert.equal(h.state.session, '1');
    assert.equal(h.state.bodyWrites, 1); assert.equal(h.state.writes.length, 2);
    await assert.rejects(h.invoke()); assert.equal(h.state.bodyWrites, 1);
});

for (const [mode, phase] of [['set-failure','session-final-write'], ['read-failure','session-final-readback'], ['read-mismatch','session-final-readback'], ['ownership-mismatch','session-final-ownership']]) test(mode + ' retains uncertainty and never repeats the applied body write', async () => {
    const h = host({ mode });
    await assert.rejects(h.invoke(), error => error.code === 'TEMPLATE_INSERT_UNCERTAIN' && error.phase === phase);
    assert.equal(h.state.bodyWrites, 1); assert.equal(isTemplateInsertionBlocked(h.item), true);
    if (mode === 'ownership-mismatch') { assert.equal(h.state.finalSets, 0); assert.equal(h.state.session, 'pending:foreign-before-final'); }
    else assert.equal(h.state.finalSets, 1);
    await assert.rejects(h.invoke(), { code: 'TEMPLATE_INSERT_UNCERTAIN' }); assert.equal(h.state.bodyWrites, 1);
});

test('missing final callback is bounded; late success cannot acknowledge or repeat a timed-out finalization', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = host({ mode: 'set-timeout' });
    const result = h.invoke().catch(error => error);
    await flush(() => h.state.releaseSet);
    t.mock.timers.tick(TEMPLATE_INSERT_LIMITS.readTimeoutMs);
    const error = await result;
    assert.equal(error.code, 'TEMPLATE_INSERT_UNCERTAIN'); assert.equal(error.phase, 'session-final-write'); assert.equal(error.reason, 'timeout');
    h.state.releaseSet(); await Promise.resolve(); await Promise.resolve();
    assert.equal(isTemplateInsertionBlocked(h.item), true); assert.equal(h.state.finalSets, 1);
    await assert.rejects(h.invoke()); assert.equal(h.state.bodyWrites, 1);
});

test('pre-existing pending claim is never finalized, cleared or retried even with a complete-looking body', async () => {
    const h = host({ claim: 'pending:old-runtime', mode: 'normal' });
    const before = h.state.body;
    await assert.rejects(h.invoke(), error => error.reason === 'pending');
    assert.deepEqual(h.state.writes, []); assert.equal(h.state.bodyWrites, 0); assert.equal(h.state.body, before); assert.equal(h.state.session, 'pending:old-runtime');
});

test('damaged V17 Source cannot reach the new completion path', async () => {
    const h = host({ kind: 'v17', mode: 'normal' });
    await assert.rejects(prependTemplate(h.office, h.item, signature.source.combined.replace('width="280"', 'width="281"')));
    assert.equal(h.state.bodyWrites, 0); assert.deepEqual(h.state.writes, []);
});

test('target change during the asynchronous owned-claim read prevents even the final metadata write', async () => {
    const h = host({ mode: 'normal' }); let allowed = true;
    h.item.sessionData.getAsync = (_key, cb) => {
        if (h.state.bodyWrites === 1) allowed = false;
        cb(success(h.state.session));
    };
    await assert.rejects(prependTemplate(h.office, h.item, '<p>New template</p>', async () => {
        if (!allowed) throw Object.assign(new Error('Private changed sender'), { code: 'MAILBOX_NOT_AUTHORIZED' });
    }), { code: 'TEMPLATE_INSERT_UNCERTAIN', phase: 'session-final-target' });
    assert.equal(h.state.bodyWrites, 1); assert.equal(h.state.finalSets, 0); assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
});
