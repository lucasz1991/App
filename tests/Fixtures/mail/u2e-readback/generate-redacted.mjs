// Regeneration requires the private source envelope on stdin. No private
// document is written or printed. Run from the repository root after the
// isolated PHP projection described in manifest.json has produced the envelope.
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody, universalComposePresentationEvidence } from '../../../../resources/js/outlook-addin/u2e-compose.js';

const hash = input => crypto.createHash('sha256').update(input).digest('hex');
const fixtureRoot = new URL('./', import.meta.url);
const inputs = {
    outlook: { path: '.lmzdev/artifacts/temp/codex-uebergabe-signatur/belege/umsetzung/C/pruefung-claude-2311/entwurf-neues-outlook-2237.html', hash: '058bbf1a2a12c540aba40b8330ceb1a3687caba3ca19dbd687ee92943ef54cf6' },
    word: { path: '.lmzdev/artifacts/temp/codex-uebergabe-signatur/belege/tests-2026-11-03/u2/u2-antwort-word-umgeschrieben.html', hash: '0959d522976fda8f3bf1acc4e5fc89475af3c6322eaf2443593b90de4180b785' },
    ios: { path: '.lmzdev/artifacts/temp/codex-uebergabe-signatur/belege/umsetzung/C/pruefung-claude-2311/entwurf-outlook-ios-2240.html', hash: 'ff870dce44f40db62ea107d0ff440e16c4475bdac904a922c962f58146daef5f' },
};
function readInput(name) {
    const bytes = fs.readFileSync(inputs[name].path);
    if (hash(bytes) !== inputs[name].hash) throw new Error('IMMUTABLE_INPUT_HASH_MISMATCH');
    return bytes.toString('utf8');
}
const attributes = new Set(['id', 'class', 'width', 'height', 'align', 'valign', 'cellpadding', 'cellspacing', 'border', 'bgcolor', 'rowspan', 'colspan', 'style', 'src', 'href', 'alt', 'role']);
const styleKeys = /^(?:width|height|padding(?:-(?:top|right|bottom|left))?|margin(?:-(?:top|right|bottom|left))?|border(?:-(?:top|right|bottom|left|collapse|spacing))?|color|background(?:-color)?|text-align|line-height|display|vertical-align|font-(?:size|family|weight|style)|text-decoration|white-space|word-break|overflow-wrap)$/;
const version = '1234567890abcdef12345678', marker = 'rt-sig-u2-' + version;
function clean(node) {
        for (const child of [...node.childNodes]) {
            if (child.nodeType === 3 || child.nodeType === 8) child.remove();
            else clean(child);
        }
        if (!node.attributes) return;
        for (const attribute of [...node.attributes]) if (!attributes.has(attribute.name)) node.removeAttribute(attribute.name);
        if (node.id) {
            const match = /^(?:x_)?rt-sig-u2-[0-9a-f]{24}(-cell)?$/.exec(node.id);
            if (match) node.id = marker + (match[1] || ''); else node.removeAttribute('id');
        }
        if (node.hasAttribute('class')) {
            node.className = node.className.split(/\s+/).filter(value => /^(?:x_)?rt-[a-z-]+$|^MsoNormal$/.test(value)).join(' ');
            if (!node.className) node.removeAttribute('class');
        }
        if (node.hasAttribute('style')) {
            const declarations = node.getAttribute('style').split(';').filter(declaration => {
                const at = declaration.indexOf(':');
                return at >= 0 && styleKeys.test(declaration.slice(0, at).trim().toLowerCase())
                    && /^[a-z0-9#.,%()!\s-]+$/i.test(declaration.slice(at + 1).replace(/["']/g, ''))
                    && !/url\s*\(/i.test(declaration);
            }).map(declaration => declaration.replace(/["']/g, ''));
            node.setAttribute('style', declarations.join(';'));
        }
}
function redact(html) {
    const { document } = parseHTML(html);
    const root = [...document.querySelectorAll('table')].find(table => /^(?:x_)?rt-sig-u2-[0-9a-f]{24}$/.test(table.id));
    if (!root) throw new Error('SOURCE_ROOT_MISSING');
    clean(root);
    const rows = [...root.children].flatMap(node => node.localName === 'tbody' ? [...node.children] : [node]).filter(node => node.localName === 'tr');
    if (rows.length !== 4) throw new Error('SOURCE_ROWS_CHANGED');
    const grid = [...rows[1].children];
    [rows[0].firstElementChild, ...grid, rows[3].firstElementChild].forEach((cell, index) => cell.insertBefore(document.createTextNode(['Fixture author', 'Fixture person', 'Fixture direct', 'Fixture company', 'Fixture dispatch', 'Fixture legal'][index]), cell.firstChild));
    [...root.querySelectorAll('img')].forEach((image, index) => {
        const file = /\/([0-9a-f]{10}\.png)$/.exec(image.getAttribute('src'))?.[1];
        if (!file) throw new Error('SOURCE_MEDIA_CHANGED');
        image.setAttribute('src', 'https://mail.example.test/m/' + file); image.setAttribute('alt', 'Fixture image ' + index);
    });
    const hrefs = ['https://mail.example.test/', 'mailto:dispatch@example.invalid', 'https://wa.me/4915112345678', 'tel:+49301234567'];
    [...root.querySelectorAll('a')].forEach((link, index) => link.setAttribute('href', hrefs[index]));
    return root.outerHTML;
}
function redactLegacyWord(html) {
    const { document } = parseHTML(html);
    const roots = [...document.querySelectorAll('table')].filter(table => !table.parentElement?.closest('table'));
    for (const root of roots) {
        clean(root);
        [...root.querySelectorAll('img')].forEach((image, index) => {
            image.setAttribute('src', 'https://mail.example.test/legacy/image-' + index + '.png');
            image.setAttribute('alt', 'Fixture image ' + index);
        });
        [...root.querySelectorAll('a')].forEach((link, index) => link.setAttribute('href', 'https://mail.example.test/legacy/link-' + index));
    }
    return roots.map(root => root.outerHTML).join('\n');
}

let input = ''; for await (const chunk of process.stdin) input += chunk;
const wire = JSON.parse(input), source = validateUniversalComposeDocument(wire.document);
if (wire.queryCount !== 0) throw new Error('SOURCE_PROJECTION_QUERY');
const sourceHtmlHash = hash(source.html);
if (sourceHtmlHash !== 'dce2d1649f3998e00e5e2d5db97550e99c97e2f14020df24f49267a1f8560bd0') throw new Error('SOURCE_HTML_HASH_MISMATCH');
const outlook = readInput('outlook'), word = readInput('word'), ios = readInput('ios');
if (!universalComposeStateFromBody(source, outlook, 'newMail').current) throw new Error('PRIVATE_PAIR_NOT_CURRENT');
const sourceHtml = redact(source.html), actualHtml = redact(outlook);
const redacted = { schema: 1, kind: 'combined', html: sourceHtml, version, marker, header: 'u2e-' + version, sourceHash: hash(sourceHtml), media: [] };
validateUniversalComposeDocument(redacted);
if (!universalComposeStateFromBody(redacted, actualHtml, 'newMail').current) throw new Error('REDACTED_PAIR_NOT_CURRENT');
const legacyHtml = redactLegacyWord(word);
if (JSON.stringify(universalComposePresentationEvidence(legacyHtml)) !== JSON.stringify(universalComposePresentationEvidence(word))) throw new Error('LEGACY_PRESENTATION_CHANGED');
const artifacts = { 'source.json': JSON.stringify(redacted, null, 2) + '\n', 'new-outlook-2237.redacted.html': actualHtml + '\n', 'legacy-word.redacted.html': legacyHtml + '\n' };
for (const [name, content] of Object.entries(artifacts)) fs.writeFileSync(new URL(name, fixtureRoot), content);
const manifest = {
    schema: 1,
    purpose: 'Redacted structure and serialization regression; private input text, addresses, links and origin are replaced before writing.',
    method: 'generate-redacted.mjs reads immutable private exports and actual PHP-projected source in memory. It verifies the original pair current before redacting six semantic text slots, four icon links, image origins and markers. Table structure, colors, centering, geometry and media filenames remain.',
    sourceProjection: { inputSha256: '95124735d6302bc8a4e71e73f924ff626be91a16547082a43874f75f9089928d', sourceHtmlSha256: sourceHtmlHash, queryCount: wire.queryCount, originalVersionMatch: true, originalCurrent: true, boot: 'Laravel configuration hook sets isolated sqlite :memory:, array cache, null logger before kernel bootstrap; Http::preventStrayRequests and Mail::fake; actual OutlookUniversalPayloadPresentation::append.' },
    inputs: Object.fromEntries(Object.entries(inputs).map(([key, value]) => [key, { sha256: value.hash, bytes: fs.statSync(value.path).size }])),
    presentation: { outlook: universalComposePresentationEvidence(outlook), word: universalComposePresentationEvidence(word), ios: universalComposePresentationEvidence(ios) },
    legacyWord: { current: false, incompatibilities: ['marker is not the current 24-hex schema', 'no centered U2e shell', 'two material blocks with CID images', 'train 529x73 and colspan 4 instead of 500x83 and colspan 3', 'no dispatch rowspan 2', 'legal gray differs'] },
    iosDiagnosis: 'The supplied export contains no visible body content and no U2e marker. No causal diagnostic report was supplied; the default-signature cause remains unproven.',
    artifacts: Object.fromEntries(Object.entries(artifacts).map(([name, content]) => [name, { sha256: hash(content), bytes: Buffer.byteLength(content) }])),
};
fs.writeFileSync(new URL('manifest.json', fixtureRoot), JSON.stringify(manifest, null, 2) + '\n');
console.log(JSON.stringify({ generated: Object.keys(artifacts).length, originalCurrent: true, redactedCurrent: true, queryCount: wire.queryCount }));
