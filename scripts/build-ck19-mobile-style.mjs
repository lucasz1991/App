import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import postcss from 'postcss';

export const CK19_APPROVED_V10_SHA256 = 'df52bd20719eeae8c73c411e797b1cdb9f8b4d8f8022662ef9694bf0e129b114';
export const CK19_APPROVED_V10_CHARACTERS = 4980;
export const CK19_APPROVED_V11_SHA256 = '532dab7e0ebf5734736d49f427bb61f49c9cec9e9aad09165a0bbc8248e63f5a';
export const CK19_APPROVED_V11_CHARACTERS = 4999;
export const CK19_V11_SOURCE_FILE_SHA256 = 'ab4d5c8bbaf9adabb1128810cba672df11b12bdf6a474d4b2d4845059eebaeda';
export const CK19_GENERATED_DIRECTORY = 'resources/mail-templates/generated';
const mappingPath = 'resources/mail-templates/ck19-mobile-style-v11.mapping.json';
const payloadName = 'ck19-mobile-style-v11.html';
const manifestName = 'ck19-mobile-style-v11.manifest.json';
const projectRoot = fileURLToPath(new URL('..', import.meta.url));
const sha256 = value => createHash('sha256').update(value).digest('hex');
const normalize = value => String(value).replaceAll('\r\n', '\n');
const fail = code => { throw new Error('CK19_' + code); };
const releases = Object.freeze({
    'ck19-approved-v10-20261105': Object.freeze({ tag: 'v10', sha256: CK19_APPROVED_V10_SHA256,
        characters: CK19_APPROVED_V10_CHARACTERS, suffix: '</style>\n' }),
    'ck19-approved-v11-20261105': Object.freeze({ tag: 'v11', sha256: CK19_APPROVED_V11_SHA256,
        characters: CK19_APPROVED_V11_CHARACTERS, suffix: '</style>' }),
});

/** The source-file EOF is explicitly not part of the approved v11 rule text.
 * No generic trim, CSS minification, or removal of other whitespace is allowed. */
export function ck19V11RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 5000 || Buffer.byteLength(source) !== 5000
        || sha256(source) !== CK19_V11_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V11_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V11_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V11_SHA256) fail('V11_RULE_TEXT_INVALID');
    return ruleText;
}

