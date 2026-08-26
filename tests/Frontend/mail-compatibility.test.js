import assert from 'node:assert/strict';
import test from 'node:test';
import { DOMParser } from 'linkedom';

import {
    createMailDegradationPreview,
    escapeMailCompatibilityText,
    MAIL_COMPATIBILITY_LEVELS,
    MAIL_DEGRADATION_MODES,
    mailCompatibilityLevelMetadata,
    normalizeMailBuilderControl,
    normalizeMailCompatibilityManifest,
    normalizeMailCompatibilityPayload,
    normalizeMailCompatibilityReport,
    normalizeMailDegradationMode,
    transformMailHtmlForDegradation,
} from '../../resources/js/mail-compatibility.js';

const environment = { DOMParser };

const mailDocument = `<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="refresh" content="0; url=javascript:steal()">
    <style>.hero { color: red; background-image: url('https://assets.test/hero.png'); }</style>
    <link rel="stylesheet" href="https://assets.test/mail.css">
    <script>globalThis.compromised = true;</script>
</head>
<body style="margin: 0; background-image: url(https://assets.test/body.png)" onload="steal()">
    <table role="presentation" width="600" bgcolor="#ffffff" style="width: 600px">
        <tr><td>
            <img src="https://assets.test/logo.png" srcset="https://assets.test/logo@2x.png 2x" width="120" height="40" alt="RailTime Logo">
            <a href="javascript:steal()" onclick="steal()">Unsicher</a>
            <a href="https://rail-time.de">RailTime</a>
        </td></tr>
    </table>
</body>
</html>`;

test('compatibility payload normalizes untrusted server JSON without mutating it', () => {
    const payload = {
        manifest: {
            schema_version: '1.0',
            catalog_version: '2026.08',
            client_profiles: ['gmail-web', 'gmail-web', 'outlook-classic'],
            rules: [{
                rule_id: 'EMAIL-LAYOUT-001',
                title_de: '<b>Tabellenlayout</b>',
                compatibility_level: 'SAFE',
                support_status: 'SUPPORTED',
                enforcement: 'WARN',
            }],
        },
        compatibility: {
            catalog_version: '2026.08',
            status: 'pass',
            counts: { block: -4, warn: 0, info: '2' },
            coverage: { required: 3, supported: 2, unknown: 1 },
            html_bytes: 8192,
            style_bytes: 1024,
            findings: [{
                rule_id: 'EMAIL-SCRIPT-001',
                diagnostic_code: 'EMAIL_SCRIPT_001',
                enforcement: 'BLOCK',
                compatibility_level: 'SEPARATE_PIPELINE',
                support_status: 'UNSUPPORTED',
                message: '<img src=x onerror=steal()>\u202E',
                fix: 'Script entfernen',
                client_profiles: ['all', 'all'],
            }],
        },
    };
    const snapshot = structuredClone(payload);
    const normalized = normalizeMailCompatibilityPayload(JSON.stringify(payload));

    assert.deepEqual(payload, snapshot);
    assert.equal(normalized.manifest.schemaVersion, '1.0');
    assert.equal(normalized.manifest.catalogVersion, '2026.08');
    assert.deepEqual(normalized.manifest.clientProfiles, ['gmail-web', 'outlook-classic']);
    assert.equal(normalized.manifest.controls[0].level, 'SAFE');
    assert.equal(normalized.manifest.controls[0].titleHtml, '&lt;b&gt;Tabellenlayout&lt;/b&gt;');
    assert.equal(normalized.compatibility.status, 'block');
    assert.deepEqual(normalized.compatibility.counts, { block: 1, warn: 0, info: 2 });
    assert.deepEqual(normalized.compatibility.coverage, {
        required: 3,
        supported: 2,
        unknown: 1,
        missing: 1,
        percentage: 66.67,
        complete: false,
    });
    assert.equal(normalized.compatibility.findings[0].message, '<img src=x onerror=steal()>');
    assert.equal(
        normalized.compatibility.findings[0].messageHtml,
        '&lt;img src=x onerror=steal()&gt;',
    );
    assert.equal(Object.isFrozen(normalized.compatibility.findings), true);
    assert.equal(Object.isFrozen(normalized.manifest.controls[0]), true);
});

