const MAX_TEXT_LENGTH = 4096;
const MAX_FINDINGS = 500;
const MAX_CONTROLS = 1000;
const MAX_LIST_ITEMS = 100;
const MAX_COUNTER = 2147483647;

const REPORT_STATUS_RANK = Object.freeze({ pass: 0, warn: 1, block: 2 });
const ENFORCEMENT_RANK = Object.freeze({ OFF: 0, INFO: 1, WARN: 2, BLOCK: 3 });
const SUPPORT_STATUSES = Object.freeze([
    'SUPPORTED',
    'PARTIAL',
    'UNSUPPORTED',
    'UNKNOWN',
    'NOT_APPLICABLE',
]);

const DEGRADATION_DISCLAIMER = 'Robustheitsvorschau – keine Mailclient-Emulation.';

export const MAIL_COMPATIBILITY_LEVELS = Object.freeze({
    SAFE: Object.freeze({
        id: 'SAFE',
        label: 'Mail-sicher',
        description: 'Für den statischen Grundinhalt der definierten Clientmatrix geeignet.',
        tone: 'positive',
        selectable: true,
        disabled: false,
        requiresFallback: false,
        requiresSeparatePipeline: false,
    }),
    PROGRESSIVE: Object.freeze({
        id: 'PROGRESSIVE',
        label: 'Progressiv',
        description: 'Nur als Verbesserung mit einem eigenständig funktionierenden SAFE-Fallback verwenden.',
        tone: 'caution',
        selectable: true,
        disabled: false,
        requiresFallback: true,
        requiresSeparatePipeline: false,
    }),
    UNSUPPORTED: Object.freeze({
        id: 'UNSUPPORTED',
        label: 'Nicht freigegeben',
        description: 'Im statischen RailTime-Mailmodus gesperrt oder nur über eine separate Pipeline zulässig.',
        tone: 'critical',
        selectable: false,
        disabled: true,
        requiresFallback: false,
        requiresSeparatePipeline: true,
    }),
});

export const MAIL_COMPATIBILITY_STATUSES = Object.freeze({
    pass: Object.freeze({ id: 'pass', label: 'Automatische Prüfung bestanden', tone: 'positive' }),
    warn: Object.freeze({ id: 'warn', label: 'Mit Hinweisen', tone: 'caution' }),
    block: Object.freeze({ id: 'block', label: 'Veröffentlichung gesperrt', tone: 'critical' }),
});