/** Pure generation: no application boot, mailbox, network, rule or mail action. */
export function generateCk19MobileStyle({ templateHtml, presentationPhp, mapping }) {
    const template = normalize(templateHtml);
    const styles = [...template.matchAll(/<style type="text\/css">([\s\S]*?)<\/style>/g)];
    const release = mapping && Object.hasOwn(releases, mapping.revision) ? releases[mapping.revision] : null;
    if (styles.length !== 1 || !mapping || mapping.schema !== 1
        || !release || mapping.approvedPayloadSha256 !== release.sha256
        || mapping.approvedPayloadCharacters !== release.characters
        || mapping.outputMedia !== 'only screen and (max-width:1039px)'
        || mapping.payloadPrefix !== '<style type="text/css">'
        || mapping.payloadSuffix !== release.suffix
        || mapping.policy?.maximumCharactersExclusive !== 5000
        || !Array.isArray(mapping.rules) || mapping.rules.length !== 42) fail('MAPPING_INVALID');
    if (release.tag === 'v11' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 5000
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V11_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V11_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V11_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-explicitly-approved-EOF-is-not-rule-text')) fail('V11_NORMALIZATION_CONTRACT_INVALID');
    const css = styles[0][1];
    if (sha256(css) !== mapping.sourceCssSha256) fail('SOURCE_CSS_CHANGED_REVIEW_MAPPING');
    const pins = [...String(presentationPhp).matchAll(/private const TEMPLATE_SHA256\s*=\s*'([0-9a-f]{64})';/g)];
    const templateHash = sha256(template);
    if (mapping.approvedTemplateSha256 !== templateHash) fail('SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING');
    if (pins.length !== 1 || pins[0][1] !== templateHash) fail('SOURCE_TEMPLATE_PIN_MISMATCH');

    const tree = postcss.parse(css);
    let derivedDeclarations = 0;
    let transportOverrides = 0;
    const rendered = mapping.rules.map(rule => {
        if (typeof rule.selector !== 'string' || !rule.selector.trim()
            || /[{}<]|\[class\s*\$\s*=|:not\s*\(/i.test(rule.selector)
            || !Array.isArray(rule.sourceSelectors) || !Array.isArray(rule.declarations)
            || !rule.declarations.length || typeof rule.reason !== 'string' || !rule.reason) fail('RULE_INVALID');
        const declarations = rule.declarations.map(declaration => {
            if (!/^[a-z-]+$/.test(declaration.property) || typeof declaration.important !== 'boolean') fail('DECLARATION_INVALID');
            let value;
            if (declaration.from && !Object.hasOwn(declaration, 'override')) {
                const from = declaration.from;
                if (from.property !== declaration.property || !rule.sourceSelectors.some(reference =>
                    reference.selector === from.selector && reference.media === from.media)) fail('SOURCE_REFERENCE_INVALID');
                const found = [];
                tree.walkRules(sourceRule => {
                    if (sourceRule.parent.type === 'atrule' && sourceRule.parent.name === 'media'
                        && sourceRule.parent.params === from.media && sourceRule.selector === from.selector) {
                        sourceRule.walkDecls(from.property, sourceDeclaration => found.push(sourceDeclaration.value));
                    }
                });
                if (found.length !== 1) fail('SOURCE_REFERENCE_AMBIGUOUS');
                value = found[0];
                derivedDeclarations++;
            } else if (!declaration.from && typeof declaration.override === 'string'
                && typeof declaration.reason === 'string' && declaration.reason) {
                // These are individually reviewed transport compatibility
                // declarations, not claims that the source CSS contained them.
                value = declaration.override;
                transportOverrides++;
            } else fail('DECLARATION_SOURCE_INVALID');
            if (!value || /[;{}<>\r\n]/.test(value)) fail('DECLARATION_VALUE_INVALID');
            return declaration.property + ':' + value + (declaration.important ? '!important' : '');
        });
        return rule.selector + '{' + declarations.join(';') + '}';
    }).join('');
    const payload = mapping.payloadPrefix + '@media ' + mapping.outputMedia + '{' + rendered + '}' + mapping.payloadSuffix;
    // Exchange's limit is strict and independent of the approved digest. Never
    // truncate, broaden selectors or silently minify a different candidate.
    if (payload.length >= 5000 || Buffer.byteLength(payload) !== payload.length) fail('PAYLOAD_LENGTH');
    if (payload.length !== release.characters || sha256(payload) !== release.sha256) fail('APPROVED_' + release.tag.toUpperCase() + '_DRIFT');
    const generatedCss = payload.slice(mapping.payloadPrefix.length, -mapping.payloadSuffix.length);
    const output = postcss.parse(generatedCss);
    if (output.nodes.length !== 1 || output.nodes[0].type !== 'atrule'
        || output.nodes[0].name !== 'media' || output.nodes[0].params !== mapping.outputMedia) fail('GENERATED_CSS_INVALID');
    return Object.freeze({ payload, templateHash, cssHash: sha256(css), derivedDeclarations, transportOverrides,
        revision: mapping.revision, release: release.tag, sourceFileNormalization: mapping.sourceFileNormalization || null });
}

/** Stable provenance: no timestamp or private input is placed in the build. */
export function ck19Manifest({ generated, mappingBytes, generatorBytes }) {
    return {
        schema: 1,
        kind: 'ck19-approved-' + generated.release + '-generated-mobile-style',
        revision: generated.revision,
        sourceTemplate: 'resources/mail-templates/u2e-universal-v1.html',
        sourceTemplateSha256: generated.templateHash,
        sourceCssSha256: generated.cssHash,
        templateConstant: 'OutlookUniversalPresentation::TEMPLATE_SHA256',
        mapping: 'resources/mail-templates/ck19-mobile-style-' + generated.release + '.mapping.json',
        mappingSha256: sha256(normalize(mappingBytes)),
        generator: 'scripts/build-ck19-mobile-style.mjs',
        generatorSha256: sha256(normalize(generatorBytes)),
        payload: 'ck19-mobile-style-' + generated.release + '.html',
        payloadSha256: sha256(generated.payload),
        payloadCharacters: generated.payload.length,
        maximumCharactersExclusive: 5000,
        media: 'only screen and (max-width:1039px)',
        rules: 42,
        derivedDeclarations: generated.derivedDeclarations,
        explicitTransportOverrides: generated.transportOverrides,
        nativeClientProof: false,
        exchangeRuleUpdatedByBuild: false,
        sourceFileNormalization: generated.sourceFileNormalization,
        scope: 'Approved v10/v11 global selector scope is unchanged; unrelated-content compatibility is not asserted.',
        historicalCtaGuard: 'Separate colspan=5 line cell only; old single action row remains visible.',
    };
}

export async function buildCk19MobileStyle({ check = false, root = projectRoot } = {}) {
    const [templateHtml, presentationPhp, mappingBytes, generatorBytes] = await Promise.all([
        readFile(path.join(root, 'resources/mail-templates/u2e-universal-v1.html'), 'utf8'),
        readFile(path.join(root, 'app/Support/OutlookAddin/OutlookUniversalPresentation.php'), 'utf8'),
        readFile(path.join(root, mappingPath), 'utf8'),
        readFile(fileURLToPath(import.meta.url), 'utf8'),
    ]);
    const generated = generateCk19MobileStyle({ templateHtml, presentationPhp, mapping: JSON.parse(mappingBytes) });
    const manifest = ck19Manifest({ generated, mappingBytes, generatorBytes });
    const manifestBytes = JSON.stringify(manifest, null, 2) + '\n';
    const directory = path.join(root, CK19_GENERATED_DIRECTORY);
    if (check) {
        const [storedPayload, storedManifest] = await Promise.all([
            readFile(path.join(directory, payloadName), 'utf8'), readFile(path.join(directory, manifestName), 'utf8'),
        ]);
        if (storedPayload !== generated.payload || normalize(storedManifest) !== manifestBytes) fail('GENERATED_FILES_STALE');
    } else {
        await mkdir(directory, { recursive: true });
        await writeFile(path.join(directory, payloadName), generated.payload);
        await writeFile(path.join(directory, manifestName), manifestBytes);
    }
    return manifest;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    if (process.argv.slice(2).some(argument => argument !== '--check')) fail('ARGUMENT_INVALID');
    const manifest = await buildCk19MobileStyle({ check: process.argv.includes('--check') });
    console.log(`CK19 v11 ${process.argv.includes('--check') ? 'verified' : 'generated'}: ${manifest.payloadCharacters} characters, sha256:${manifest.payloadSha256}, template:${manifest.sourceTemplateSha256}`);
}
