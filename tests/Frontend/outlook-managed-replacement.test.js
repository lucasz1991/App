import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { managedTemplateContract, managedTemplateReplacement, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { standaloneHtml, nativeOfficeHtml, officeCarrier } from './outlook-standalone-fixture.js';
import { currentComposeBodyHtml, prependTemplate, replaceManagedTemplate, isTemplateInsertionBlocked, TEMPLATE_INSERT_LIMITS } from '../../resources/js/outlook-addin/compose-template.js';

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

const metadata = value => `<span class="rt-office-metadata" aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;">${value}</span>`;
const START = 'RT-COMPOSE-EDITABLE-START-V1';
const END = 'RT-COMPOSE-EDITABLE-END-V1';
const nativeGroups = '<table class="rt-personal-columns" data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v4"><tbody><tr><td>'
    + '<table class="rt-personal-left"><tbody><tr><td><p>Personal name</p></td></tr></tbody></table>'
    + '<table class="rt-personal-right"><tbody><tr><td><img src="cid:logo" width="180" height="31"></td></tr></tbody></table>'
    + '<table class="rt-df-train"><tbody><tr><td><div class="rt-train-window"><img class="rt-delivery-train" src="cid:train" width="300" height="38"></div><!--[if mso]><img src="cid:still" width="220" height="28"><![endif]--></td></tr></tbody></table>'
    + '</td></tr></tbody></table>';

function stableArtifact({ content = '<p class="design-spacer"><br></p>', greeting = false, sentinels = false, native = false, footer = false, responsive = false, wrappers = false, templateGutter = false } = {}) {
    const groups = responsive ? nativeGroups.replace('outlook-desktop-flow-v4', 'outlook-desktop-flow-v7')
        .replace('<table class="rt-personal-left">', '<table class="rt-personal-logo-column"><tbody><tr><td><table class="rt-personal-logo-frame"><tbody><tr><td><img src="cid:separate-logo" width="200" height="34"></td></tr></tbody></table></td></tr></tbody></table><table class="rt-personal-left" align="left" height="50">')
        .replace('<table class="rt-df-train">', '</td></tr><tr><td><table class="rt-df-train">')
        .replace('class="rt-delivery-train" src="cid:train" width="300" height="38"', 'class="rt-delivery-train" src="cid:train" width="300" height="65"') : nativeGroups;
    return '<div class="rt-combined-compose-document rtc0123456789ab" data-rt-compose-document="combined-v1" style="display:block;width:100%;">'
        + '<table class="rt-combined-compose-frame rt-managed-combined-v1 rtc0123456789ab"><tbody><tr><td>'
        + '<style data-rt-combined-compose-css="1">table.rtc0123456789ab{width:100%}</style>'
        + '<div class="rt-outlook-template rtt0123456789ab"><table class="rt-native-compose-frame rt-managed-template-v1 rtt0123456789ab"><tbody><tr><td>'
        + (templateGutter ? '<table class="rt-shell design-v27"><tbody><tr><td>' : '')
        + metadata(MARKER) + (greeting ? '<p>Guten Tag,</p>' : '')
        + '<table class="rt-compose-editable rt-compose-editable-v1" data-rt-compose-editable="v1"><tbody><tr><td style="padding:0;">' + (sentinels ? metadata(START) : '') + content + (sentinels ? metadata(END) : '') + '</td></tr></tbody></table>'
        + (greeting ? '<p>Mit freundlichen Grüßen,</p>' : '') + (templateGutter ? '</td></tr></tbody></table>' : '') + '</td></tr></tbody></table></div>'
        + '<div class="rt-outlook-signature rts0123456789' + (responsive ? ' rt-dflow-host-v4 rt-df70123456789' : '') + '"><table class="rt-combined-signature-frame rt-managed-signature-v1 rts0123456789"><tbody><tr><td>'
        + (footer ? '' : metadata('RT-SIGNATURE-MANAGED-V1') + metadata('RT-SIGNATURE-VERSION:0123456789abcdef'))
        + (native ? '<table><tbody><tr><td class="design-hotline-row">' + (wrappers ? '<table class="rt-brand-hotline-gutter"><tbody><tr><td><img src="cid:fill" width="100%" height="34"></td></tr></tbody></table>' : '') + '<strong>24/7</strong> erreichbar</td></tr></tbody></table>' + groups
            : '<p>Personal signature</p><img class="rt-delivery-train" src="cid:train" width="520" height="65">')
        + (footer ? '</td></tr><tr><td style="color:#718096;">' + (wrappers ? '<table class="rt-brand-legal-table"><tbody><tr><td>' : '') + '<p><font color="#718096">Legal footer</font></p>' + (wrappers ? '</td></tr></tbody></table>' : '') + metadata('RT-SIGNATURE-MANAGED-V1') + metadata('RT-SIGNATURE-VERSION:0123456789abcdef') : '')
        + '</td></tr></tbody></table></div>'
        + '</td></tr></tbody></table></div>';
}

function afterOfficeSet(html) {
    return html.replace(/<!--[\s\S]*?-->/g, '').replace(/\sdata-rt-[a-z-]+="[^"]*"/gi, '')
        .replace(/\sclass="[^"]*"/gi, '')
        .replace(/(<span\b[^>]*>)RT-SIGNATURE-MANAGED-V1<\/span><span\b[^>]*>RT-SIGNATURE-VERSION:([0-9a-f]{16})<\/span>/g,
            '$1RT-SIGNATURE-MANAGED-V1RT-SIGNATURE-VERSION:$2</span>')
        .replace(/\bid="(rt-native-layout-[^"]*)"/g, 'id="x_$1"');
}

function officeBoundaryDivs(html) {
    return html.replace(/<span\b[^>]*>RT-COMPOSE-EDITABLE-(START|END)-V1<\/span>/g, (_whole, side) => side === 'START'
        ? `<div style="line-height:26px;font-size:11pt;"><span style="font-size:0px;line-height:0;">${START}</span><span style="font-size:16px;">\n</span></div>`
        : `<div style="line-height:26px;font-size:0px;"><span style="line-height:0;">${END}</span></div>`);
}

function officeRetainedCarrier(html) {
    return html.replace(/\bid="(rt-native-layout-[^"]*)"/g, 'id="x_$1"').replace(/#rt-native-layout-/g, '#x_rt-native-layout-');
}

