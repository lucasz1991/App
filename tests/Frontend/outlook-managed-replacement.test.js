import assert from 'node:assert/strict';
import test from 'node:test';
import { managedTemplateReplacement, inspectManagedTemplateContract } from '../../resources/js/outlook-addin/managed-template.js';
import { currentComposeBodyHtml, replaceManagedTemplate, isTemplateInsertionBlocked, TEMPLATE_INSERT_LIMITS } from '../../resources/js/outlook-addin/compose-template.js';

const MARKER = 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT';
const TYPED = '<p class="design-spacer">My &amp; <strong>formatted</strong> text<img src="cid:user-image" alt="A > B"></p>';
const OUTSIDE = '<p>Text before the managed document &amp; kept exactly.</p>';

function artifact({ content = '<p class="design-spacer"><br></p>', greeting = false, legacy = false, name = 'new' } = {}) {
    const editable = legacy ? content : `<div class="rt-compose-editable" data-rt-compose-editable="v1">${content}</div>`;
    return `<style data-rt-combined-compose-css="1">.scope-${name}{color:red}</style>`
        + '<div class="rt-combined-compose-document" data-rt-compose-document="combined-v1">'
        + `<table class="rt-combined-compose-frame"><tbody><tr><td><!--${MARKER}-->`
        + `<div class="rt-outlook-template rtt0123456789ab"><table><tbody><!-- RT_APPLICATION_CONTENT_START --><tr><td class="rt-native-template-pad">`
        + (greeting ? '<p class="design-salutation">Guten Tag,</p>' : '') + editable
        + (greeting ? '<p class="design-greeting">Mit freundlichen Grüßen,</p>' : '')
        + '</td></tr><!-- RT_APPLICATION_CONTENT_END --></tbody></table></div>'
        + `<div class="rt-outlook-signature rts0123456789"><!--RT-SIGNATURE-MANAGED-V1--><p>Signature ${name}</p><!--[if mso]><img src="cid:mso-${name}" width="220" height="28"><![endif]--></div>`
        + '</td></tr></tbody></table></div>';
}

function replace(body, next = artifact({ greeting: true }), composeType = 'newMail') {
    return managedTemplateReplacement(body, currentComposeBodyHtml(body, composeType), next);
}

test('replacement preserves typed inner HTML, every outside byte and quoted managed documents', () => {
    for (const composeType of ['newMail', 'reply', 'forward']) {
        const old = artifact({ content: TYPED, name: 'old' });
        const quote = `<div id="divRplyFwdMsg"><blockquote>${artifact({ name: 'quoted' })}<p>History &amp; quotes.</p></blockquote></div>`;
        const body = OUTSIDE + old + '<p>After the managed document</p>' + quote;
        const plan = replace(body, artifact({ greeting: true }), composeType);
        const oldPrefix = old.slice(0, old.indexOf('<div class="rt-combined-compose-document"'));
        const next = artifact({ greeting: true, content: TYPED }).replace(/^(<style[^]*?<\/style>)(<div[^>]*>)/, '$2$1');
        const expected = OUTSIDE + oldPrefix + next + '<p>After the managed document</p>' + quote;
        assert.equal(plan.html, expected, composeType);
        assert.equal(plan.legacy, false);
        assert.equal(plan.html.includes('Signature old'), false);
        assert.equal(plan.html.includes('Signature quoted'), true);
    }
});

test('same selection is a targeted refresh of one shell, never another prepended copy', () => {
    const old = artifact({ greeting: true, content: TYPED });
    const once = replace(old).html;
    const twice = replace(once).html;
    assert.equal((twice.match(/data-rt-compose-document="combined-v1"/g) || []).length, 1);
    assert.equal((twice.match(/My &amp;/g) || []).length, 1);
    assert.equal((twice.match(/Mit freundlichen/g) || []).length, 1);
    assert.equal(twice, once, 'the compiler-owned carrier sheet does not accumulate on refresh');
});

test('a recognized quote inside the editable region is preserved without becoming a second active document', () => {
    const quote = `<blockquote type="cite">${artifact({ name: 'quoted' })}</blockquote>`;
    const content = TYPED + quote;
    const once = replace(artifact({ content, name: 'old' })).html;
    assert.ok(once.includes(`<div class="rt-compose-editable" data-rt-compose-editable="v1">${content}</div>`));
    const twice = replace(once).html;
    assert.equal(twice, once);
    assert.ok(twice.includes(quote));
});

test('Office-prefixed classes and quoted attributes remain parseable without reserialization', () => {
    const old = artifact({ content: TYPED }).replace(/class="([^"]*)"/g, (_whole, names) => `class="${names.split(' ').map(name => `x_${name}`).join(' ')}"`);
    const result = replace(old).html;
    assert.match(result, /class="x_design-spacer">My &amp; <strong>formatted<\/strong>/);
    assert.match(result, /alt="A > B"/);
});