test('report model bounds invalid counters and derives a conservative status', () => {
    const report = normalizeMailCompatibilityReport({
        status: 'not-a-status',
        counts: { block: Number.POSITIVE_INFINITY, warn: 0, info: -1 },
        coverage: { required: 1, supported: 7, unknown: 9 },
        findings: [
            { severity: 'warning', message: 'Fallback prüfen' },
            null,
        ],
    });

    assert.equal(report.status, 'warn');
    assert.deepEqual(report.counts, { block: 0, warn: 1, info: 1 });
    assert.deepEqual(report.coverage, {
        required: 16,
        supported: 7,
        unknown: 9,
        missing: 9,
        percentage: 43.75,
        complete: false,
    });
    assert.deepEqual(report.messages, ['Fallback prüfen', 'Kompatibilitätshinweis ohne Beschreibung.']);
});

test('manifest deduplicates controls and fails unknown support closed', () => {
    const manifest = normalizeMailCompatibilityManifest({
        catalogVersion: '1.0.0',
        rules: [
            { ruleId: 'A', compatibilityLevel: 'SAFE', supportStatus: 'SUPPORTED' },
            { ruleId: 'A', compatibilityLevel: 'PROGRESSIVE', supportStatus: 'PARTIAL' },
            { ruleId: 'B', compatibilityLevel: 'SAFE', supportStatus: 'UNKNOWN' },
        ],
    });

    assert.equal(manifest.controls.length, 2);
    assert.equal(manifest.controls[0].level, 'SAFE');
    assert.equal(manifest.controls[1].level, 'UNSUPPORTED');
    assert.equal(manifest.controls[1].disabled, true);
});

test('manifest consumes the grouped client_support shape emitted by the PHP catalog', () => {
    const manifest = normalizeMailCompatibilityManifest({
        schema_version: '1.0.0',
        catalog_version: '1.0.0',
        rules: [{
            rule_id: 'EMAIL-RESPONSIVE-001',
            title_de: 'Responsiver Umbruch',
            compatibility_level: 'SAFE',
            enforcement: 'WARN',
            client_support: [
                { id: 'gmail-web', support_status: 'SUPPORTED', support_note_de: 'Geprüft' },
                { id: 'outlook-classic-windows', support_status: 'PARTIAL', support_note_de: 'Fallback nötig' },
            ],
        }],
    });

    const control = manifest.controls[0];
    assert.equal(control.level, 'PROGRESSIVE');
    assert.equal(control.supportStatus, 'PARTIAL');
    assert.equal(control.requiresFallback, true);
    assert.deepEqual(control.clientProfiles, ['gmail-web', 'outlook-classic-windows']);
    assert.equal(control.clientSupport[1].noteHtml, 'Fallback nötig');
});

test('builder metadata distinguishes SAFE, PROGRESSIVE and UNSUPPORTED controls', () => {
    assert.equal(mailCompatibilityLevelMetadata('SAFE', 'SUPPORTED'), MAIL_COMPATIBILITY_LEVELS.SAFE);
    assert.equal(mailCompatibilityLevelMetadata('SAFE', 'PARTIAL'), MAIL_COMPATIBILITY_LEVELS.PROGRESSIVE);
    assert.equal(mailCompatibilityLevelMetadata('SAFE', 'UNKNOWN'), MAIL_COMPATIBILITY_LEVELS.UNSUPPORTED);
    assert.equal(mailCompatibilityLevelMetadata('SEPARATE_PIPELINE', 'SUPPORTED'), MAIL_COMPATIBILITY_LEVELS.UNSUPPORTED);

    const progressive = normalizeMailBuilderControl({
        rule_id: 'EMAIL-DARK-001',
        title_de: 'Dark Mode',
        compatibility_level: 'PROGRESSIVE',
        support_status: 'PARTIAL',
        client_profiles: ['apple-mail', 'outlook-web'],
    });
    assert.equal(progressive.selectable, true);
    assert.equal(progressive.requiresFallback, true);
    assert.equal(progressive.requiresSeparatePipeline, false);
    assert.deepEqual(progressive.clientProfiles, ['apple-mail', 'outlook-web']);
});