function afterOfficeTyping(html) {
    return html.replace(/<!--[\s\S]*?-->/g, '').replace(/\sdata-rt-[a-z-]+="[^"]*"/gi, '')
        .replace(/<[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi, tag => /^<table\b/i.test(tag) ? tag : tag.replace(/\sclass="[^"]*"/i, ' class="elementToProof"'))
        .replace(/(<table\b[^>]*\bclass=")([^"]*)"/gi, (_all, prefix, classes) => prefix + classes.split(/\s+/).map(name => `x_${name}`).join(' ') + '"');
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

test('versioned TABLE ownership preserves typed content after Office removes every DIV/P/SPAN/data marker', () => {
    const body = afterOfficeTyping(stableArtifact({ content: TYPED }));
    const typed = afterOfficeTyping(TYPED);
    const plan = replace(OUTSIDE + body + '<p>Outside after</p>', stableArtifact({ greeting: true }));
    assert.ok(plan.html.includes('<td style="padding:0;">' + typed + '</td>'));
    assert.ok(plan.html.startsWith(OUTSIDE + '<div class="elementToProof" style="display:block;width:100%;">'));
    assert.ok(plan.html.endsWith('</div><p>Outside after</p>'));
    assert.equal((plan.html.match(/rt-managed-combined-v1/g) || []).length, 1);
    assert.ok(plan.html.includes('Guten Tag,'));
    assert.equal(replace(plan.html, stableArtifact({ greeting: true })).html, plan.html, 'repeated refresh does not add generic DIVs or carrier sheets');
});

test('versioned TABLE replacement keeps outside and quoted edits byte-exact in replies and forwards', () => {
    const quote = '<div id="divRplyFwdMsg"><blockquote>' + afterOfficeTyping(stableArtifact({ greeting: true })) + '<p>Quoted custom text</p></blockquote></div>';
    const content = TYPED;
    const old = afterOfficeTyping(stableArtifact({ content }));
    for (const type of ['reply', 'forward']) {
        const plan = replace(OUTSIDE + old + '<p>Outside after</p>' + quote, stableArtifact({ greeting: true }), type);
        assert.ok(plan.html.startsWith(OUTSIDE));
        assert.ok(plan.html.endsWith('<p>Outside after</p>' + quote));
        assert.ok(plan.html.includes(afterOfficeTyping(content)));
    }
});

test('the real coordinator replaces a typed table-owned SDK body once and keeps outside/quoted bytes', async () => {
    const quote = '<div id="divRplyFwdMsg"><blockquote>' + afterOfficeTyping(stableArtifact()) + '</blockquote></div>';
    const h = host({ composeType: 'reply', body: OUTSIDE + afterOfficeTyping(stableArtifact({ content: TYPED })) + quote });
    const next = stableArtifact({ greeting: true });
    const expected = replace(h.state.body, next, 'reply').html;
    await replaceManagedTemplate(h.office, h.item, next);
    assert.deepEqual(h.state.writes, [expected]);
    assert.ok(h.state.body.startsWith(OUTSIDE));
    assert.ok(h.state.body.endsWith(quote));
    assert.ok(h.state.body.includes(afterOfficeTyping(TYPED)));
    assert.equal(h.state.session, '1');
    assert.equal(isTemplateInsertionBlocked(h.item), false);
});

test('table-owned inline quotes survive in new messages while an ambiguous reply boundary stays fail-closed', () => {
    const content = TYPED + '<blockquote type="cite">' + afterOfficeTyping(stableArtifact()) + '</blockquote>';
    const body = afterOfficeTyping(stableArtifact({ content }));
    assert.ok(replace(body, stableArtifact()).html.includes(afterOfficeTyping(content)));
    assert.throws(() => replace(body, stableArtifact(), 'reply'), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
});

test('table guards reject missing scopes, unknown versions, contradictory attributes and broken ownership', () => {
    const body = afterOfficeTyping(stableArtifact({ content: TYPED }));
    const invalid = [
        body.replace('x_rt-managed-combined-v1', 'x_rt-managed-combined-v2'),
        body.replace('x_rtc0123456789ab', 'x_unknownscope'),
        body.replace('x_rtt0123456789ab', 'x_unknownscope'),
        body.replace('x_rt-managed-signature-v1', 'x_unknown-owner'),
        body.replace('x_rt-compose-editable-v1', 'x_rt-compose-editable-v2'),
        body.replace('class="elementToProof" style=', 'class="elementToProof" data-rt-compose-document="other" style='),
        body.replace('class="x_rt-native-compose-frame ', 'data-rt-compose-document="other" class="x_rt-native-compose-frame '),
        body.replace('class="x_rt-compose-editable ', 'data-rt-compose-editable="v2" class="x_rt-compose-editable '),
        body.replace('RT-SIGNATURE-MANAGED-V1', 'UNKNOWN-SIGNATURE'),
        body.replace('RT-SIGNATURE-VERSION:0123456789abcdef', 'RT-SIGNATURE-VERSION:unknown'),
        body.replace('<td style="padding:0;">', '<td style="padding:0;"><table class="x_rt-compose-editable x_rt-compose-editable-v1"><tbody><tr><td>Foreign owner</td></tr></tbody></table>'),
        body.replace('<td style="padding:0;">', '<td>Extra cell</td><td style="padding:0;">'),
    ];
    for (const value of invalid) assert.throws(() => replace(value, stableArtifact()), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    assert.throws(() => replace(body, artifact()), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' }, 'a stable table cannot be downgraded to a disappearing DIV contract');
});

test('stripped older drafts without deliberate versioned table ownership stay unchanged and produce no debug text', t => {
    const logs = [];
    t.mock.method(console, 'warn', (...args) => { logs.push(args); });
    const old = afterOfficeTyping(artifact({ content: TYPED }));
    assert.throws(() => replace(old, stableArtifact()), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    assert.deepEqual(logs, [], 'temporary structural warning has been removed');
});

test('an untouched, positively bounded R46 legacy default can migrate its paragraph into the stable editable TD', () => {
    const old = artifact({ legacy: true, content: TYPED });
    const plan = replace(old, stableArtifact());
    assert.equal(plan.legacy, true);
    assert.ok(plan.html.includes('<td style="padding:0;">' + TYPED + '</td>'));
    assert.ok(plan.html.includes('rt-managed-combined-v1'));
});

test('text ownership survives three classless exchanges, merged signature metadata and inline quotes', () => {
    const content = TYPED + '<blockquote><p>My own quoted text</p></blockquote>';
    const quote = '<blockquote>' + afterOfficeSet(stableArtifact({ sentinels: true })) + '</blockquote>';
    let body = OUTSIDE + afterOfficeSet(stableArtifact({ content, sentinels: true })) + '<p>Outside after</p>' + quote;
    for (const greeting of [true, false, true]) {
        const result = replace(body, stableArtifact({ sentinels: true, greeting }));
        body = afterOfficeSet(result.html);
        const contract = managedTemplateContract(body);
        assert.equal(contract.kind, 'text-v1');
        assert.equal(body.slice(contract.region.start, contract.region.end), afterOfficeSet(content));
        assert.ok(body.startsWith(OUTSIDE));
        assert.ok(body.endsWith('<p>Outside after</p>' + quote));
        assert.equal((body.slice(contract.root.start, contract.root.end).match(new RegExp(START, 'g')) || []).length, 1);
        assert.equal((body.slice(contract.root.start, contract.root.end).match(new RegExp(END, 'g')) || []).length, 1);
        assert.equal(body.includes('Guten Tag,'), greeting);
    }
});

test('R51 table and proven R46 default migrate once while fresh sentinel boundaries remain intact', () => {
    for (const old of [afterOfficeTyping(stableArtifact({ content: TYPED })), artifact({ legacy: true, content: TYPED })]) {
        const result = replace(old, stableArtifact({ sentinels: true }));
        const contract = managedTemplateContract(result.html);
        assert.equal(result.html.slice(contract.region.start, contract.region.end), old.startsWith('<div') ? afterOfficeTyping(TYPED) : TYPED);
        assert.equal((result.html.match(new RegExp(START, 'g')) || []).length, 1);
        assert.equal((result.html.match(new RegExp(END, 'g')) || []).length, 1);
    }
});

test('an exactly coalesced empty hidden boundary span migrates while text or other content makes it ambiguous', () => {
    const blank = afterOfficeSet(stableArtifact({ sentinels: true, content: '' }));
    const coalesced = blank.replace(`>${START}</span><span aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;">${END}`, `>${START}${END}`);
    const result = replace(coalesced, stableArtifact({ sentinels: true, greeting: true }));
    const contract = managedTemplateContract(result.html);
    assert.equal(result.html.slice(contract.region.start, contract.region.end), '');
    assert.throws(() => replace(coalesced.replace(START + END, START + 'own text' + END), stableArtifact({ sentinels: true })), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    assert.throws(() => replace(coalesced.replace(START + END, START + END + '</span><p>Foreign note</p><span>'), stableArtifact({ sentinels: true })), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
});

test('observed Office hidden metadata profiles survive initial rendering and setAsync font inheritance', () => {
    const source = stableArtifact({ sentinels: true, content: TYPED });
    for (const style of ['font-size:0; line-height:0; max-height:0; overflow:hidden', 'font-size: 0px; line-height: 0;']) {
        const body = afterOfficeSet(source).replace(/ aria-hidden="true"/g, '').replace(/style="display:none!important;mso-hide:all;font-size:0;line-height:0;"/g, `style="${style}"`);
        assert.equal(managedTemplateContract(body).kind, 'text-v1');
        assert.ok(replace(body, source).html.includes(afterOfficeSet(TYPED)));
        for (const visible of ['font-size:1px;line-height:0;', 'font-size:0;line-height:normal;', 'font-size:0;', 'line-height:0;']) {
            assert.throws(() => managedTemplateContract(body.replace(`style="${style}">${START}`, `style="${visible}">${START}`)), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
        }
        const inherited = body.replace(`<span style="${style}">${MARKER}</span>`, `<div style="font-size:0px;"><span style="line-height:0;">${MARKER}</span></div>`);
        assert.equal(managedTemplateContract(inherited).kind, 'text-v1');
        assert.throws(() => managedTemplateContract(inherited.replace('style="font-size:0px;"', 'style="font-size:12px;"')), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
        assert.throws(() => managedTemplateContract(inherited.replace('style="font-size:0px;"', 'style="font-size:0px;font-size:12px;"')), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
        assert.throws(() => managedTemplateContract(inherited.replace('style="font-size:0px;"', 'style="font-size:12px!important;font-size:0px;"')), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
        assert.throws(() => managedTemplateContract(inherited.replace(`style="line-height:0;">${MARKER}`, `style="font:12px Arial;line-height:0;">${MARKER}`)), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
        assert.throws(() => managedTemplateContract(inherited.replace(`<span style="line-height:0;">${MARKER}</span>`, `<div style="font:12px Arial;"><span style="line-height:0;">${MARKER}</span></div>`)), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    }
});

test('separate observed metadata-only Office DIVs become balanced boundaries without accumulating empty shells', () => {
    for (const content of ['', '<p><br></p>', TYPED + '<blockquote><p>My quoted text</p></blockquote>']) {
        let body = officeBoundaryDivs(afterOfficeSet(stableArtifact({ sentinels: true, content })));
        for (let attempt = 0; attempt < 3; attempt++) {
            const old = managedTemplateContract(body);
            assert.equal(body.slice(old.region.start, old.region.end), afterOfficeSet(content));
            const plan = replace(body, stableArtifact({ sentinels: true, greeting: true }));
            const next = managedTemplateContract(plan.html);
            assert.equal(plan.html.slice(next.region.start, next.region.end), afterOfficeSet(content));
            body = officeBoundaryDivs(afterOfficeSet(plan.html));
        }
        assert.equal((body.match(/font-size:16px/g) || []).length, 1, 'Office-only empty formatting does not accumulate in user content');
    }
});

test('nonempty, malformed, cross-cell or unknown Office metadata wrappers fail without dropping any content', () => {
    const body = officeBoundaryDivs(afterOfficeSet(stableArtifact({ sentinels: true, content: TYPED })));
    const invalid = [
        body.replace('font-size:16px;">\n</span>', 'font-size:16px;">User text</span>'),
        body.replace('font-size:16px;">\n</span>', 'font-size:16px;"><img src="cid:own-photo"></span>'),
        body.replace('font-size:16px;">\n</span>', 'font-size:16px;"><!--own note--></span>'),
        body.replace(`>${START}</span>`, `>${START}</span><div></div>`),
        body.replace('line-height:26px;font-size:11pt;">', 'line-height:26px;font-size:11pt;" id="own-wrapper">'),
        body.replace(`<div style="line-height:26px;font-size:0px;">`, '</td><td><div style="line-height:26px;font-size:0px;">'),
    ];
    for (const value of invalid) assert.throws(() => replace(value, stableArtifact({ sentinels: true })), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
});

test('text ownership rejects damaged, visible, contradictory or ambiguous boundaries and metadata', () => {
    const source = stableArtifact({ sentinels: true, content: TYPED });
    const body = afterOfficeSet(source);
    const invalid = [
        body + body,
        body.replace(START, 'RT-COMPOSE-EDITABLE-START-V2'),
        body.replace(START, END),
        body.replace(metadata(START).replace(/ class="[^"]*"/, ''), ''),
        body.replace(END, START + END),
        body.replace('RT-SIGNATURE-MANAGED-V1RT-SIGNATURE-VERSION', 'RT-SIGNATURE-MANAGED-V1foreignRT-SIGNATURE-VERSION'),
        body.replace('0123456789abcdef', 'unknown'),
        body.replace(MARKER, MARKER + '-FOREIGN'),
        body.replace(`>${START}</span>`, `>${START}</span>${metadata(START)}`),
        body.replace(`>${END}</span>`, `>${END}</span><p>Foreign note outside region</p>`),
        body.replace(`style="display:none!important;mso-hide:all;font-size:0;line-height:0;">${START}`, `style="display:block;">${START}`).replace(`aria-hidden="true" style="display:block;">${START}`, `style="display:block;">${START}`),
        source.replace('rt-managed-template-v1', 'rt-managed-template-v2'),
        source.replace('rt-managed-template-v1', 'rt-managed-template-vUnknown'),
        source.replace('data-rt-compose-document="combined-v1"', 'data-rt-compose-document="other"'),
        source.replace('data-rt-compose-editable="v1"', 'data-rt-compose-editable="v2"'),
        '<blockquote>' + body + '</blockquote>',
        afterOfficeSet(stableArtifact({ content: TYPED })),
    ];
    invalid.forEach((value, index) => assert.throws(() => replace(value, stableArtifact({ sentinels: true })), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' }, 'invalid ' + index));
    assert.throws(() => replace(body, stableArtifact()), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' }, 'cannot discard durable boundaries');
});

function nativeHost({ footer = false, responsive = false, wrappers = false, templateGutter = false } = {}) {
    const h = host({ body: OUTSIDE + afterOfficeTyping(stableArtifact({ sentinels: true, content: TYPED, native: true, footer, responsive, wrappers, templateGutter })) + '<p>Outside after</p>' });
    h.item.body.setAsync = (html, _options, callback) => {
        h.state.writes.push(html);
        h.state.body = afterOfficeSet(html);
        callback(succeeded());
    };
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        h.state.body = officeRetainedCarrier(html) + h.state.body;
        callback(succeeded());
    };
    h.run = (options = {}, check = () => {}) => replaceManagedTemplate(h.office, h.item, stableArtifact({ sentinels: true, native: true, greeting: true, footer, responsive, wrappers, templateGutter }), check, options);
    return h;
}

function initialNativeHost({ composeType = 'newMail' } = {}) {
    const quote = composeType === 'newMail' ? '' : '<div id="divRplyFwdMsg"><blockquote>' + artifact({ name: 'history' }) + '</blockquote></div>';
    const original = OUTSIDE + TYPED + quote;
    const h = host({ composeType, body: original, session: '' });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        h.state.body = (html.includes('data-rt-native-layout-restore') ? officeRetainedCarrier(html) : afterOfficeSet(html)) + h.state.body;
        callback(succeeded());
    };
    h.item.body.setAsync = () => { throw Error('Initial insertion must never rewrite the body'); };
    h.run = (options = {}, check = () => {}) => prependTemplate(h.office, h.item,
        stableArtifact({ sentinels: true, native: true, footer: true, responsive: true, wrappers: true, templateGutter: true }), check, options);
    return { ...h, original };
}

test('initial desktop personal combined prepend annotates ten IDs and confirms the separate core before completion', async () => {
    for (const composeType of ['newMail', 'reply', 'forward']) {
        const h = initialNativeHost({ composeType });
        await h.run();
        assert.equal(h.state.prepends.length, 2);
        assert.equal((h.state.prepends[0].match(/ id="rt-native-layout-[0-9a-f]{32}-[rpcthslifq]"/g) || []).length, 10);
        assert.ok(h.state.prepends[1].includes('data-rt-native-layout-restore'));
        assert.ok(h.state.body.endsWith(h.original), 'Typed content and complete quoted history survive byte-exact.');
        assert.equal(h.state.session, '1'); assert.equal(isTemplateInsertionBlocked(h.item), false);
        await assert.rejects(h.run(), { code: 'TEMPLATE_ALREADY_INSERTED' });
        assert.equal(h.state.prepends.length, 2);
    }
});

test('initial combined insertion retains the lock and pending session until carrier readback completes', async () => {
    const h = initialNativeHost(); let finish;
    const original = h.item.body.prependAsync;
    h.item.body.prependAsync = (html, options, callback) => {
        if (!html.includes('data-rt-native-layout-restore')) return original(html, options, callback);
        h.state.prepends.push(html); finish = () => { h.state.body = officeRetainedCarrier(html) + h.state.body; callback(succeeded()); };
    };
    const pending = h.run(); await flush(() => finish);
    assert.equal(h.state.prepends.length, 2); assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_IN_PROGRESS' });
    finish(); await pending;
    assert.equal(h.state.session, '1'); assert.equal(isTemplateInsertionBlocked(h.item), false);
});

test('initial combined layout damage after its body prepend stays quarantined without another body write', async () => {
    for (const damaged of ['ids', 'core']) {
        const h = initialNativeHost();
        h.item.body.prependAsync = (html, _options, callback) => {
            h.state.prepends.push(html);
            let output = html.includes('data-rt-native-layout-restore') ? officeRetainedCarrier(html) : afterOfficeSet(html);
            if (damaged === 'ids') output = output.replace(/ id="x_rt-native-layout-[^"]*-i"/, '');
            if (damaged === 'core') output = output.replace('max-width:340px!important;', 'max-width:341px!important;');
            h.state.body = output + h.state.body; callback(succeeded());
        };
        await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.prepends.length, damaged === 'ids' ? 1 : 2);
        assert.ok(h.state.body.endsWith(h.original)); assert.match(h.state.session, /^pending:/);
        await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    }
});

test('definite failure of the initial combined body prepend releases its own claim and allows a fresh retry', async () => {
    const h = initialNativeHost(); const prepend = h.item.body.prependAsync;
    h.item.body.prependAsync = (_html, _options, callback) => callback({ status: 'failed', error: { code: 'WRITE_FAILED' } });
    await assert.rejects(h.run(), { code: 'WRITE_FAILED' });
    assert.equal(h.state.body, h.original); assert.equal(h.state.session, '');
    assert.equal(isTemplateInsertionBlocked(h.item), false);
    h.item.body.prependAsync = prepend; await h.run();
    assert.equal(h.state.prepends.length, 2); assert.equal(h.state.session, '1');
});

test('obsolete actual one-row PHP wire is rejected before native cleanup or any session/body write', async () => {
    const incoming = readFileSync(new URL('../Fixtures/outlook/r61-combined-initial-wire.html', import.meta.url), 'utf8');
    const oldSource = standaloneHtml('0123456789abcdef', false);
    const old = prepareNativeLayoutRestore(oldSource, standaloneSignatureContract(oldSource), 'a'.repeat(32));
    const oldSlot = nativeOfficeHtml(old.html);
    const h = initialNativeHost();
    h.state.body = OUTSIDE + officeCarrier(old.stylePayload) + oldSlot;
    const snapshot = h.state.body; let cleanup = 0;
    await assert.rejects(prependTemplate(h.office, h.item, incoming, () => {}, {
        beforeInsert() { cleanup += 1; h.state.body = h.state.body.replace(oldSlot, ''); },
    }), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    assert.equal(cleanup, 0); assert.deepEqual(h.state.claims, []); assert.deepEqual(h.state.prepends, []);
    assert.equal(h.state.body, snapshot); assert.equal(h.state.session, ''); assert.equal(isTemplateInsertionBlocked(h.item), false);
});

test('old carrier inside quoted history stays opaque while current first insertion confirms its own core', async () => {
    const incoming = stableArtifact({ sentinels: true, native: true, footer: true, responsive: true, wrappers: true, templateGutter: true });
    const oldSource = standaloneHtml('0123456789abcdef', false);
    const old = prepareNativeLayoutRestore(oldSource, standaloneSignatureContract(oldSource), 'a'.repeat(32));
    const quote = '<blockquote>' + officeCarrier(old.stylePayload) + nativeOfficeHtml(old.html) + '</blockquote>';
    const h = initialNativeHost(); h.state.body = OUTSIDE + quote;
    await prependTemplate(h.office, h.item, incoming);
    assert.equal(h.state.prepends.length, 2); assert.ok(h.state.body.endsWith(OUTSIDE + quote));
    assert.equal(h.state.session, '1'); assert.equal(isTemplateInsertionBlocked(h.item), false);
});

test('an unbound or damaged current external carrier blocks initial combined insertion without touching unknown markup', async () => {
    const incoming = stableArtifact({ sentinels: true, native: true, footer: true, responsive: true, wrappers: true, templateGutter: true });
    const source = standaloneHtml();
    const old = prepareNativeLayoutRestore(source, standaloneSignatureContract(source), 'a'.repeat(32));
    for (const body of [OUTSIDE + officeCarrier(old.stylePayload),
        OUTSIDE + officeCarrier(old.stylePayload).replace('max-width:340px!important;', 'max-width:341px!important;') + nativeOfficeHtml(old.html)]) {
        const h = initialNativeHost(); h.state.body = body; let cleanup = 0;
        await assert.rejects(prependTemplate(h.office, h.item, incoming, () => {}, { beforeInsert() { cleanup += 1; } }), { code: 'SIGNATURE_LAYOUT_LEGACY_CARRIER' });
        assert.equal(h.state.body, body); assert.equal(cleanup, 0); assert.deepEqual(h.state.claims, []); assert.deepEqual(h.state.prepends, []);
    }
});

test('verified current external carriers and verified legacy carriers inside the replaced native slot keep their safe transition', async () => {
    const incoming = stableArtifact({ sentinels: true, native: true, footer: true, responsive: true, wrappers: true, templateGutter: true });
    for (const responsive of [false, true]) {
        const source = standaloneHtml('0123456789abcdef', responsive);
        const old = prepareNativeLayoutRestore(source, standaloneSignatureContract(source), 'a'.repeat(32));
        let slot = nativeOfficeHtml(old.html);
        const carrier = officeCarrier(old.stylePayload);
        if (!responsive) slot = slot.replace('<td>', '<td>' + carrier);
        const h = initialNativeHost(); h.state.body = OUTSIDE + (responsive ? carrier : '') + slot;
        await prependTemplate(h.office, h.item, incoming, () => {}, { beforeInsert() { h.state.body = h.state.body.replace(slot, ''); } });
        assert.equal(h.state.prepends.length, 2); assert.equal(h.state.session, '1');
        assert.equal(isTemplateInsertionBlocked(h.item), false);
        if (!responsive) assert.equal(h.state.body.includes('RT-NATIVE-LAYOUT-RESTORE-V1:' + 'a'.repeat(32)), false);
    }
});

test('native replacement confirms unique retained IDs before STYLE-only restore and completes only afterward', async () => {
    const h = nativeHost();
    let styleCallback;
    h.item.body.prependAsync = (html, _options, callback) => { h.state.prepends.push(html); styleCallback = callback; };
    const result = h.run();
    await flush(() => styleCallback);
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
    assert.match(h.state.prepends[0], /^<table\b/);
    assert.match(h.state.prepends[0], /<style data-rt-native-layout-restore="v1">/);
    assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
    h.state.body = officeRetainedCarrier(h.state.prepends[0]) + h.state.body;
    styleCallback(succeeded());
    await result;
    assert.equal(h.state.session, '1');
    assert.equal(isTemplateInsertionBlocked(h.item), false);
    assert.ok(h.state.body.includes(afterOfficeSet(TYPED)));
});

test('incoming native shells without repeatable text boundaries fail before any body or session mutation', async () => {
    const h = nativeHost();
    await assert.rejects(replaceManagedTemplate(h.office, h.item, stableArtifact({ native: true })), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE' });
    assert.deepEqual(h.state.writes, []);
    assert.deepEqual(h.state.prepends, []);
    assert.deepEqual(h.state.claims, []);
});

test('native repeated exchanges preserve content and remove only the exact preceding owned layout sheet', async () => {
    const h = nativeHost();
    h.state.body += '<style>.unrelated{color:blue}</style>';
    for (let count = 1; count <= 3; count++) {
        await h.run();
        assert.equal(h.state.writes.length, count);
        assert.equal(h.state.prepends.length, count);
        assert.equal((h.state.body.match(/data-rt-native-layout-restore=/g) || []).length, 1);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.ok(h.state.body.endsWith('<p>Outside after</p><style>.unrelated{color:blue}</style>'));
        assert.equal(isTemplateInsertionBlocked(h.item), false);
    }
});

test('v5 seven-ID combined exchanges remove only the verified preceding carrier and preserve user/quote bytes', async () => {
    const h = nativeHost({ footer: true, responsive: true });
    h.state.body += '<style>.foreign{color:blue}</style><blockquote><p>Preserved history</p></blockquote>';
    for (let count = 1; count <= 3; count++) {
        await h.run();
        assert.equal(h.state.writes.length, count); assert.equal(h.state.prepends.length, count);
        assert.equal((h.state.body.match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
        assert.equal((h.state.writes.at(-1).match(/ id="rt-native-layout-[0-9a-f]{32}-[rpcthsl]"/g) || []).length, 7);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.ok(h.state.body.endsWith('<p>Outside after</p><style>.foreign{color:blue}</style><blockquote><p>Preserved history</p></blockquote>'));
        assert.equal(isTemplateInsertionBlocked(h.item), false);
    }
});

test('R63 one-row mixed carrier migrates only through exact owned cleanup while quote and editable bytes survive', async () => {
    const frozen = readFileSync(new URL('../Fixtures/outlook/r63-native-mixed-carrier.html', import.meta.url), 'utf8').trimEnd();
    const historical = standaloneSignatureContract(frozen);
    const carrier = historical.elements.find(node => (node.attrs.id || '').endsWith('-carrier'));
    const signature = frozen.slice(historical.signature.node.start, historical.signature.node.end);
    let old = stableArtifact({ sentinels: true, content: TYPED, native: true, footer: true, responsive: true, wrappers: true });
    const contract = managedTemplateContract(old);
    old = frozen.slice(carrier.start, carrier.end) + old.slice(0, contract.signature.node.start) + signature + old.slice(contract.signature.node.end);
    const quote = '<blockquote>' + old.replaceAll('63'.repeat(16), '64'.repeat(16)) + '</blockquote>';
    const h = host({ body: OUTSIDE + old + quote });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html); h.state.body = html + h.state.body; callback(succeeded());
    };
    await replaceManagedTemplate(h.office, h.item, stableArtifact({ sentinels: true, native: true, greeting: true, footer: true, responsive: true, wrappers: true }));
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
    assert.ok(h.state.body.endsWith(quote));
    const current = h.state.body.slice(0, -quote.length);
    assert.ok(current.includes(TYPED));
    assert.equal(current.includes('rt-native-layout-' + '63'.repeat(16)), false);
    assert.equal(h.state.session, '1');
});

test('v5 nine-ID combined refresh preserves outer legal metadata and removes the exact old carrier', async () => {
    const h = nativeHost({ footer: true, responsive: true, wrappers: true });
    for (let count = 1; count <= 3; count++) {
        await h.run();
        assert.equal((h.state.body.match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
        assert.equal((h.state.writes.at(-1).match(/ id="rt-native-layout-[0-9a-f]{32}-[rpcthslif]"/g) || []).length, 9);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.equal(h.state.body.split(OUTSIDE).length - 1, 1); assert.ok(h.state.body.endsWith('<p>Outside after</p>'));
        assert.equal(isTemplateInsertionBlocked(h.item), false);
    }
});

test('v5 ten-ID combined refresh scopes template q outside signature and removes each exact preceding carrier', async () => {
    const h = nativeHost({ footer: true, responsive: true, wrappers: true, templateGutter: true });
    for (let count = 1; count <= 3; count++) {
        await h.run();
        assert.equal((h.state.body.match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
        assert.equal((h.state.writes.at(-1).match(/ id="rt-native-layout-[0-9a-f]{32}-[rpcthslifq]"/g) || []).length, 10);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.equal(h.state.body.split(OUTSIDE).length - 1, 1); assert.ok(h.state.body.endsWith('<p>Outside after</p>'));
        assert.equal(isTemplateInsertionBlocked(h.item), false);
    }
});

function officeInsetImage(html, { depth = 1, tag = 'div', style = 'width:100%;height:34px;max-height:34px;display:block;', text = '', duplicate = false } = {}) {
    const contract = managedTemplateContract(html);
    const inset = contract.elements.find(node => (node.attrs.id || '').endsWith('-i'));
    const image = contract.elements.find(node => node.tag === 'img' && node.start > inset.start && node.end < inset.end);
    const opening = html.slice(image.start, image.openEnd).replace(/\swidth="100%"/, '').replace(/>$/, ' style="' + style + '">');
    const content = ('<' + tag + ' style="font-family:Aptos;font-size:13px;">').repeat(depth) + text + opening + (duplicate ? opening : '') + ('</' + tag + '>').repeat(depth);
    return html.slice(0, image.start) + content + html.slice(image.end);
}

test('observed inset image typography DIVs/SPANs and removed percent-width attribute retain exact ten-ID ownership', async () => {
    for (const [depth, tag] of [[0, 'div'], [1, 'div'], [2, 'div'], [1, 'span'], [2, 'span']]) {
        const h = nativeHost({ footer: true, responsive: true, wrappers: true, templateGutter: true });
        h.item.body.setAsync = (html, _options, callback) => { h.state.writes.push(html); h.state.body = officeInsetImage(afterOfficeSet(html), { depth, tag }); callback(succeeded()); };
        await h.run();
        assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1);
        assert.equal(isTemplateInsertionBlocked(h.item), false);
    }
});

test('an explicit opposite direct TD alignment stops current layout recovery before its carrier prepend', async () => {
    for (const suffix of ['l', 'p', 'c', 't']) {
        const h = nativeHost({ footer: true, responsive: true, wrappers: true, templateGutter: true });
        h.item.body.setAsync = (html, _options, callback) => {
            h.state.writes.push(html);
            let output = afterOfficeSet(html);
            const contract = managedTemplateContract(output);
            const table = contract.elements.findIndex(node => (node.attrs.id || '').endsWith('-' + suffix));
            const body = contract.elements.findIndex(node => node.parent === table && node.tag === 'tbody');
            const row = contract.elements.findIndex(node => node.parent === body && node.tag === 'tr');
            const cell = contract.elements.find(node => node.parent === row && node.tag === 'td');
            output = output.slice(0, cell.openEnd - 1) + ' style="direction:ltr;text-align:right"' + output.slice(cell.openEnd - 1);
            h.state.body = output; callback(succeeded());
        };
        await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 0);
        assert.match(h.state.session, /^pending:/); assert.equal(isTemplateInsertionBlocked(h.item), true);
    }
});

test('sanitized actual one-row R59 and R59b Office DOMs cannot acknowledge a new two-row operation', async () => {
    for (const file of ['r59-office-inset-wrapper.html', 'r59b-office-inset-wrapper.html']) {
    const captured = readFileSync(new URL('../Fixtures/outlook/' + file, import.meta.url), 'utf8');
    const observed = managedTemplateContract(captured);
    assert.equal(observed.kind, 'text-v1');
    assert.equal(observed.signatureMetadata.managed, observed.signatureMetadata.version, 'The real Office body coalesced hidden signature metadata.');
    const h = nativeHost({ footer: true, responsive: true, wrappers: true, templateGutter: true });
    h.item.body.setAsync = (html, _options, callback) => {
        h.state.writes.push(html);
        const nonce = /id="rt-native-layout-([0-9a-f]{32})-r"/.exec(html)[1];
        h.state.body = captured.replaceAll('rt-native-layout-11223344556677889900aabbccddeeff-', 'rt-native-layout-' + nonce + '-');
        callback(succeeded());
    };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 0);
    assert.match(h.state.session, /^pending:/); assert.equal(isTemplateInsertionBlocked(h.item), true);
    }
});

test('inset readback still rejects extra content/images, excessive wrappers and ambiguous CSS width before prepend', async () => {
    for (const changes of [{ depth: 3 }, { depth: 3, tag: 'span' }, { tag: 'section' }, { text: 'User text' }, { duplicate: true },
        { style: '' }, { style: 'width:50%;' }, { style: 'width:100%!important;' },
        { style: 'width:100%;width:100%;' }, { style: 'width:100%;max-width:40px;' },
        { style: 'font-family:&quot;name;width:100%;&quot;;' }]) {
        const h = nativeHost({ footer: true, responsive: true, wrappers: true, templateGutter: true });
        h.item.body.setAsync = (html, _options, callback) => { h.state.writes.push(html); h.state.body = officeInsetImage(afterOfficeSet(html), changes); callback(succeeded()); };
        await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 0);
    }
});

test('function-free required carrier core completes even when Office drops the separate responsive sheet', async () => {
    for (const keepEmpty of [false, true]) {
        const h = nativeHost({ footer: true, responsive: true, wrappers: true, templateGutter: true });
        h.item.body.prependAsync = (html, _options, callback) => {
            h.state.prepends.push(html);
            const filtered = html.replace(/<style data-rt-native-layout-responsive="v1">[\s\S]*?<\/style>/,
                keepEmpty ? '<style></style>' : '');
            h.state.body = officeRetainedCarrier(filtered) + h.state.body;
            callback(succeeded());
        };
        await h.run();
        assert.equal(h.state.writes.length, 1); assert.equal(h.state.prepends.length, 1);
        assert.equal(h.state.session, '1'); assert.equal(isTemplateInsertionBlocked(h.item), false);
        const retainedCss = [...h.state.body.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/g)].map(match => match[1]).join('');
        assert.ok(retainedCss.includes('max-width:340px!important;'));
        assert.doesNotMatch(retainedCss, /min\(|max\(|calc\(|@media/);
    }
});

test('a confirmed prepend callback without retained trusted CSS never completes the layout operation', async () => {
    const h = nativeHost();
    h.item.body.prependAsync = (html, _options, callback) => { h.state.prepends.push(html); callback(succeeded()); };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
    assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
    assert.ok(h.state.body.includes(afterOfficeSet(TYPED)));
    await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
});

test('a retained carrier with an emptied STYLE remains layout-unconfirmed after the successful callback', async () => {
    const h = nativeHost({ footer: true });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        h.state.body = html.replace(/(<style\b[^>]*>)[\s\S]*?(<\/style>)/, '$1\n $2') + h.state.body;
        callback(succeeded());
    };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
    assert.match(h.state.body, /<style\b[^>]*>\s*<\/style>/);
    assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
});

test('unprefixed CSS cannot confirm a layout whose actual target IDs have the Office prefix', async () => {
    const h = nativeHost({ footer: true });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html); h.state.body = html + h.state.body; callback(succeeded());
    };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.match(h.state.session, /^pending:/);
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
});

test('exact Office ID-prefix and final-semicolon serialization permit three confirmed exchanges', async () => {
    const h = nativeHost({ footer: true });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        const retained = officeRetainedCarrier(html).replace(/;}/g, '}');
        h.state.body = retained + h.state.body;
        callback(succeeded());
    };
    for (let attempt = 1; attempt <= 3; attempt++) {
        await h.run();
        assert.equal(h.state.writes.length, attempt);
        assert.equal(h.state.prepends.length, attempt);
        assert.equal((h.state.body.match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.equal(h.state.session, '1');
    }
});

test('the observed Office prepend position in the unique Combined TD before Template preserves three exchanges', async () => {
    const h = nativeHost({ footer: true });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        const retained = officeRetainedCarrier(html).replace(/;}/g, '}');
        const contract = managedTemplateContract(h.state.body);
        const tbody = contract.elements.findIndex(node => node.parent === contract.rootIndex && node.tag === 'tbody');
        const row = contract.elements.findIndex(node => node.parent === tbody && node.tag === 'tr');
        const cell = contract.elements.find(node => node.parent === row && node.tag === 'td');
        h.state.body = h.state.body.slice(0, cell.openEnd) + retained + h.state.body.slice(cell.openEnd);
        callback(succeeded());
    };
    for (let attempt = 1; attempt <= 3; attempt++) {
        await h.run();
        assert.equal(h.state.writes.length, attempt);
        assert.equal(h.state.prepends.length, attempt);
        assert.equal((h.state.body.match(/RT-NATIVE-LAYOUT-RESTORE-V1:/g) || []).length, 1);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.equal(h.state.session, '1');
    }
});

test('a carrier moved into the editable user region is never accepted as a trusted layout write', async () => {
    const h = nativeHost({ footer: true });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        const contract = managedTemplateContract(h.state.body);
        h.state.body = h.state.body.slice(0, contract.region.start) + officeRetainedCarrier(html) + h.state.body.slice(contract.region.start);
        callback(succeeded());
    };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.match(h.state.session, /^pending:/);
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
});

test('a changed layout rule remains unconfirmed even when the carrier and all IDs survive', async () => {
    const h = nativeHost({ footer: true });
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html);
        const changed = html.replace(/(<style\b[^>]*>)([\s\S]*?)(<\/style>)/, (_all, open, css, close) => open + css.replace('{overflow:hidden!important;}', '{overflow:visible!important;}') + close);
        h.state.body = officeRetainedCarrier(changed) + h.state.body; callback(succeeded());
    };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.match(h.state.session, /^pending:/);
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
});

test('a foreign session after the confirmed style write prevents the final applied marker', async () => {
    const h = nativeHost();
    h.item.body.prependAsync = (html, _options, callback) => {
        h.state.prepends.push(html); h.state.body = html + h.state.body; h.state.session = 'pending:foreign-after-style'; callback(succeeded());
    };
    await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
    assert.equal(h.state.session, 'pending:foreign-after-style');
    assert.equal(h.state.writes.length, 1);
    assert.equal(h.state.prepends.length, 1);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
});

test('the optional signature TABLE ID scopes gray recovery to exactly the second legal row', async () => {
    const h = nativeHost({ footer: true });
    await h.run();
    assert.equal((h.state.writes[0].match(/id="rt-native-layout-[0-9a-f]{32}-s"/g) || []).length, 1);
    assert.match(h.state.prepends[0], /#rt-native-layout-[0-9a-f]{32}-s>tbody>tr:last-child font/);
    assert.doesNotMatch(h.state.prepends[0], /-s>tbody>tr:first-child[^{}]*\{color:#718096/);
    assert.equal(h.state.session, '1');
});

test('moved legal metadata, additional rows or a footer HEADER ID block any gray recovery before prepend', async () => {
    for (const corrupt of [
        value => {
            const c = managedTemplateContract(value);
            const body = c.elements.find(n => n.parent === c.signature.index && n.tag === 'tbody');
            return value.slice(0, body.close) + '<tr><td></td></tr>' + value.slice(body.close);
        },
        value => {
            const c = managedTemplateContract(value);
            const span = c.elements[c.signatureMetadata.managed];
            const bodyIndex = c.elements.findIndex(n => n.parent === c.signature.index && n.tag === 'tbody');
            const rowIndex = c.elements.findIndex(n => n.parent === bodyIndex && n.tag === 'tr');
            const cell = c.elements.find(n => n.parent === rowIndex && n.tag === 'td');
            return value.slice(0, cell.close) + value.slice(span.start, span.end) + value.slice(cell.close, span.start) + value.slice(span.end);
        },
        value => {
            const header = / id="(x_rt-native-layout-[^"]*-h)"/.exec(value);
            const without = value.replace(header[0], '');
            const c = managedTemplateContract(without);
            const metadata = c.elements[c.signatureMetadata.managed];
            return without.slice(0, metadata.start) + `<table id="${header[1]}"><tbody><tr><td></td></tr></tbody></table>` + without.slice(metadata.start);
        },
    ]) {
        const h = nativeHost({ footer: true });
        h.item.body.setAsync = (html, _options, callback) => { h.state.writes.push(html); h.state.body = corrupt(afterOfficeSet(html)); callback(succeeded()); };
        await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.prepends.length, 0);
        assert.match(h.state.session, /^pending:/);
    }
});

test('the native post-set readback accepts only balanced observed boundary DIVs before restoring layout', async () => {
    const h = nativeHost();
    h.item.body.setAsync = (html, _options, callback) => {
        h.state.writes.push(html); h.state.body = officeBoundaryDivs(afterOfficeSet(html)); callback(succeeded());
    };
    for (let attempt = 1; attempt <= 3; attempt++) {
        await h.run();
        assert.equal(h.state.writes.length, attempt);
        assert.equal(h.state.prepends.length, attempt);
        assert.equal((h.state.body.match(/My &amp;/g) || []).length, 1);
        assert.equal(h.state.session, '1');
        assert.equal(isTemplateInsertionBlocked(h.item), false);
    }
});

test('missing, duplicate, foreign-prefixed or quoted native IDs prevent the layout write without repeating the body', async () => {
    for (const corrupt of [
        value => value.replace(/ id="x_rt-native-layout-[^"]*-p"/, ''),
        value => value.replace(/(<table\b[^>]*id="(x_rt-native-layout-[^"]*-p)"[^>]*>)/, '$1<table id="$2"><tbody><tr><td></td></tr></tbody></table>'),
        value => value.replace(/id="x_rt-native-layout-/, 'id="foreign_rt-native-layout-'),
        value => {
            const header = / id="(x_rt-native-layout-[^"]*-h)"/.exec(value);
            const without = value.replace(header[0], '');
            const signature = managedTemplateContract(without).signature.node;
            return without.slice(0, signature.openEnd - 1) + ` id="${header[1]}">` + without.slice(signature.openEnd);
        },
        value => value + '<blockquote><table id="' + /id="(x_rt-native-layout-[^"]*-p)"/.exec(value)[1] + '"><tbody><tr><td></td></tr></tbody></table></blockquote>',
    ]) {
        const h = nativeHost();
        h.item.body.setAsync = (html, _options, callback) => { h.state.writes.push(html); h.state.body = corrupt(afterOfficeSet(html)); callback(succeeded()); };
        await assert.rejects(h.run(), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.writes.length, 1);
        assert.equal(h.state.prepends.length, 0);
        assert.match(h.state.session, /^pending:/);
        await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    }
});

test('foreign session or item change after body success prevents STYLE and keeps the preserved body quarantined', async () => {
    for (const itemChange of [false, true]) {
        const h = nativeHost();
        let guard = false;
        h.item.body.setAsync = (html, _options, callback) => {
            h.state.writes.push(html); h.state.body = afterOfficeSet(html);
            if (itemChange) guard = true; else h.state.session = 'pending:foreign';
            callback(succeeded());
        };
        await assert.rejects(h.run({}, () => { if (guard) throw new Error('item changed'); }), { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' });
        assert.equal(h.state.prepends.length, 0);
        assert.ok(h.state.body.includes(afterOfficeSet(TYPED)));
        assert.equal(isTemplateInsertionBlocked(h.item), true);
    }
});

test('uncertain first native body write never authorizes a late layout write or automatic retry', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = nativeHost();
    let callback;
    h.item.body.setAsync = (html, _options, complete) => { h.state.writes.push(html); callback = complete; };
    const pending = h.run().then(() => null, error => error);
    await flush(() => callback);
    t.mock.timers.tick(TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
    assert.equal((await pending).code, 'TEMPLATE_INSERT_UNCERTAIN');
    callback(succeeded()); await flush();
    assert.equal(h.state.prepends.length, 0);
    assert.match(h.state.session, /^pending:/);
    assert.equal(isTemplateInsertionBlocked(h.item), true);
});

test('uncertain or failed STYLE confirmation never rewrites the body, retries STYLE or completes the session', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    for (const definite of [false, true]) {
        const h = nativeHost();
        let callback;
        h.item.body.prependAsync = (html, _options, complete) => { h.state.prepends.push(html); callback = complete; };
        const pending = h.run().then(() => null, error => error);
        await flush(() => callback);
        if (definite) callback({ status: 'failed', error: { code: 'WRITE_FAILED' } });
        else t.mock.timers.tick(TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
        assert.equal((await pending).code, 'TEMPLATE_LAYOUT_UNCONFIRMED');
        callback(succeeded()); await flush();
        assert.equal(h.state.writes.length, 1);
        assert.equal(h.state.prepends.length, 1);
        assert.match(h.state.session, /^pending:/);
        await assert.rejects(h.run(), { code: 'TEMPLATE_INSERT_UNCERTAIN' });
    }
});
