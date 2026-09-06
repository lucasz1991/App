import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('const showFindings = (report, compatibility = undefined) => {');
const end = view.indexOf('// Bereits der serverseitig geladene Entwurf', start);
assert.ok(start > 0 && end > start);
const functionSource = view.slice(start, end);

function harness() {
    const summary = { hidden: true, textContent: '' };
    const classes = new Set(['hidden']);
    const findingsBox = {
        hidden: true,
        classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name) },
        querySelector: () => summary,
    };
    const findingsList = { items: [], replaceChildren() { this.items = []; }, appendChild(item) { this.items.push(item); } };
    const findingsTitle = { textContent: '' };
    const context = vm.createContext({
        findingsBox, findingsList, findingsTitle,
        runtimeBridge: { normalizeCompatibilityReport: (value) => value },
        compatibilityBlocksPublication: false, publishButton: { setAttribute() {} },
        setActionsBusy() {}, actionsBusy: false,
        window: { document: { createElement: () => ({ textContent: '' }) } },
    });
    vm.runInContext(`${functionSource}\nglobalThis.showFindings = showFindings;`, context);
    return { context, summary, findingsBox, findingsList, findingsTitle };
}

test('manual catalog checks remain visible after an automated pass without blocking publication', () => {
    const h = harness();
    h.context.showFindings(null, { status: 'pass', findings: [], checks: { automated: 24, manual: 54 } });
    assert.equal(h.findingsBox.hidden, false);
    assert.equal(h.summary.hidden, false);
    assert.match(h.summary.textContent, /24 Regeln automatisch geprüft/);
    assert.match(h.summary.textContent, /54 Regeln benötigen eine manuelle Prüfung/);
    assert.match(h.summary.textContent, /nicht verifiziert/);
    assert.equal(h.context.compatibilityBlocksPublication, false);
    assert.match(h.findingsTitle.textContent, /Clientprüfung bleibt separat/);
});

test('old reports do not invent automated coverage and an actual BLOCK still blocks publication', () => {
    const h = harness();
    h.context.showFindings(null, { status: 'pass', findings: [] });
    assert.equal(h.summary.hidden, true);
    assert.equal(h.summary.textContent, '');
    assert.equal(h.findingsBox.hidden, true);
    h.context.showFindings(null, { status: 'block', findings: [{ rule_id: 'EMAIL-X', message: 'Fehlt' }], checks: { automated: 1, manual: 1 } });
    assert.equal(h.context.compatibilityBlocksPublication, true);
    assert.match(h.findingsTitle.textContent, /blockiert/);
    assert.equal(h.findingsList.items[0].textContent, '[EMAIL-X] Fehlt');
});

test('findings use text nodes and malformed counts cannot inject markup', () => {
    const h = harness();
    h.context.showFindings({ messages: ['<img src=x onerror=alert(1)>'] }, {
        status: 'warn', findings: [], checks: { automated: '<svg>', manual: -1 },
    });
    assert.equal(h.findingsList.items[0].textContent, '<img src=x onerror=alert(1)>');
    assert.match(h.summary.textContent, /^0 Regeln automatisch geprüft · 0 Regeln/);
    assert.doesNotMatch(h.summary.textContent, /<svg>/);
});

test('mail editor explains format versus renderer and preserves the IMG-only rule', () => {
    assert.match(view, /OFT speichert eine Vorlage, MSG eine Nachricht/);
    assert.match(view, /Der Zug bleibt ein echtes IMG/);
    assert.match(view, /Negative Margins und positionierte Ebenen bleiben bearbeitbar/);
    assert.match(view, /data-mail-document-format-rules/);
    assert.match(view, /target="_blank" rel="noopener noreferrer"/);
});