test('plain and HTML-safe render strings are explicit and deterministic', () => {
    assert.equal(
        escapeMailCompatibilityText(' <strong title="x">A & B</strong> '),
        '&lt;strong title=&quot;x&quot;&gt;A &amp; B&lt;/strong&gt;',
    );
    assert.equal(normalizeMailDegradationMode('DARK'), 'dark');
    assert.equal(normalizeMailDegradationMode('FORWARD'), 'forward');
    assert.equal(normalizeMailDegradationMode('FORWARD-STRICT'), 'forward-strict');
    assert.equal(normalizeMailDegradationMode('outlook-2016'), 'normal');
});

test('normal degradation is deterministic, script-free and keeps safe links', () => {
    const first = transformMailHtmlForDegradation(mailDocument, 'normal', { environment });
    const second = transformMailHtmlForDegradation(mailDocument, 'normal', { environment });

    assert.equal(first, second);
    assert.doesNotMatch(first, /<script\b/i);
    assert.doesNotMatch(first, /\sonload=|\sonclick=/i);
    assert.doesNotMatch(first, /href="javascript:/i);
    assert.doesNotMatch(first, /http-equiv="refresh"/i);
    assert.match(first, /http-equiv="Content-Type"/i);
    assert.match(first, /href="https:\/\/rail-time\.de"/);
    assert.match(first, /data-rt-mail-degradation-mode="normal"/);
    assert.match(first, /data-rt-mail-client-emulation="false"/);
});

test('images-off removes every image source and CSS URL but preserves fallback information', () => {
    const snapshot = mailDocument;
    const degraded = transformMailHtmlForDegradation(mailDocument, 'images-off', { environment });

    assert.equal(mailDocument, snapshot);
    assert.doesNotMatch(degraded, /\ssrc=|\ssrcset=|url\s*\(/i);
    assert.match(degraded, /alt="RailTime Logo"/);
    assert.match(degraded, /width="120"/);
    assert.match(degraded, /height="40"/);
    assert.match(degraded, /href="https:\/\/rail-time\.de"/);
});

test('head-css-off keeps inline styles while css-off preserves only HTML presentation attributes', () => {
    const headCssOff = transformMailHtmlForDegradation(mailDocument, 'head-css-off', { environment });
    const cssOff = transformMailHtmlForDegradation(mailDocument, 'css-off', { environment });

    assert.doesNotMatch(headCssOff, /<style\b|rel="stylesheet"/i);
    assert.match(headCssOff, /style="width: 600px"/);

    assert.doesNotMatch(cssOff, /<style\b|rel="stylesheet"|\sstyle=/i);
    assert.match(cssOff, /width="600"/);
    assert.match(cssOff, /bgcolor="#ffffff"/);
    assert.match(cssOff, /role="presentation"/);
});

test('forward mode uses quoted compiled HTML without Head CSS and keeps the inline SAFE basis', () => {
    const preview = createMailDegradationPreview(mailDocument, 'forward', { environment });

    assert.equal(preview.clientEmulation, false);
    assert.equal(preview.viewportWidth, 375);
    assert.match(preview.disclaimer, /keine iPhone- oder Mailclient-Emulation/);
    assert.doesNotMatch(preview.html, /<style\b|rel="stylesheet"/i);
    assert.match(preview.html, /style="width: 600px"/);
    assert.match(preview.html, /<blockquote(?=[^>]*type="cite")(?=[^>]*data-rt-mail-forwarded-content)[^>]*>/);
    assert.equal((preview.html.match(/RailTime Logo/g) || []).length, 1);
    assert.equal((preview.html.match(/href="https:\/\/rail-time\.de"/g) || []).length, 1);
    assert.equal(mailDocument.includes('<style>.hero'), true, 'the source HTML remains unchanged');
});

test('strict iPhone forwarding stress removes fragile overlap styles but keeps media and HTML dimensions', () => {
    const strictDocument = mailDocument.replace(
        '<table role="presentation" width="600" bgcolor="#ffffff" style="width: 600px">',
        '<table role="presentation" width="600" height="200" bgcolor="#ffffff" style="position:relative;z-index:1;width:600px;height:200px;margin:0 auto;margin-bottom:-200px">',
    ).replace(
        'width="120" height="40" alt="RailTime Logo"',
        'width="120" height="40" alt="RailTime Logo" style="position:absolute;right:0;bottom:0;z-index:2;width:120px;height:auto;margin:0 0 -4px 0"',
    );
    const preview = createMailDegradationPreview(strictDocument, 'forward-strict', { environment });

    assert.equal(preview.clientEmulation, false);
    assert.equal(preview.viewportWidth, 375);
    assert.equal(preview.label, 'iPhone-Weiterleitung · Stress');
    assert.match(preview.disclaimer, /Stressprüfung/);
    assert.match(preview.disclaimer, /keine iPhone- oder Mailclient-Emulation/);
    assert.doesNotMatch(preview.html, /<style\b|rel="stylesheet"/i);
    assert.doesNotMatch(preview.html, /(?:^|[;"\s])(?:position|z-index|top|right|bottom|left|inset(?:-[a-z-]+)?)\s*:/i);
    assert.doesNotMatch(preview.html, /margin(?:-[a-z-]+)?\s*:[^;"']*-\s*(?:\d|\.\d)/i);
    assert.match(preview.html, /<blockquote(?=[^>]*type="cite")(?=[^>]*data-rt-mail-forwarded-content)(?=[^>]*data-rt-mail-forward-stress)[^>]*>/);
    assert.match(preview.html, /<table(?=[^>]*width="600")(?=[^>]*height="200")[^>]*style="width:600px;height:200px;margin:0 auto;"/);
    assert.match(preview.html, /<img(?=[^>]*src="https:\/\/assets\.test\/logo\.png")(?=[^>]*srcset="https:\/\/assets\.test\/logo@2x\.png 2x")(?=[^>]*width="120")(?=[^>]*height="40")[^>]*style="width:120px;height:auto;"/);
    assert.equal(strictDocument.includes('margin-bottom:-200px'), true, 'the source HTML remains unchanged');
});

test('mobile and dark modes expose honest host metadata instead of client emulation claims', () => {
    const mobile = createMailDegradationPreview(mailDocument, 'mobile', { environment });
    const dark = createMailDegradationPreview(mailDocument, 'dark', { environment });

    assert.equal(mobile.clientEmulation, false);
    assert.equal(mobile.viewportWidth, 375);
    assert.match(mobile.disclaimer, /keine Mailclient-Emulation/);
    const mobileDocument = new DOMParser().parseFromString(mobile.html, 'text/html');
    assert.equal(
        mobileDocument.querySelector('meta[name="viewport"]')?.getAttribute('content'),
        'width=device-width, initial-scale=1',
    );

    assert.equal(dark.clientEmulation, false);
    assert.equal(dark.colorScheme, 'dark');
    assert.match(dark.disclaimer, /keine Mailclient-Emulation/);
    const darkDocument = new DOMParser().parseFromString(dark.html, 'text/html');
    assert.equal(darkDocument.querySelector('meta[name="color-scheme"]')?.getAttribute('content'), 'dark light');
    assert.match(dark.html, /data-rt-mail-preview-color-scheme="dark"/);
    assert.equal(MAIL_DEGRADATION_MODES.dark.label, 'Dark Mode');
});

test('fragment degradation preserves table-row shape and never adds a wrapper', () => {
    const fragment = '<tr style="color:red"><td><img src="logo.png" alt="Logo"></td></tr>';
    const degraded = transformMailHtmlForDegradation(fragment, 'css-off', { environment });
    const forwarded = transformMailHtmlForDegradation(fragment, 'forward', { environment });
    const strictForwarded = transformMailHtmlForDegradation(
        '<tr style="position:relative;margin-bottom:-20px;color:red"><td><img src="logo.png" width="120" height="40" alt="Logo"></td></tr>',
        'forward-strict',
        { environment },
    );

    assert.equal(degraded, '<tr><td><img src="logo.png" alt="Logo"></td></tr>');
    assert.equal(forwarded, fragment);
    assert.doesNotMatch(forwarded, /blockquote/i);
    assert.equal(strictForwarded, '<tr style="color:red;"><td><img src="logo.png" width="120" height="40" alt="Logo"></td></tr>');
    assert.doesNotMatch(strictForwarded, /blockquote/i);
});

test('degradation rejects non-string HTML and a missing DOM parser', () => {
    assert.throws(
        () => transformMailHtmlForDegradation({}, 'normal', { environment }),
        /HTML als Zeichenkette/,
    );
    assert.throws(
        () => transformMailHtmlForDegradation('<p>Mail</p>', 'normal', { environment: {} }),
        /DOMParser erforderlich/,
    );
});
