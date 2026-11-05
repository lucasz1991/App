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
export const CK19_APPROVED_V12_SHA256 = '12b7b1ea14005334f19716ac459fa45de2dd96e29ff43f4ce383bdd034d6fc92';
export const CK19_APPROVED_V12_CHARACTERS = 4262;
export const CK19_V12_SOURCE_FILE_SHA256 = '0860693acf1b7a78dd379a1381b70332b0d54d1a6e9dc5b960baa9bad7b0a72c';
export const CK19_APPROVED_V13_SHA256 = 'ae784b17a110b6578362b4f567c76d7b1389705a8bf36bbcd6758f168fd8b2c5';
export const CK19_APPROVED_V13_CHARACTERS = 3801;
export const CK19_V13_SOURCE_FILE_SHA256 = 'f203fd9db56e30485bc3ef2d348a538847bb6f7d159dd3bb93e8dfdcae5619de';
export const CK19_APPROVED_V14_SHA256 = '796c465c64703cfd8804a96797c41364cbf1dfcdee7874b2ae379dc18c07d2d1';
export const CK19_APPROVED_V14_CHARACTERS = 4950;
export const CK19_V14_SOURCE_FILE_SHA256 = 'f3aaa167d1f059a6f3fde1226dab0724507b96b0a021490564b02267ed08c227';
export const CK19_APPROVED_V15_SHA256 = 'f5e928a2f517887f7379646bada05223fe7efbb26880be228d368e08774e2595';
export const CK19_APPROVED_V15_CHARACTERS = 3803;
export const CK19_V15_SOURCE_FILE_SHA256 = '80ede788b578b6efa40c92ddeaef096eb94c53966711d63b8442705779fe0687';
export const CK19_APPROVED_V16_SHA256 = 'c5532f4ac2805996973d7c001fbcadaf34ab988b752f4a23bd368635b6801b62';
export const CK19_APPROVED_V16_CHARACTERS = 4948;
export const CK19_V16_SOURCE_FILE_SHA256 = '600e526b11d27f4a44f672d64c7c1d7fd78aa3ee087c51dfb6143a029c0a6e5e';
export const CK19_APPROVED_V17_SHA256 = '36bfdadfced47555ea12e092902652db5efa6f91a04b7cd704f80cf82a145d24';
export const CK19_APPROVED_V17_CHARACTERS = 3801;
export const CK19_V17_SOURCE_FILE_SHA256 = '00dc74efd5d4c6e628eb16569449de8a57738647c4293187809c45993a90aacf';
export const CK19_GENERATED_DIRECTORY = 'resources/mail-templates/generated';
const projectRoot = fileURLToPath(new URL('..', import.meta.url));
const sha256 = value => createHash('sha256').update(value).digest('hex');
const normalize = value => String(value).replaceAll('\r\n', '\n');
const fail = code => { throw new Error('CK19_' + code); };
// C-K25 preserves approved transport bytes, while the removed line rule is no
// longer claimed as a derivation from the current v2.5 CSS.
const ck26TemplateHash = 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf';
const historicalLineSource = Object.freeze({
    "approval": "C-K25-user-approved-retain-existing-v11-v15-payloads",
    "templateSha256": "5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204",
    "cssSha256": "502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1",
    "media": "only screen and (max-width:600px)",
    "selector": ".rt-cta-line",
    "property": "display",
    "value": "none"
});
const historicalLineCounts = Object.freeze({ v11: 3, v12: 1, v13: 1, v14: 3, v15: 1, v16: 3, v17: 1 });
const historicalPaddingCounts = Object.freeze({ v11: 3, v12: 3, v13: 3, v14: 2, v15: 2, v16: 0, v17: 0 });
const historicalPaddingSources = Object.freeze([
    {
        "approval": "C-K26-user-approved-retain-existing-v11-v15-payloads",
        "templateSha256": "5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204",
        "cssSha256": "502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1",
        "media": "only screen and (max-width:600px)",
        "selector": ".rt-dispo-head",
        "property": "padding",
        "value": "9px 0 9px 20px"
    },
    {
        "approval": "C-K26-user-approved-retain-existing-v11-v15-payloads",
        "templateSha256": "5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204",
        "cssSha256": "502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1",
        "media": "only screen and (max-width:600px)",
        "selector": ".rt-dispo-actions",
        "property": "padding",
        "value": "4px 20px 4px 0"
    },
    {
        "approval": "C-K26-user-approved-retain-existing-v11-v15-payloads",
        "templateSha256": "5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204",
        "cssSha256": "502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1",
        "media": "only screen and (max-width:600px)",
        "selector": ".rt-cta-cell",
        "property": "padding",
        "value": "5px 0 5px 12px"
    }
]);
const releases = Object.freeze({
    'ck19-approved-v10-20261105': Object.freeze({ tag: 'v10', sha256: CK19_APPROVED_V10_SHA256,
        characters: CK19_APPROVED_V10_CHARACTERS, suffix: '</style>\n', rules: 42 }),
    'ck19-approved-v11-20261105': Object.freeze({ tag: 'v11', sha256: CK19_APPROVED_V11_SHA256,
        characters: CK19_APPROVED_V11_CHARACTERS, suffix: '</style>', rules: 42 }),
    'ck19-approved-v12-20261105': Object.freeze({ tag: 'v12', sha256: CK19_APPROVED_V12_SHA256,
        characters: CK19_APPROVED_V12_CHARACTERS, suffix: '</style>', rules: 36 }),
    'ck19-approved-v13-20261105': Object.freeze({ tag: 'v13', sha256: CK19_APPROVED_V13_SHA256,
        characters: CK19_APPROVED_V13_CHARACTERS, suffix: '</style>', rules: 34 }),
    'ck19-approved-v14-20261105': Object.freeze({ tag: 'v14', sha256: CK19_APPROVED_V14_SHA256,
        characters: CK19_APPROVED_V14_CHARACTERS, suffix: '</style>', rules: 42 }),
    'ck19-approved-v15-20261105': Object.freeze({ tag: 'v15', sha256: CK19_APPROVED_V15_SHA256,
        characters: CK19_APPROVED_V15_CHARACTERS, suffix: '</style>', rules: 34 }),
    'ck19-approved-v16-20261106': Object.freeze({ tag: 'v16', sha256: CK19_APPROVED_V16_SHA256,
        characters: CK19_APPROVED_V16_CHARACTERS, suffix: '</style>', rules: 42 }),
    'ck19-approved-v17-20261106': Object.freeze({ tag: 'v17', sha256: CK19_APPROVED_V17_SHA256,
        characters: CK19_APPROVED_V17_CHARACTERS, suffix: '</style>', rules: 34 }),
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

/** v12's handoff counts the rule text without its single file-ending LF. */
export function ck19V12RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 4263 || Buffer.byteLength(source) !== 4263
        || sha256(source) !== CK19_V12_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V12_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V12_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V12_SHA256) fail('V12_RULE_TEXT_INVALID');
    return ruleText;
}