export const MAIL_DEGRADATION_MODES = Object.freeze({
    normal: Object.freeze({
        id: 'normal',
        label: 'Normal',
        description: 'Skriptfreie Standardansicht des aktuellen HTML-Stands.',
        clientEmulation: false,
        viewportWidth: null,
        colorScheme: null,
        disclaimer: DEGRADATION_DISCLAIMER,
    }),
    'images-off': Object.freeze({
        id: 'images-off',
        label: 'Bilder aus',
        description: 'Entfernt Bildquellen und CSS-Bild-URLs, während Alternativtexte und Layoutattribute erhalten bleiben.',
        clientEmulation: false,
        viewportWidth: null,
        colorScheme: null,
        disclaimer: DEGRADATION_DISCLAIMER,
    }),
    'head-css-off': Object.freeze({
        id: 'head-css-off',
        label: 'Head-CSS aus',
        description: 'Entfernt eingebettete und verlinkte Stylesheets, behält aber Inline-Stile.',
        clientEmulation: false,
        viewportWidth: null,
        colorScheme: null,
        disclaimer: DEGRADATION_DISCLAIMER,
    }),
    forward: Object.freeze({
        id: 'forward',
        label: 'Weiterleitung',
        description: 'Prüft das kompilierte HTML als zitierte Weiterleitung ohne Head-CSS; Inline-Stile und HTML-Attribute bleiben erhalten.',
        clientEmulation: false,
        viewportWidth: 375,
        colorScheme: null,
        disclaimer: 'Weiterleitungs-Robustheitsvorschau – keine iPhone- oder Mailclient-Emulation.',
    }),
    'forward-strict': Object.freeze({
        id: 'forward-strict',
        label: 'iPhone-Weiterleitung · Stress',
        description: 'Stressprüfung einer zitierten Weiterleitung ohne Head-CSS, negative Margins oder positionierte Ebenen; HTML-Abmessungen und Medien bleiben erhalten.',
        clientEmulation: false,
        viewportWidth: 375,
        colorScheme: null,
        disclaimer: 'iPhone-Weiterleitungs-Stressprüfung – bewusste Degradation, keine iPhone- oder Mailclient-Emulation.',
    }),
    'css-off': Object.freeze({
        id: 'css-off',
        label: 'CSS aus',
        description: 'Entfernt Stylesheets und Inline-CSS; HTML-Präsentationsattribute bleiben sichtbar prüfbar.',
        clientEmulation: false,
        viewportWidth: null,
        colorScheme: null,
        disclaimer: DEGRADATION_DISCLAIMER,
    }),
    mobile: Object.freeze({
        id: 'mobile',
        label: 'Schmale Ansicht',
        description: 'Bereitet eine 375-Pixel-Robustheitsansicht vor; der Host setzt die tatsächliche Iframe-Breite.',
        clientEmulation: false,
        viewportWidth: 375,
        colorScheme: null,
        disclaimer: DEGRADATION_DISCLAIMER,
    }),
    dark: Object.freeze({
        id: 'dark',
        label: 'Dark Mode',
        description: 'Kennzeichnet eine dunkle Robustheitsansicht ohne das proprietäre Verhalten einzelner Clients nachzuahmen.',
        clientEmulation: false,
        viewportWidth: null,
        colorScheme: 'dark',
        disclaimer: DEGRADATION_DISCLAIMER,
    }),
});

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function recordFrom(value) {
    if (isRecord(value)) return value;
    if (typeof value !== 'string' || value.trim() === '') return {};

    try {
        const parsed = JSON.parse(value);

        return isRecord(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

function ownValue(record, ...keys) {
    if (!isRecord(record)) return undefined;

    for (const key of keys) {
        if (!Object.prototype.hasOwnProperty.call(record, key)) continue;

        try {
            return record[key];
        } catch {
            return undefined;
        }
    }

    return undefined;
}

function cleanText(value, fallback = '', maximum = MAX_TEXT_LENGTH) {
    if (typeof value !== 'string' && typeof value !== 'number') return fallback;

    const text = String(value)
        .replace(/\r\n?/g, '\n')
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
        .replace(/[\u202A-\u202E\u2066-\u2069]/g, '')
        .trim();

    return text === '' ? fallback : text.slice(0, maximum);
}

function cleanIdentifier(value, fallback = '') {
    const identifier = cleanText(value, fallback, 160)
        .replace(/[^a-zA-Z0-9_.:-]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return identifier || fallback;
}

function enumValue(value, allowed, fallback) {
    const normalized = cleanText(value).toUpperCase();

    return allowed.includes(normalized) ? normalized : fallback;
}

function nonNegativeInteger(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number <= 0) return 0;

    return Math.min(MAX_COUNTER, Math.floor(number));
}

function stringList(value, maximum = MAX_LIST_ITEMS) {
    if (!Array.isArray(value)) return Object.freeze([]);

    const unique = [];
    value.slice(0, maximum).forEach((item) => {
        const normalized = cleanText(item, '', 240);
        if (normalized !== '' && !unique.includes(normalized)) unique.push(normalized);
    });

    return Object.freeze(unique);
}

function normalizeEnforcement(value, fallback = 'INFO') {
    const normalized = cleanText(value).toUpperCase();
    const aliases = {
        ERROR: 'BLOCK',
        CRITICAL: 'BLOCK',
        VIOLATION: 'BLOCK',
        WARNING: 'WARN',
        NOTICE: 'WARN',
    };
    const resolved = aliases[normalized] || normalized;

    return Object.prototype.hasOwnProperty.call(ENFORCEMENT_RANK, resolved) ? resolved : fallback;
}

function normalizeSupportStatus(value) {
    return enumValue(value, SUPPORT_STATUSES, 'UNKNOWN');
}

function normalizedControlLevel(value, supportStatus = '') {
    const requested = cleanText(value).toUpperCase();
    const hasSupportEvidence = cleanText(supportStatus) !== '';
    const support = hasSupportEvidence ? normalizeSupportStatus(supportStatus) : '';

    if (requested === 'SEPARATE_PIPELINE'
        || requested === 'UNSUPPORTED'
        || support === 'UNSUPPORTED'
        || support === 'UNKNOWN') {
        return 'UNSUPPORTED';
    }

    if (requested === 'PROGRESSIVE' || support === 'PARTIAL') return 'PROGRESSIVE';
    if (requested === 'SAFE' && ['SUPPORTED', 'NOT_APPLICABLE'].includes(support)) return 'SAFE';

    return 'UNSUPPORTED';
}

export function escapeMailCompatibilityText(value) {
    return cleanText(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

export function mailCompatibilityLevelMetadata(level, supportStatus = '') {
    return MAIL_COMPATIBILITY_LEVELS[normalizedControlLevel(level, supportStatus)];
}

function normalizeClientSupport(value) {
    if (!Array.isArray(value)) return Object.freeze([]);

    return Object.freeze(value.slice(0, MAX_LIST_ITEMS).map((item, index) => {
        const source = recordFrom(item);
        const note = cleanText(ownValue(source, 'support_note_de', 'supportNote'), '', 1000);

        return Object.freeze({
            id: cleanIdentifier(ownValue(source, 'id', 'client_profile_id', 'clientProfileId'), `profile-${index + 1}`),
            supportStatus: normalizeSupportStatus(ownValue(source, 'support_status', 'supportStatus')),
            note,
            noteHtml: escapeMailCompatibilityText(note),
        });
    }));
}

function aggregateClientSupport(clientSupport) {
    const statuses = clientSupport.map((profile) => profile.supportStatus);
    if (statuses.length === 0) return '';
    if (statuses.includes('UNSUPPORTED')) return 'UNSUPPORTED';
    if (statuses.includes('UNKNOWN')) return 'UNKNOWN';
    if (statuses.includes('PARTIAL')) return 'PARTIAL';
    if (statuses.every((status) => status === 'NOT_APPLICABLE')) return 'NOT_APPLICABLE';

    return 'SUPPORTED';
}

export function normalizeMailBuilderControl(input = {}) {
    const source = typeof input === 'string' ? { compatibility_level: input } : recordFrom(input);
    const clientSupport = normalizeClientSupport(ownValue(source, 'client_support', 'clientSupport'));
    const suppliedSupport = ownValue(source, 'support_status', 'supportStatus');
    const supportEvidence = cleanText(suppliedSupport) !== ''
        ? normalizeSupportStatus(suppliedSupport)
        : aggregateClientSupport(clientSupport);
    const supportStatus = supportEvidence || 'UNKNOWN';
    const metadata = mailCompatibilityLevelMetadata(
        ownValue(source, 'compatibility_level', 'compatibilityLevel', 'level'),
        supportEvidence,
    );
    const title = cleanText(
        ownValue(source, 'title_de', 'title', 'label'),
        metadata.label,
        240,
    );
    const description = cleanText(
        ownValue(source, 'description_de', 'description', 'rule_text_de', 'ruleText'),
        metadata.description,
        1000,
    );

    return Object.freeze({
        id: cleanIdentifier(ownValue(source, 'rule_id', 'ruleId', 'id'), metadata.id),
        title,
        titleHtml: escapeMailCompatibilityText(title),
        description,
        descriptionHtml: escapeMailCompatibilityText(description),
        level: metadata.id,
        supportStatus,
        enforcement: normalizeEnforcement(ownValue(source, 'enforcement'), 'INFO'),
        tone: metadata.tone,
        selectable: metadata.selectable,
        disabled: metadata.disabled,
        requiresFallback: metadata.requiresFallback,
        requiresSeparatePipeline: metadata.requiresSeparatePipeline,
        clientProfiles: clientSupport.length > 0
            ? Object.freeze(clientSupport.map((profile) => profile.id))
            : stringList(ownValue(source, 'client_profiles', 'clientProfiles')),
        clientSupport,
    });
}

function normalizeFinding(input, index) {
    const source = recordFrom(input);
    const message = cleanText(
        ownValue(source, 'message', 'diagnostic_message_de', 'diagnosticMessage'),
        'Kompatibilitätshinweis ohne Beschreibung.',
        2000,
    );
    const fix = cleanText(ownValue(source, 'fix', 'fix_guidance_de', 'fixGuidance'), '', 2000);
    const suppliedEnforcement = normalizeEnforcement(ownValue(source, 'enforcement', 'severity'), 'INFO');
    const suppliedSupport = ownValue(source, 'support_status', 'supportStatus');
    const hasSupportEvidence = cleanText(suppliedSupport) !== '';
    const supportStatus = hasSupportEvidence ? normalizeSupportStatus(suppliedSupport) : 'UNKNOWN';
    const suppliedLevel = ownValue(source, 'compatibility_level', 'compatibilityLevel', 'level');
    const fallbackLevel = suppliedEnforcement === 'BLOCK'
        ? 'UNSUPPORTED'
        : (suppliedEnforcement === 'WARN' ? 'PROGRESSIVE' : 'SAFE');
    const level = normalizedControlLevel(
        cleanText(suppliedLevel) === '' ? fallbackLevel : suppliedLevel,
        hasSupportEvidence ? supportStatus : '',
    );

    return Object.freeze({
        ruleId: cleanIdentifier(ownValue(source, 'rule_id', 'ruleId'), `UNSPECIFIED-${index + 1}`),
        diagnosticCode: cleanIdentifier(
            ownValue(source, 'diagnostic_code', 'diagnosticCode'),
            `MAIL_COMPATIBILITY_${index + 1}`,
        ),
        enforcement: suppliedEnforcement,
        compatibilityLevel: level,
        supportStatus,
        message,
        messageHtml: escapeMailCompatibilityText(message),
        fix,
        fixHtml: escapeMailCompatibilityText(fix),
        clientProfiles: stringList(
            ownValue(source, 'client_profiles', 'clientProfiles', 'client_profile_ids'),
        ),
    });
}

function statusFrom(source, counts) {
    const explicit = cleanText(ownValue(source, 'status')).toLowerCase();
    let status = Object.prototype.hasOwnProperty.call(REPORT_STATUS_RANK, explicit) ? explicit : 'pass';

    if (counts.warn > 0 && REPORT_STATUS_RANK[status] < REPORT_STATUS_RANK.warn) status = 'warn';
    if (counts.block > 0) status = 'block';

    return status;
}

function normalizedCoverage(source) {
    const coverage = recordFrom(ownValue(source, 'coverage'));
    const suppliedSupported = nonNegativeInteger(ownValue(coverage, 'supported'));
    const suppliedUnknown = nonNegativeInteger(ownValue(coverage, 'unknown'));
    const required = Math.max(
        nonNegativeInteger(ownValue(coverage, 'required')),
        Math.min(MAX_COUNTER, suppliedSupported + suppliedUnknown),
    );
    const supported = Math.min(required, suppliedSupported);
    const unknown = Math.min(required - supported, suppliedUnknown);
    const missing = Math.max(0, required - supported);
    const percentage = required === 0 ? 100 : Math.round((supported / required) * 10000) / 100;

    return Object.freeze({
        required,
        supported,
        unknown,
        missing,
        percentage,
        complete: missing === 0 && unknown === 0,
    });
}

export function normalizeMailCompatibilityReport(input = {}) {
    const root = recordFrom(input);
    const nested = recordFrom(ownValue(root, 'compatibility'));
    const source = Object.keys(nested).length > 0 ? nested : root;
    const rawFindings = ownValue(source, 'findings');
    const findings = Object.freeze((Array.isArray(rawFindings) ? rawFindings : [])
        .slice(0, MAX_FINDINGS)
        .map((finding, index) => normalizeFinding(finding, index)));
    const derived = findings.reduce((counts, finding) => {
        const key = finding.enforcement.toLowerCase();
        if (Object.prototype.hasOwnProperty.call(counts, key) && key !== 'off') counts[key] += 1;

        return counts;
    }, { block: 0, warn: 0, info: 0 });
    const suppliedCounts = recordFrom(ownValue(source, 'counts'));
    const counts = Object.freeze({
        block: Math.max(derived.block, nonNegativeInteger(ownValue(suppliedCounts, 'block'))),
        warn: Math.max(derived.warn, nonNegativeInteger(ownValue(suppliedCounts, 'warn'))),
        info: Math.max(derived.info, nonNegativeInteger(ownValue(suppliedCounts, 'info'))),
    });
    const messages = [];
    const suppliedMessages = ownValue(source, 'messages');
    const messageCandidates = [
        ...(Array.isArray(suppliedMessages) ? suppliedMessages : []),
        ...findings.map((finding) => finding.message),
    ];
    messageCandidates.slice(0, MAX_FINDINGS).forEach((message) => {
        const normalized = cleanText(message, '', 2000);
        if (normalized !== '' && !messages.includes(normalized)) messages.push(normalized);
    });
    const status = statusFrom(source, counts);
    const title = status === 'pass' ? MAIL_COMPATIBILITY_STATUSES.pass.label : cleanText(
        ownValue(source, 'title'),
        MAIL_COMPATIBILITY_STATUSES[status].label,
        240,
    );

    const rawChecks = ownValue(source, 'checks');
    const checks = isRecord(rawChecks)
        && Number.isFinite(ownValue(rawChecks, 'automated')) && Number.isFinite(ownValue(rawChecks, 'manual'))
        ? Object.freeze({
            automated: nonNegativeInteger(ownValue(rawChecks, 'automated')),
            manual: nonNegativeInteger(ownValue(rawChecks, 'manual')),
            manualRuleIds: stringList(ownValue(rawChecks, 'manual_rule_ids', 'manualRuleIds')),
        }) : null;

    return Object.freeze({
        checks,
        // Automated checks never establish a received-client rendering result.
        renderingVerified: false,
        catalogVersion: cleanText(
            ownValue(source, 'catalog_version', 'catalogVersion'),
            'unknown',
            80,
        ),
        status,
        statusMetadata: MAIL_COMPATIBILITY_STATUSES[status],
        title,
        titleHtml: escapeMailCompatibilityText(title),
        counts,
        coverage: normalizedCoverage(source),
        htmlBytes: nonNegativeInteger(ownValue(source, 'html_bytes', 'htmlBytes')),
        styleBytes: nonNegativeInteger(ownValue(source, 'style_bytes', 'styleBytes')),
        findings,
        messages: Object.freeze(messages),
        messagesHtml: Object.freeze(messages.map((message) => escapeMailCompatibilityText(message))),
    });
}

export function normalizeMailEditingPolicy(input = {}) {
    const source = recordFrom(input);
    const version = ownValue(source, 'policy_version', 'policyVersion');
    const expected = {
        trainSource: ['train_source', 'img-only'],
        negativeMargin: ['negative_margin', 'warn'],
        positionedLayout: ['positioned_layout', 'warn'],
        backgroundImages: ['background_images', 'warn'],
        criticalLayout: ['critical_layout', 'table-flow'],
    };
    const available = version === 1 && Object.entries(expected).every(([key, [snake, value]]) =>
        ownValue(source, snake, key) === value);
    const evidence = [];
    if (available) {
        const rawEvidence = ownValue(source, 'evidence');
        (Array.isArray(rawEvidence) ? rawEvidence : []).slice(0, 10).forEach((entry) => {
            const record = recordFrom(entry);
            const title = cleanText(ownValue(record, 'title'), '', 240);
            const url = cleanText(ownValue(record, 'url'), '', 2048);
            try {
                const parsed = new URL(url);
                if (title && ['https:', 'http:'].includes(parsed.protocol) && !parsed.username && !parsed.password) {
                    evidence.push(Object.freeze({ title, url: parsed.href }));
                }
            } catch { /* Untrusted or relative evidence is not a clickable source. */ }
        });
    }
    return Object.freeze({
        available,
        policyVersion: available ? 1 : null,
        ...Object.fromEntries(Object.entries(expected).map(([key, [, value]]) => [key, available ? value : null])),
        mailCss: available && ownValue(source, 'mail_css', 'mailCss') === 'critical-inline' ? 'critical-inline' : null,
        addinCss: available && ownValue(source, 'addin_css', 'addinCss') === 'separate-client-test' ? 'separate-client-test' : null,
        evidence: Object.freeze(evidence),
    });
}

export function normalizeMailCompatibilityManifest(input = {}) {
    const root = recordFrom(input);
    const nested = recordFrom(ownValue(root, 'manifest', 'compatibility_manifest', 'compatibilityManifest'));
    const source = Object.keys(nested).length > 0 ? nested : root;
    const rawControls = ownValue(source, 'controls', 'rules');
    const seen = new Set();
    const controls = [];

    (Array.isArray(rawControls) ? rawControls : []).slice(0, MAX_CONTROLS).forEach((control) => {
        const normalized = normalizeMailBuilderControl(control);
        if (seen.has(normalized.id)) return;
        seen.add(normalized.id);
        controls.push(normalized);
    });

    return Object.freeze({
        schemaVersion: cleanText(ownValue(source, 'schema_version', 'schemaVersion'), '1', 80),
        catalogVersion: cleanText(ownValue(source, 'catalog_version', 'catalogVersion'), 'unknown', 80),
        levels: MAIL_COMPATIBILITY_LEVELS,
        degradationModes: MAIL_DEGRADATION_MODES,
        clientProfiles: stringList(
            ownValue(source, 'client_profiles', 'clientProfiles', 'required_profiles', 'requiredProfiles'),
        ),
        controls: Object.freeze(controls),
        editingPolicy: normalizeMailEditingPolicy(ownValue(source, 'editing_policy', 'editingPolicy')),
    });
}

export function normalizeMailCompatibilityPayload(input = {}) {
    const root = recordFrom(input);

    return Object.freeze({
        manifest: normalizeMailCompatibilityManifest(root),
        compatibility: normalizeMailCompatibilityReport(root),
    });
}

export function normalizeMailDegradationMode(mode = 'normal') {
    const normalized = cleanText(mode).toLowerCase();

    return Object.prototype.hasOwnProperty.call(MAIL_DEGRADATION_MODES, normalized)
        ? normalized
        : 'normal';
}

function parserFrom(options = {}) {
    const environment = options?.environment || options || globalThis;
    const DOMParserClass = environment?.DOMParser || environment?.defaultView?.DOMParser;
    if (typeof DOMParserClass !== 'function') {
        throw new Error('Für die sichere Mail-Robustheitsvorschau ist DOMParser erforderlich.');
    }

    return new DOMParserClass();
}

function parsedMailHtml(source, options) {
    const parser = parserFrom(options);
    const fullDocument = /<!doctype\s+html\b|<html(?:\s|>)/i.test(source);

    if (fullDocument) {
        const document_ = parser.parseFromString(source, 'text/html');
        if (!document_?.documentElement || !document_?.head || !document_?.body) {
            throw new Error('Das Mail-HTML konnte nicht als vollständiges Dokument gelesen werden.');
        }

        return { document_, root: document_, fullDocument, hadDoctype: /<!doctype\s+html\b/i.test(source) };
    }

    const document_ = parser.parseFromString(
        '<!doctype html><html><head></head><body><template data-rt-mail-fragment></template></body></html>',
        'text/html',
    );
    const template = document_?.querySelector?.('template[data-rt-mail-fragment]');
    if (!template) throw new Error('Das Mail-HTML-Fragment konnte nicht isoliert gelesen werden.');
    template.innerHTML = source;

    return {
        document_,
        root: template.content || template,
        template,
        fullDocument,
        hadDoctype: false,
    };
}

function removeElements(root, selector) {
    Array.from(root?.querySelectorAll?.(selector) || []).forEach((element) => element.remove());
}

function unsafeUrl(value) {
    const compact = cleanText(value).replace(/[\u0000-\u0020\u007F-\u009F]/g, '').toLowerCase();

    return compact.startsWith('javascript:')
        || compact.startsWith('vbscript:')
        || compact.startsWith('data:text/html')
        || compact.startsWith('data:application/xhtml+xml')
        || compact.startsWith('data:image/svg+xml');
}

function unsafeCss(value) {
    const compact = String(value || '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/[\u0000-\u0020\u007F-\u009F]/g, '')
        .toLowerCase();

    return compact.includes('expression(')
        || compact.includes('javascript:')
        || compact.includes('vbscript:')
        || compact.includes('behavior:')
        || compact.includes('-moz-binding:');
}

function makeScriptFree(root) {
    removeElements(root, 'script, iframe, object, embed, base, template');
    Array.from(root?.querySelectorAll?.('meta[http-equiv]') || []).forEach((meta) => {
        if (cleanText(meta.getAttribute?.('http-equiv')).toLowerCase() === 'refresh') meta.remove();
    });

    Array.from(root?.querySelectorAll?.('*') || []).forEach((element) => {
        Array.from(element.attributes || []).forEach((attribute) => {
            const name = String(attribute.name || '').toLowerCase();
            if (name.startsWith('on') || name === 'srcdoc') {
                element.removeAttribute(attribute.name);
                return;
            }

            if (['href', 'src', 'action', 'formaction', 'poster', 'background', 'xlink:href'].includes(name)
                && unsafeUrl(attribute.value)) {
                element.removeAttribute(attribute.name);
            }
        });

        const inlineStyle = element.getAttribute?.('style');
        if (inlineStyle !== null && unsafeCss(inlineStyle)) element.removeAttribute('style');
    });

    Array.from(root?.querySelectorAll?.('style') || []).forEach((style) => {
        if (unsafeCss(style.textContent)) style.remove();
    });
}

function withoutCssUrls(css) {
    const withoutUrls = String(css || '').replace(
        /url\(\s*(?:"(?:\\.|[^"])*"|'(?:\\.|[^'])*'|[^)])*\)/gi,
        'none',
    );

    return /url\s*\(/i.test(withoutUrls) ? '' : withoutUrls;
}

function disableImages(root) {
    Array.from(root?.querySelectorAll?.('*') || []).forEach((element) => {
        ['src', 'srcset', 'sizes', 'poster', 'background'].forEach((attribute) => {
            element.removeAttribute?.(attribute);
        });

        const tagName = String(element.tagName || '').toLowerCase();
        if (tagName === 'image' || tagName.endsWith(':fill') || tagName.endsWith(':imagedata')) {
            element.removeAttribute?.('href');
            element.removeAttribute?.('xlink:href');
        }

        const inlineStyle = element.getAttribute?.('style');
        if (inlineStyle !== null && /url\s*\(/i.test(inlineStyle)) {
            const sanitized = withoutCssUrls(inlineStyle);
            if (sanitized === '') element.removeAttribute('style');
            else element.setAttribute('style', sanitized);
        }
    });

    Array.from(root?.querySelectorAll?.('style') || []).forEach((style) => {
        if (!/url\s*\(/i.test(style.textContent || '')) return;
        const sanitized = withoutCssUrls(style.textContent);
        if (sanitized === '') style.remove();
        else style.textContent = sanitized;
    });
}

function removeEmbeddedCss(root) {
    removeElements(root, 'style');
    Array.from(root?.querySelectorAll?.('link') || []).forEach((link) => {
        const rel = cleanText(link.getAttribute?.('rel')).toLowerCase().split(/\s+/);
        const as = cleanText(link.getAttribute?.('as')).toLowerCase();
        if (rel.includes('stylesheet') || (rel.includes('preload') && as === 'style')) link.remove();
    });
}

function removeAllCss(root) {
    removeEmbeddedCss(root);
    Array.from(root?.querySelectorAll?.('[style]') || []).forEach((element) => {
        element.removeAttribute('style');
    });
}

function splitInlineCssDeclarations(style) {
    const declarations = [];
    let current = '';
    let quote = '';
    let depth = 0;
    let escaped = false;

    for (const character of String(style || '')) {
        if (escaped) {
            current += character;
            escaped = false;
            continue;
        }
        if (character === '\\' && quote) {
            current += character;
            escaped = true;
            continue;
        }
        if (quote) {
            current += character;
            if (character === quote) quote = '';
            continue;
        }
        if (character === '"' || character === "'") {
            quote = character;
            current += character;
            continue;
        }
        if (character === '(') depth += 1;
        if (character === ')' && depth > 0) depth -= 1;
        if (character === ';' && depth === 0) {
            if (current.trim()) declarations.push(current.trim());
            current = '';
            continue;
        }
        current += character;
    }

    if (current.trim()) declarations.push(current.trim());

    return declarations;
}

function hasNegativeCssNumber(value) {
    return /-\s*(?:\d|\.\d)/.test(
        String(value || '').replace(/\/\*[\s\S]*?\*\//g, ''),
    );
}

function neutralizeStrictForwardingStyles(root) {
    const positionalProperties = new Set([
        'position',
        'z-index',
        'top',
        'right',
        'bottom',
        'left',
        'inset',
        'inset-block',
        'inset-block-start',
        'inset-block-end',
        'inset-inline',
        'inset-inline-start',
        'inset-inline-end',
    ]);

    Array.from(root?.querySelectorAll?.('[style]') || []).forEach((element) => {
        const declarations = splitInlineCssDeclarations(element.getAttribute?.('style'));
        const retained = declarations.filter((declaration) => {
            const separator = declaration.indexOf(':');
            if (separator <= 0) return false;

            const property = declaration.slice(0, separator).trim().toLowerCase();
            const value = declaration.slice(separator + 1).trim();
            if (positionalProperties.has(property)) return false;
            if ((property === 'margin' || property.startsWith('margin-'))
                && hasNegativeCssNumber(value)) {
                return false;
            }

            return true;
        });

        if (retained.length === 0) {
            element.removeAttribute('style');
            return;
        }
        element.setAttribute('style', `${retained.join(';')};`);
    });
}

function prepareForwarding(parsed, { strict = false } = {}) {
    removeEmbeddedCss(parsed.root);
    if (strict) neutralizeStrictForwardingStyles(parsed.root);
    if (!parsed.fullDocument || !parsed.document_?.body) return;

    const body = parsed.document_.body;
    const quote = parsed.document_.createElement('blockquote');
    quote.setAttribute('type', 'cite');
    quote.setAttribute('data-rt-mail-forwarded-content', '');
    if (strict) quote.setAttribute('data-rt-mail-forward-stress', '');
    quote.setAttribute(
        'style',
        'margin:0 0 0 8px;padding:0 0 0 8px;border:0;border-left:1px solid #d1d5db;',
    );

    while (body.firstChild) quote.appendChild(body.firstChild);
    body.appendChild(quote);
}

function ensureMeta(document_, name, content) {
    const escapedName = String(name).replace(/"/g, '\\"');
    let meta = document_.head?.querySelector?.(`meta[name="${escapedName}"]`) || null;
    if (!meta) {
        meta = document_.createElement('meta');
        meta.setAttribute('name', name);
        document_.head?.appendChild(meta);
    }
    meta.setAttribute('content', content);
}

function prepareMobile(document_) {
    let viewport = document_.head?.querySelector?.('meta[name="viewport"]') || null;
    if (!viewport) {
        viewport = document_.createElement('meta');
        viewport.setAttribute('name', 'viewport');
        document_.head?.appendChild(viewport);
    }
    viewport.setAttribute('content', 'width=device-width, initial-scale=1');
}

function serializeParsed(parsed) {
    if (!parsed.fullDocument) {
        const serializer = parsed.document_.createElement('div');
        Array.from(parsed.root?.childNodes || []).forEach((node) => {
            serializer.appendChild(node.cloneNode(true));
        });

        return String(serializer.innerHTML || '');
    }

    const documentHtml = String(parsed.document_.documentElement?.outerHTML || '');

    return `${parsed.hadDoctype ? '<!doctype html>' : ''}${documentHtml}`;
}

export function transformMailHtmlForDegradation(html, mode = 'normal', options = {}) {
    if (typeof html !== 'string') {
        throw new TypeError('Die Mail-Robustheitsvorschau erwartet HTML als Zeichenkette.');
    }

    const normalizedMode = normalizeMailDegradationMode(mode);
    const parsed = parsedMailHtml(html, options);
    makeScriptFree(parsed.root);

    if (normalizedMode === 'images-off') disableImages(parsed.root);
    if (normalizedMode === 'head-css-off') removeEmbeddedCss(parsed.root);
    if (normalizedMode === 'forward') prepareForwarding(parsed);
    if (normalizedMode === 'forward-strict') prepareForwarding(parsed, { strict: true });
    if (normalizedMode === 'css-off') removeAllCss(parsed.root);

    if (parsed.fullDocument) {
        parsed.document_.documentElement.setAttribute('data-rt-mail-degradation-mode', normalizedMode);
        parsed.document_.documentElement.setAttribute('data-rt-mail-client-emulation', 'false');
        if (normalizedMode === 'mobile') prepareMobile(parsed.document_);
        if (normalizedMode === 'dark') {
            ensureMeta(parsed.document_, 'color-scheme', 'dark light');
            ensureMeta(parsed.document_, 'supported-color-schemes', 'dark light');
            parsed.document_.documentElement.setAttribute('data-rt-mail-preview-color-scheme', 'dark');
        }
    }

    return serializeParsed(parsed);
}

export function createMailDegradationPreview(html, mode = 'normal', options = {}) {
    const normalizedMode = normalizeMailDegradationMode(mode);
    const metadata = MAIL_DEGRADATION_MODES[normalizedMode];

    return Object.freeze({
        ...metadata,
        html: transformMailHtmlForDegradation(html, normalizedMode, options),
    });
}
