// Receives the private offline PHP document over stdin. Original Word HTML
// stays private; only the production-redacted shell and safe aggregates leave.
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseManagedHtml } from '../../../../resources/js/outlook-addin/managed-template.js';
import { universalComposeDiagnosticFixture, validateUniversalComposeDocument, universalComposeStateFromBody } from '../../../../resources/js/outlook-addin/u2e-compose.js';
import { universalDiagnosticReplayHtml } from '../../../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const inputPath = '.lmzdev/artifacts/temp/codex-uebergabe-signatur/belege/umsetzung/C/pruefung-claude-0411/classic-antwort/Ent-1103-2357-AW-Test-aus-Desktop-Outlook-ohne-einf-gung-da-automatisc.html';
const originalBytes = fs.readFileSync(inputPath), inputHash = '6d6b0cbb3d8302e5c6df2f936332d07d2a075524d89cf31767d9be2bea62a7c2';
if (hash(originalBytes) !== inputHash) throw new Error('IMMUTABLE_INPUT_HASH_MISMATCH');
let input = ''; for await (const chunk of process.stdin) input += chunk;
const wire = JSON.parse(input); if (wire.queryCount !== 0) throw new Error('OFFLINE_QUERY_NONZERO');
const source = validateUniversalComposeDocument(wire.document), original = originalBytes.toString('utf8');
if (hash(source.html) !== 'dce2d1649f3998e00e5e2d5db97550e99c97e2f14020df24f49267a1f8560bd0') throw new Error('IMMUTABLE_SOURCE_HASH_MISMATCH');
const originalState = universalComposeStateFromBody(source, original, 'newMail');
if (!originalState.present || originalState.kind !== 'combined') throw new Error('ORIGINAL_MATERIAL_NOT_RECOGNIZED');
const pair = universalComposeDiagnosticFixture(source, original, 'reply');
if (!pair) throw new Error('PRODUCTION_REDACTION_FAILED');
function combined(replay) {
    if (typeof replay !== 'string') throw new Error('DIAGNOSTIC_REPLAY_FAILED');
    const root = parseManagedHtml(replay).elements.find(node => node.tag === 'table');
    return replay.slice(0, root.openEnd) + '<tr><td colspan="4">rt-redacted-author</td></tr>' + replay.slice(root.openEnd);
}
const sourceHtml = combined(universalDiagnosticReplayHtml(pair.expectedHtml));
const actualHtml = combined(universalDiagnosticReplayHtml(pair.html));
const redactedSource = { ...source, html: sourceHtml, sourceHash: hash(sourceHtml) };
validateUniversalComposeDocument(redactedSource);
const state = universalComposeStateFromBody(redactedSource, actualHtml, 'newMail');
if (!state.present || state.kind !== 'combined' || state.current || state.checkpoint !== 'evidence.links' || state.difference.index !== 0) throw new Error('REDACTED_MATERIAL_PROOF_CHANGED');
const reply = actualHtml + '<hr><div id=divRplyFwdMsg>rt-redacted</div><p>rt-redacted</p>';
const artifacts = { 'source.json': JSON.stringify(redactedSource, null, 2) + '\n', 'actual.redacted.html': actualHtml + '\n', 'reply.redacted.html': reply + '\n' };
for (const [name, content] of Object.entries(artifacts)) fs.writeFileSync(new URL(name, import.meta.url), content);
const manifest = { schema: 1,
    original: { bytes: originalBytes.length, sha256: inputHash, material: { present: originalState.present, kind: originalState.kind,
        images: parseManagedHtml(pair.html).elements.filter(node => node.tag === 'img').length,
        links: parseManagedHtml(pair.html).elements.filter(node => node.tag === 'a').length },
        wholeBodyReader: originalState, replyReader: universalComposeStateFromBody(source, original, 'reply') },
    source: { privateInputSha256: '95124735d6302bc8a4e71e73f924ff626be91a16547082a43874f75f9089928d',
        htmlSha256: hash(source.html), utf16Length: source.html.length, queryCount: wire.queryCount },
    method: 'Production scoped redactor removes author/user/quote text, masks all URLs, and keeps Word geometry/background omissions plus accepted center DIV. Diagnostic URL surrogates restore hash-preserving comparisons. One neutral author row is added to each redacted signature to retain combined-kind tests. reply.redacted.html appends a neutral quote with the observed unquoted known boundary ID.',
    limitations: ['No original business text, URLs or quote content are copied.', 'Fixed signature text tokens cannot reproduce raw text differences.',
        'Original material recognition is not a native compose acknowledgement.', 'The original first link differs in raw length/hash; no production URL equivalence relaxation is applied.'],
    redactedReader: state,
    artifacts: Object.fromEntries(Object.entries(artifacts).map(([name, content]) => [name, { sha256: hash(content), bytes: Buffer.byteLength(content) }])) };
fs.writeFileSync(new URL('manifest.json', import.meta.url), JSON.stringify(manifest, null, 2) + '\n');
console.log(JSON.stringify({ generated: Object.keys(artifacts).length, materialPresent: state.present, kind: state.kind,
    current: state.current, checkpoint: state.checkpoint, queryCount: wire.queryCount }));