/** v13 replaces only the mobile-send rule text; preserve its approved bytes. */
export function ck19V13RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 3802 || Buffer.byteLength(source) !== 3802
        || sha256(source) !== CK19_V13_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V13_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V13_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V13_SHA256) fail('V13_RULE_TEXT_INVALID');
    return ruleText;
}

export function ck19V14RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 4951 || Buffer.byteLength(source) !== 4951
        || sha256(source) !== CK19_V14_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V14_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V14_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V14_SHA256) fail('V14_RULE_TEXT_INVALID');
    return ruleText;
}

export function ck19V15RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 3804 || Buffer.byteLength(source) !== 3804
        || sha256(source) !== CK19_V15_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V15_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V15_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V15_SHA256) fail('V15_RULE_TEXT_INVALID');
    return ruleText;
}

export function ck19V16RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 4949 || Buffer.byteLength(source) !== 4949
        || sha256(source) !== CK19_V16_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V16_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V16_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V16_SHA256) fail('V16_RULE_TEXT_INVALID');
    return ruleText;
}

export function ck19V17RuleTextFromSourceFile(source) {
    if (typeof source !== 'string' || source.length !== 3802 || Buffer.byteLength(source) !== 3802
        || sha256(source) !== CK19_V17_SOURCE_FILE_SHA256 || !source.endsWith('\n') || source.endsWith('\n\n')) fail('V17_SOURCE_FILE_INVALID');
    const ruleText = source.slice(0, -1);
    if (ruleText.length !== CK19_APPROVED_V17_CHARACTERS || sha256(ruleText) !== CK19_APPROVED_V17_SHA256) fail('V17_RULE_TEXT_INVALID');
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
        || !Array.isArray(mapping.rules) || mapping.rules.length !== release.rules) fail('MAPPING_INVALID');
    if (release.tag === 'v11' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 5000
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V11_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V11_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V11_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-explicitly-approved-EOF-is-not-rule-text')) fail('V11_NORMALIZATION_CONTRACT_INVALID');
    if (release.tag === 'v12' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 4263
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V12_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V12_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V12_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-approved-rule-text-count-excludes-terminal-LF')) fail('V12_NORMALIZATION_CONTRACT_INVALID');
    if (release.tag === 'v13' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 3802
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V13_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V13_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V13_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-approved-rule-text-count-excludes-terminal-LF')) fail('V13_NORMALIZATION_CONTRACT_INVALID');
    if (release.tag === 'v14' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 4951
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V14_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V14_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V14_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-approved-rule-text-count-excludes-terminal-LF')) fail('V14_NORMALIZATION_CONTRACT_INVALID');
    if (release.tag === 'v15' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 3804
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V15_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V15_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V15_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-approved-rule-text-count-excludes-terminal-LF')) fail('V15_NORMALIZATION_CONTRACT_INVALID');
    if (release.tag === 'v16' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 4949
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V16_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V16_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V16_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-approved-rule-text-count-excludes-terminal-LF')) fail('V16_NORMALIZATION_CONTRACT_INVALID');
    if (release.tag === 'v17' && (mapping.sourceFileNormalization?.operation !== 'omit-exactly-one-terminal-LF'
        || mapping.sourceFileNormalization.sourceFileCharacters !== 3802
        || mapping.sourceFileNormalization.sourceFileSha256 !== CK19_V17_SOURCE_FILE_SHA256
        || mapping.sourceFileNormalization.ruleTextCharacters !== CK19_APPROVED_V17_CHARACTERS
        || mapping.sourceFileNormalization.ruleTextSha256 !== CK19_APPROVED_V17_SHA256
        || mapping.sourceFileNormalization.approval !== 'user-approved-rule-text-count-excludes-terminal-LF')) fail('V17_NORMALIZATION_CONTRACT_INVALID');
    const css = styles[0][1];
    if (sha256(css) !== mapping.sourceCssSha256) fail('SOURCE_CSS_CHANGED_REVIEW_MAPPING');
    const pins = [...String(presentationPhp).matchAll(/private const TEMPLATE_SHA256\s*=\s*'([0-9a-f]{64})';/g)];
    const templateHash = sha256(template);
    if (mapping.approvedTemplateSha256 !== templateHash) fail('SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING');
    if (pins.length !== 1 || pins[0][1] !== templateHash) fail('SOURCE_TEMPLATE_PIN_MISMATCH');

    const tree = postcss.parse(css);
    let derivedDeclarations = 0;
    let transportOverrides = 0;
    let historicalLineOverrides = 0;
    let historicalPaddingOverrides = 0;
    const usedHistoricalPaddingSources = [];
    const rendered = mapping.rules.map(rule => {
        if (typeof rule.selector !== 'string' || !rule.selector.trim()
            || /[{}<]|\[class\s*\$\s*=|:not\s*\(/i.test(rule.selector)
            || !Array.isArray(rule.sourceSelectors) || !Array.isArray(rule.declarations)
            || !rule.declarations.length || typeof rule.reason !== 'string' || !rule.reason) fail('RULE_INVALID');
        const declarations = rule.declarations.map(declaration => {
            if (!/^[a-z-]+$/.test(declaration.property) || typeof declaration.important !== 'boolean') fail('DECLARATION_INVALID');
            if (Object.hasOwn(declaration, 'historicalSource')) {
                if (templateHash !== ck26TemplateHash || declaration.from || declaration.important !== true) fail('HISTORICAL_OVERRIDE_PROVENANCE_INVALID');
                if (declaration.property === 'display' && declaration.override === 'none'
                    && JSON.stringify(declaration.historicalSource) === JSON.stringify(historicalLineSource)) historicalLineOverrides++;
                else if (declaration.property === 'padding' && historicalPaddingSources.some(source => declaration.override === source.value
                    && JSON.stringify(declaration.historicalSource) === JSON.stringify(source))) {
                    historicalPaddingOverrides++;
                    if (!usedHistoricalPaddingSources.some(source => source.selector === declaration.historicalSource.selector)) usedHistoricalPaddingSources.push(declaration.historicalSource);
                }
                else fail('HISTORICAL_OVERRIDE_PROVENANCE_INVALID');
            }
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
    if (templateHash === ck26TemplateHash && (historicalLineOverrides !== historicalLineCounts[release.tag]
        || historicalPaddingOverrides !== historicalPaddingCounts[release.tag])) fail('HISTORICAL_OVERRIDE_PROVENANCE_INVALID');
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
        historicalLineOverrides, historicalPaddingOverrides, historicalPaddingSources: usedHistoricalPaddingSources, revision: mapping.revision, release: release.tag, rules: release.rules, sourceFileNormalization: mapping.sourceFileNormalization || null });
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
        rules: generated.rules,
        derivedDeclarations: generated.derivedDeclarations,
        explicitTransportOverrides: generated.transportOverrides,
        historicalPaddingOverrides: generated.historicalPaddingOverrides,
        historicalPaddingSources: generated.historicalPaddingSources,
        historicalLineOverrides: generated.historicalLineOverrides,
        historicalLineSource: generated.historicalLineOverrides ? historicalLineSource : null,
        nativeClientProof: false,
        exchangeRuleUpdatedByBuild: false,
        sourceFileNormalization: generated.sourceFileNormalization,
        scope: ['v14', 'v16'].includes(generated.release)
            ? 'Approved v14/v16 global attribute-selector scope follows v11 and additionally targets Word head paragraphs; unrelated-content compatibility is not asserted.'
            : ['v15', 'v17'].includes(generated.release)
            ? 'Approved v15/v17 class/tag scope follows v13 and includes selectors outside table.rt-shell; unrelated-content compatibility is not asserted.'
            : generated.release === 'v13'
            ? 'Approved v13 class/tag scope includes selectors outside table.rt-shell; unrelated-content compatibility is not asserted.'
            : generated.release === 'v12'
            ? 'Approved v12 class/structure scope includes unrooted td.rt-col-* and table.rt-dispo selectors; unrelated-content compatibility is not asserted.'
            : 'Approved v10/v11 global selector scope is unchanged; unrelated-content compatibility is not asserted.',
        historicalCtaGuard: ['v13', 'v15', 'v17'].includes(generated.release)
            ? 'Only rt-dispo-cap, rt-cta-line and rt-cta-gap descendants of table.rt-dispo are hidden by the cap/line/gap guard; CTA cells are not hidden.'
            : generated.release === 'v12'
            ? 'Only colspan=5 or rt-cta-line descendants of table.rt-shell are hidden; an old single action row is not selected by that guard.'
            : 'Separate colspan=5 line cell only; old single action row remains visible.',
    };
}

export async function buildCk19MobileStyle({ check = false, root = projectRoot, release = 'v16' } = {}) {
    if (!['v11', 'v12', 'v13', 'v14', 'v15', 'v16', 'v17'].includes(release)) fail('BUILD_RELEASE_INVALID');
    const mappingPath = 'resources/mail-templates/ck19-mobile-style-' + release + '.mapping.json';
    const payloadName = 'ck19-mobile-style-' + release + '.html';
    const manifestName = 'ck19-mobile-style-' + release + '.manifest.json';
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

/** The active CLI/default set is v16/v17; explicit historical release checks remain available. */
export async function buildCk19MobileStyles({ releases: requested = ['v16', 'v17'], ...options } = {}) {
    if (!Array.isArray(requested) || requested.length === 0 || new Set(requested).size !== requested.length
        || requested.some(release => !['v11', 'v12', 'v13', 'v14', 'v15', 'v16', 'v17'].includes(release))) fail('BUILD_RELEASE_INVALID');
    const manifests = [];
    for (const release of requested) manifests.push(await buildCk19MobileStyle({ ...options, release }));
    return manifests;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    if (process.argv.slice(2).some(argument => argument !== '--check')) fail('ARGUMENT_INVALID');
    const manifests = await buildCk19MobileStyles({ check: process.argv.includes('--check'), releases: ['v16', 'v17'] });
    for (const manifest of manifests) console.log(`CK19 ${manifest.revision.split('-')[2]} ${process.argv.includes('--check') ? 'verified' : 'generated'}: ${manifest.payloadCharacters} characters, sha256:${manifest.payloadSha256}, template:${manifest.sourceTemplateSha256}`);
}