test('known legacy default preserves its complete spacer paragraph including user markup', () => {
    for (const content of ['<p class="design-spacer"><br></p>', TYPED]) {
        const result = replace(artifact({ legacy: true, content, name: 'old' }));
        assert.equal(result.legacy, true);
        assert.ok(result.html.includes(`<div class="rt-compose-editable" data-rt-compose-editable="v1">${content}</div>`));
    }
});

test('unknown, quoted, mixed, duplicate or damaged ownership fails closed', () => {
    const valid = artifact({ content: TYPED });
    const invalid = [
        valid + valid,
        valid.replace('data-rt-compose-document="combined-v1"', 'data-rt-compose-document="other"'),
        valid.replace('data-rt-compose-editable="v1"', 'data-rt-compose-editable="v2"'),
        valid.replace('class="rt-compose-editable"', 'class="unknown-editable"'),
        valid.replace('<p class="design-spacer">', '<div class="rt-compose-editable" data-rt-compose-editable="v1"></div><p class="design-spacer">'),
        valid.replace(`<!--${MARKER}-->`, '<!--UNKNOWN-TEMPLATE-->'),
        valid.replace(MARKER, `${MARKER}-UNKNOWN`),
        valid.replace('</tbody></table></div>', '</table></div>'),
        `<blockquote>${valid}</blockquote>`,
        `<!--RT-TEMPLATE-MANAGED-V1-->${valid}`,
        artifact({ legacy: true, greeting: true, content: TYPED }),
        artifact({ legacy: true }).replace('RT_APPLICATION_CONTENT_END', 'UNKNOWN_END'),
        artifact({ legacy: true }).replace('class="rt-native-template-pad"', 'class="foreign-pad"'),
        artifact({ legacy: true }).replace('<!-- RT_APPLICATION_CONTENT_END -->', '<tr><td>Another edited note</td></tr><!-- RT_APPLICATION_CONTENT_END -->'),
    ];
    for (const body of invalid) assert.throws(() => replace(body), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    assert.throws(() => replace(valid, artifact(), 'reply'), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' }, 'unknown reply boundary');
    assert.throws(() => replace(valid, artifact({ legacy: true })), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' }, 'new artifacts need the explicit editable wrapper');
});

const succeeded = value => ({ status: 'succeeded', value });
const flush = async (ready = () => false) => {
    for (let index = 0; index < 100 && !ready(); index++) await Promise.resolve();
};

function host({ composeType = 'newMail', body = OUTSIDE + artifact({ content: TYPED, name: 'old' }), session = '1' } = {}) {
    const state = { body, session, writes: [], prepends: [], native: [], claims: [], prepared: 0 };
    const item = {
        getComposeTypeAsync(callback) { callback(succeeded({ composeType })); },
        sessionData: {
            getAsync(_key, callback) { callback(succeeded(state.session)); },
            setAsync(_key, value, callback) { state.session = value; state.claims.push(value); callback(succeeded()); },
        },
        body: {
            getTypeAsync(callback) { callback(succeeded('html')); },
            getAsync(_type, callback) { callback(succeeded(state.body)); },
            setAsync(html, _options, callback) { state.writes.push(html); state.body = html; callback(succeeded()); },
            prependAsync() { throw new Error('Replacement must never prepend'); },
            setSignatureAsync() { throw new Error('Replacement must never clear a native slot'); },
        },
    };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        context: { platform: 'PC', requirements: { isSetSupported() { return true; } }, mailbox: { item } } };
    const prepare = async () => { state.prepared++; };
    return { office, item, state, run: (options = {}, check = () => {}) => replaceManagedTemplate(office, item, artifact({ greeting: true }), check, { beforeReplace: prepare, ...options }) };
}

test('explicit replacement uses one confirmed SDK body write, preserves content and finishes the existing session', async () => {
    const h = host();
    const expected = replace(h.state.body).html;
    await h.run();
    assert.deepEqual(h.state.writes, [expected]);
    assert.equal(h.state.session, '1');
    assert.equal(h.state.prepared, 1);
    assert.equal(isTemplateInsertionBlocked(h.item), false);
});

test('ambiguous legacy ownership is rejected before media preparation or session mutations', async () => {
    const h = host({ body: artifact({ legacy: true, greeting: true }) });
    await assert.rejects(h.run(), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    assert.equal(h.state.prepared, 0);
    assert.deepEqual(h.state.writes, []);
    assert.deepEqual(h.state.claims, []);
});

test('concurrent edits during preparation stop the write and restore the completed session', async () => {
    const h = host();
    const original = h.state.body;
    await assert.rejects(h.run({ beforeReplace: () => { h.state.body += '<p>New edit</p>'; } }), { code: 'COMPOSE_BODY_CHANGED' });
    assert.equal(h.state.body, original + '<p>New edit</p>');
    assert.deepEqual(h.state.writes, []);
    assert.equal(h.state.session, '1');
    assert.equal(isTemplateInsertionBlocked(h.item), false);
});

test('a final synchronous compose-target guard runs after the last read and before the only body write', async () => {
    const h = host();
    await assert.rejects(h.run({ beforeCommit: () => { throw Object.assign(new Error('ITEM_CHANGED'), { code: 'ITEM_CHANGED' }); } }), { code: 'ITEM_CHANGED' });
    assert.deepEqual(h.state.writes, []);
    assert.equal(h.state.session, '1');
});

test('a foreign session claim during media preparation blocks the write without clearing its ownership', async () => {
    const h = host();
    const original = h.state.body;
    await assert.rejects(h.run({ beforeReplace: () => { h.state.session = 'pending:another-runtime'; } }), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    assert.equal(h.state.body, original);
    assert.deepEqual(h.state.writes, []);
    assert.equal(h.state.session, 'pending:another-runtime');
    assert.equal(isTemplateInsertionBlocked(h.item), true);
});

test('unknown incoming outside markup or an additional old spacer is never silently discarded', () => {
    assert.throws(() => replace(artifact(), '<p>Unknown outside content</p>' + artifact()), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    const legacy = artifact({ legacy: true }).replace('<!-- RT_APPLICATION_CONTENT_END -->', '<tr><td><p class="design-spacer">Foreign note</p></td></tr><!-- RT_APPLICATION_CONTENT_END -->');
    assert.throws(() => replace(legacy), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
});

test('definite SDK failure keeps the original document and permits a fresh explicitly requested attempt', async () => {
    const h = host();
    const original = h.state.body;
    const write = h.item.body.setAsync;
    h.item.body.setAsync = (_html, _options, callback) => callback({ status: 'failed', error: { code: 'WRITE_FAILED' } });
    await assert.rejects(h.run(), { code: 'WRITE_FAILED' });
    assert.equal(h.state.body, original);
    assert.equal(h.state.session, '1');
    h.item.body.setAsync = write;
    await h.run();
    assert.equal(h.state.writes.length, 1);
});

test('timeout blocks repeats and a late success establishes completion without a second body mutation', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = host();
    let callback;
    h.item.body.setAsync = (html, _options, complete) => { h.state.writes.push(html); callback = complete; };
    const result = h.run().then(() => null, error => error);
    await flush(() => callback);
    assert.ok(callback);
    t.mock.timers.tick(TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    assert.equal((await result).code, 'TEMPLATE_INSERT_UNCERTAIN');
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    assert.equal(h.state.writes.length, 1);
    h.state.body = h.state.writes[0];
    callback(succeeded());
    await flush();
    assert.equal(h.state.session, '1');
    assert.equal(isTemplateInsertionBlocked(h.item), false);
    assert.equal(h.state.writes.length, 1);
});

test('a synchronous SDK exception keeps replacement uncertain with no duplicate fallback', async () => {
    const h = host();
    h.item.body.setAsync = () => { throw new Error('Bridge may have received the write'); };
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    assert.equal(isTemplateInsertionBlocked(h.item), true);
});

test('contract diagnostics return bounded known structure without HTML, text, URLs or arbitrary attribute values', () => {
    const html = artifact({ content: '<p class="design-spacer secret-class-person">Private message secret@example.test <img src="https://private.example/token-secret"></p>' })
        .replace('data-rt-compose-document="combined-v1"', 'data-rt-compose-document="private-credential"');
    const report = inspectManagedTemplateContract(html, html);
    assert.equal(report.counts['rt-combined-compose-document'], 1);
    assert.equal(report.counts['rt-compose-editable'], 1);
    assert.equal(report.editableV1, 1);
    assert.equal(report.roots[0].mode, 'other');
    assert.equal(report.appStart, 1);
    assert.equal(report.markers.signature, true);
    assert.ok(JSON.stringify(report).length < 2000);
    assert.doesNotMatch(JSON.stringify(report), /Private message|secret@example|private-credential|secret-class|private\.example|token-secret|<p|<img/);
});

test('a blocked replacement logs only safe SDK structure and preserves its original failure', async t => {
    const diagnosticModule = await import('../../resources/js/outlook-addin/managed-template.js?diagnostic-proof');
    const logs = [];
    t.mock.method(console, 'warn', (...args) => { logs.push(args); });
    const body = artifact({ content: TYPED }).replace('data-rt-compose-document="combined-v1"', '');
    for (let attempt = 0; attempt < 5; attempt++) {
        assert.throws(() => diagnosticModule.managedTemplateReplacement(body, body, artifact()), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    }
    assert.equal(logs.length, 3, 'at most three structural reports per pane runtime');
    assert.equal(logs[0][0], '[RailTime] template-replace-contract');
    const report = JSON.parse(logs[0][1]);
    assert.equal(report.sdkBody.roots[0].mode, 'missing');
    assert.equal(report.incoming.roots[0].mode, 'combined-v1');
    assert.doesNotMatch(logs[0][1], /My &amp;|formatted|user-image|A > B|<div|<p/);
});
