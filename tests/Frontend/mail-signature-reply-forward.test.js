import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = async (relativePath) => readFile(new URL(relativePath, import.meta.url), 'utf8');
const occurrences = (value, pattern) => (value.match(pattern) || []).length;

test('reply and forward markup has one company contact DOM and one responsive wordmark', async () => {
    const [signature, contacts] = await Promise.all([
        source('../../resources/views/emails/parts/signature.blade.php'),
        source('../../resources/views/emails/parts/company-contact-table.blade.php'),
    ]);

    assert.equal(
        occurrences(signature, /@include\('emails\.parts\.company-contact-table'/g),
        1,
        'the company contact partial must be rendered exactly once',
    );
    assert.doesNotMatch(signature, /rt-firma-(?:breit|schmal)/);

    assert.equal(
        occurrences(contacts, /<table class="rt-contact rt-company-contact"/g),
        1,
        'all company rows must share one presentation table',
    );
    assert.doesNotMatch(contacts, /\$gruppen|@foreach\(\$gruppen/);
    assert.equal(occurrences(contacts, /RT_\{\{ \$zeile\['marker'\] \}\}_START/g), 1);
    assert.equal(occurrences(contacts, /RT_\{\{ \$zeile\['marker'\] \}\}_END/g), 1);
    for (const marker of ['COMPANY_PHONE', 'COMPANY_EMAIL', 'WEBSITE']) {
        assert.match(contacts, new RegExp(`'marker' => '${marker}'`));
    }

    const iconCell = contacts.indexOf('class="rt-contact-icon rt-company-contact-icon"');
    const textCell = contacts.indexOf('class="rt-contact-text rt-company-contact-text"');
    assert.ok(iconCell >= 0 && textCell > iconCell, 'icon-left/text-right order must not depend on CSS reordering');
    assert.match(signature, /<div align="right" style="text-align:right;">\s*@include\('emails\.parts\.company-contact-table'/);
    assert.match(contacts, /margin-left:\{\{ \$rechtsPositioniert \? 'auto' : '0' \}\}/);
    assert.doesNotMatch(contacts, /<table[^>]*\salign=/);
    assert.match(contacts, /align="left" class="rt-contact-text rt-company-contact-text"/);

    assert.doesNotMatch(signature, /rt-marke-mobil|class="rt-only-narrow/);
    assert.equal(
        occurrences(signature, /<img class="rt-logo" src="\{\{ \$values\['LOGO_SRC'\] \}\}"/g),
        1,
        'the animated wordmark must have one responsive DOM instance',
    );
    assert.equal(
        occurrences(signature, /<!--\[if mso\]><img class="rt-logo"/g),
        1,
        'Classic Outlook keeps one conditional still-image fallback',
    );
    const logo = signature.indexOf('class="rt-sign-logo"');
    const identity = signature.indexOf('class="rt-sign-identity"');
    const company = signature.indexOf('class="rt-sign-company"');
    assert.ok(logo >= 0 && identity > logo && company > identity, 'mobile source order is logo, person, company');
    assert.match(signature, /<table class="rt-sign-layout" role="presentation" width="100%"[^>]*style="width:100%;table-layout:fixed;/);
    assert.match(signature, /class="rt-sign-logo" colspan="2" width="100%"/);
    assert.match(signature, /<tr class="rt-stack rt-sign-top-row">[\s\S]*?class="rt-sign-identity"[\s\S]*?class="rt-sign-company"/);
    assert.doesNotMatch(signature, /<table class="rt-sign-layout"[^>]*\bdir="rtl"/);
    assert.doesNotMatch(signature, /\browspan=/);
    assert.doesNotMatch(signature, /rt-sign-company-row/);
});

test('mobile rules restyle the same signature nodes without hide-and-show copies', async () => {
    const css = await source('../../resources/views/emails/parts/responsive-css.blade.php');
    const mobile = css.slice(css.indexOf('@media only screen and (max-width: 860px)'));

    assert.match(mobile, /tr\.rt-stack > td\s*\{[\s\S]*?display:\s*block !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /\.rt-sign-logo\s*\{[\s\S]*?padding:\s*0 0 14px !important;[\s\S]*?border-bottom:\s*1px solid \{\{ \$border \}\} !important;[\s\S]*?text-align:\s*left !important;/);
    assert.match(mobile, /\.rt-sign-identity\s*\{\s*padding:\s*14px 0 0 !important;/);
    assert.match(mobile, /\.rt-sign-company\s*\{[\s\S]*?padding:\s*12px 0 0 !important;[\s\S]*?border-left:\s*0 !important;/);
    assert.match(mobile, /img\.rt-logo\s*\{[\s\S]*?margin-left:\s*0 !important;/);
    assert.match(mobile, /\.rt-company-contact\s*\{[\s\S]*?float:\s*none !important;[\s\S]*?display:\s*table !important;[\s\S]*?width:\s*100% !important;/);
    assert.match(mobile, /\.rt-company-contact td\.rt-company-contact-text\s*\{\s*text-align:\s*left !important;/);
    assert.doesNotMatch(css, /\.rt-firma-(?:breit|schmal)/);
    assert.doesNotMatch(css, /rt-marke-mobil|\.rt-sign-logo img\.rt-logo\s*\{[^}]*display:\s*none/);
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[^}]*width:\s*100% !important;[^}]*max-width:\s*1815px !important;/s);
    assert.doesNotMatch(css, /\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0 !important;/s);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width:\s*150% !important;\s*max-width:\s*none !important;\s*margin-left:\s*0 !important;/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]*?width:\s*150% !important;\s*max-width:\s*none !important;\s*margin-left:\s*-25% !important;/);
    const phone = css.slice(css.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width:\s*175% !important;[\s\S]*?margin-left:\s*-8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-content-frame,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v10"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][\s\S]*?width:\s*175% !important;[\s\S]*?margin-left:\s*-40% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v10"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*270px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v13"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*190px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v14"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*175px !important;/s);
    assert.match(css, /tr\[data-rt-signature-density="compact"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-signature-density="compact"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*145px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso\s*\{[^}]*width:\s*94% !important;[^}]*margin-left:\s*0 !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*max-height:\s*none !important;[^}]*min-height:\s*175px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\s*\{[^}]*position:\s*relative !important;[^}]*z-index:\s*0 !important;[^}]*height:\s*175px !important;[^}]*margin-bottom:\s*-175px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-content-frame\s*\{[^}]*position:\s*relative !important;[^}]*z-index:\s*1 !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v14"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*296px !important;/s);
    assert.match(mobile, /tr\[data-rt-signature-density="compact"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-signature-density="compact"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*215px !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*296px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*215px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-artifact-version="v14"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*264px !important;/s);
    assert.match(phone, /tr\[data-rt-signature-density="compact"\] \.rt-sign-train-frame,[\s\S]*?tr\[data-rt-signature-density="compact"\] \.rt-sign-content-frame\s*\{[^}]*height:\s*190px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*264px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-stage\s*\{[^}]*height:\s*auto !important;[^}]*min-height:\s*190px !important;[^}]*overflow:\s*visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width:\s*108\.67% !important;[^}]*margin-left:\s*0 !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width:\s*135% !important;[^}]*margin-left:\s*-15\.75% !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width:\s*175% !important;[^}]*margin-left:\s*-40% !important;/s);
    assert.match(mobile, /data-rt-layer-mobile="right"\]\[data-rt-layer-size\][\s\S]*?width:\s*200% !important;\s*max-width:\s*none !important;\s*margin-left:\s*-100% !important;/);
});

test('delivery keeps the fixed train pixel frame and its MSO fallback inside the bottom slot', async () => {
    const [signature, runtime, carrier, preview] = await Promise.all([
        source('../../resources/views/emails/parts/signature.blade.php'),
        source('../../app/Support/MailSignature.php'),
        source('../../app/Support/Mail/SignatureTrainCarrier.php'),
        source('../../app/Services/PageBuilder/PageBuilderPreviewService.php'),
    ]);
    const msoFallback = carrier.slice(
        carrier.indexOf('public static function withMsoFallback'),
        carrier.indexOf('public static function withIdleOverlay'),
    );

    assert.match(signature, /\$trainSrc = \$outlookTrainSrc !== ''/);
    assert.match(signature, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(signature, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="center" data-rt-layer-size="125" data-rt-layer-mobile="train" style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;[^"\r\n]*overflow:hidden;[^"\r\n]*text-align:left;">/);
    assert.match(signature, /<table class="rt-sign-train-frame" role="presentation" width="100%" height="200"/);
    assert.match(signature, /<td class="rt-sign-train-slot" height="200" valign="bottom"/);
    assert.match(signature, /<table class="rt-sign-content-frame" role="presentation" width="100%" height="200"/);
    assert.match(signature, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:125%;max-width:none;height:auto;margin:0 0 0 -12\.5%;[^\"]*vertical-align:bottom;[^\"]*mso-hide:all;">/);
    assert.doesNotMatch(signature, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.doesNotMatch(signature, /data-rt-outlook-train/);
    assert.doesNotMatch(signature, /<td[^>]+background="\{\{[^}]*TRAIN/);

    assert.doesNotMatch(runtime, /data-rt-train-main-(?:image|layer)/);
    assert.doesNotMatch(runtime, /projectPublishedTrainAsRuntimeImage/);
    assert.match(runtime, /usesTokenizedTrainCarrier/);
    assert.match(runtime, /SignatureArtifactVersion::usesOptimizedMailAssets\(\$artifactVersion\)/);
    assert.match(runtime, /\$values\['TRAIN_IDLE_SRC'\] = '';/);
    assert.match(runtime, /SignatureTrainCarrier::projectAsImage\(/);
    assert.doesNotMatch(runtime, /SignatureTrainCarrier::projectAsRuntimeBackground\(/);
    assert.equal(occurrences(runtime, /SignatureTrainCarrier::withMsoFallback\(/g), 1);
    assert.match(runtime, /outlookTrainFallbackSrc[\s\S]*?TRAIN_STILL_SRC/);
    assert.doesNotMatch(runtime, /appendClassicOutlookTrainFallback/);
    assert.match(carrier, /public static function projectAsImage/);
    assert.doesNotMatch(carrier, /public static function projectAsRuntimeBackground/);
    assert.match(carrier, /public static function withMsoFallback/);
    assert.match(carrier, /public static function withIdleOverlay/);
    assert.equal(occurrences(carrier, /<!--\[if mso\]><tr><td class="rt-sign-train-mso"/g), 0);
    assert.match(carrier, /<!--\[if mso\]><img class="rt-sign-train-mso"/);
    assert.match(msoFallback, /\$fallbackStyle = \(\$aspectSafeTrain \|\| \$forwardSafeTrain\)[\s\S]*?display:inline-block;[\s\S]*?vertical-align:bottom;/);
    assert.match(msoFallback, /self::assertRuntimeImages\(\$html, expectedMsoSource: \$source\);[\s\S]*?return \$html;/);
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(carrier, /\$layerPosition = \$failOpenStage \? 'position:relative;z-index:0;' : '';/);
    assert.match(carrier, /<div class="rt-sign-train-layer" data-rt-layer-train[^>]*style="'\.\$layerPosition\.'display:block;[^"\r\n]*height:200px;max-height:200px;[^"\r\n]*margin-bottom:-200px;/);
    assert.match(carrier, /\$imageHeight = \(\$forwardSafeTrain \|\| \(\$failOpenStage && ! \$aspectSafeTrain\)\) \? ' height="61"' : '';/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"'\.\$imageHeight\.' alt=""/);
    assert.match(carrier, /canonicalStageStartMarkup\(bool \$failOpenStage\)[\s\S]*?height:auto;min-height:200px;overflow:visible;/);
    assert.match(carrier, /canonicalContentFrameStartMarkup\(bool \$failOpenStage = false\)[\s\S]*?position:relative;z-index:1;/);
    assert.match(msoFallback, /substr_replace\(\$html, \$fallback, \$slots\[0\]\['endOffset'\] \+ 1, 0\)/);
    assert.match(msoFallback, /\$fallbackHeight = self::usesFailOpenStage\(\$html\) \? ' height="61"' : '';/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"[^>]*style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;/);
    assert.match(preview, /MailSignature::forCompany\(/);
    assert.match(preview, /->renderDocument\(/);
    assert.doesNotMatch(preview, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(runtime, /SignatureTrainCarrier::withIdleOverlay\(/);
    assert.match(carrier, /data-rt-train-idle-overlay/);
    assert.match(carrier, /<span class="rt-train-idle-overlay" data-rt-train-idle-overlay[\s\S]*?<img class="rt-train-idle-image" data-rt-train-idle-image src="'\.\$escapedSource\.'"[\s\S]*?<\/span>/);
    assert.match(carrier, /data-rt-train-idle-overlay[^>]*position:absolute;[^>]*display:block;[^>]*height:0;max-height:0;[^>]*overflow:hidden;/);
    assert.match(carrier, /data-rt-train-idle-overlay[^>]*top:auto;bottom:0;[^>]*max-width:none;[^>]*text-align:left/);
    assert.match(carrier, /rt-train-idle-image[^>]*position:absolute;[^>]*bottom:0;[^>]*display:inline-block;[^>]*vertical-align:bottom;/);
    assert.doesNotMatch(carrier, /background-image:[^;]*(?:TRAIN|train|\.gif)/);
    assert.doesNotMatch(runtime, /rt-classic-outlook-train/);
});

test('outlook taskpane normalizes multiple templates and keeps the single-template fallback', async () => {
    const previousOffice = globalThis.Office;
    globalThis.Office = { onReady() {} };

    try {
        const taskpane = await import('../../resources/js/outlook-addin/taskpane.js');
        const templates = taskpane.normalizeTemplateChoices({
            templates: [
                {
                    id: 'slot-standard',
                    key: 'slot-standard',
                    name: 'Standardvorlage',
                    label: 'Standardvorlage',
                    active: true,
                    html: '<p>Standard</p>',
                    media: [],
                    version: '0123456789abcdef',
                    hash: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                },
                {
                    id: 'slot-offer',
                    key: 'slot-offer',
                    name: 'Angebot',
                    label: 'Angebot',
                    active: false,
                    html: '<p>Angebot</p>',
                    media: [],
                    version: 'fedcba9876543210',
                    hash: 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210',
                },
            ],
            template: { html: '<p>Legacy</p>', media: [] },
            version: {
                personal: '1111111111111111',
                signature: '2222222222222222',
                template: '3333333333333333',
            },
        });

        assert.deepEqual(
            templates.map(({ id, name, active, version }) => ({ id, name, active, version })),
            [
                {
                    id: 'slot-standard',
                    name: 'Standardvorlage',
                    active: true,
                    version: '0123456789abcdef',
                },
                {
                    id: 'slot-offer',
                    name: 'Angebot',
                    active: false,
                    version: 'fedcba9876543210',
                },
            ],
        );
        assert.equal(taskpane.hasCurrentSnapshot({
            version: { personal: '1111111111111111', signature: '2222222222222222' },
        }), true);
        assert.equal(taskpane.hasCurrentSnapshot({
            version: { signature: '2222222222222222' },
        }), false);

        const fallback = taskpane.normalizeTemplateChoices({
            template: { html: '<p>Legacy</p>', media: [] },
            version: { template: '3333333333333333' },
        });
        assert.equal(fallback.length, 1);
        assert.equal(fallback[0].id, 'active-template');
        assert.equal(fallback[0].name, 'Standardvorlage');
        assert.equal(fallback[0].active, true);
        assert.equal(fallback[0].version, '3333333333333333');
        assert.deepEqual(
            taskpane.signatureVersionsFromBody(
                '<!-- RT-SIGNATURE-VERSION:2222222222222222 -->'
                + '<span>RT-SIGNATURE-VERSION:ABCDEF0123456789</span>',
            ),
            ['2222222222222222', 'abcdef0123456789'],
        );
        assert.equal(
            taskpane.currentComposeBodyHtml(
                '<p>Neu</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
                'newMail',
            ),
            '<p>Neu</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
        );
        assert.equal(
            taskpane.currentComposeBodyHtml(
                '<p>Antwort</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->'
                + '<div id="divRplyFwdMsg">Von:</div>'
                + '<!-- RT-SIGNATURE-VERSION:aaaaaaaaaaaaaaaa -->',
                'reply',
            ),
            '<p>Antwort</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
        );
        assert.equal(
            taskpane.currentComposeBodyHtml(
                '<p>Antwort ohne eindeutigen Trenner</p><!-- RT-SIGNATURE-VERSION:2222222222222222 -->',
                'reply',
            ),
            null,
        );
    } finally {
        if (previousOffice === undefined) {
            delete globalThis.Office;
        } else {
            globalThis.Office = previousOffice;
        }
    }
});

test('outlook taskpane keeps templates visible and hides resolved maintenance actions accessibly', async () => {
    const [view, taskpane] = await Promise.all([
        source('../../resources/views/outlook-addin/taskpane.blade.php'),
        source('../../resources/js/outlook-addin/taskpane.js'),
    ]);

    assert.match(view, /\[hidden\]\s*\{\s*display:\s*none !important;/);
    assert.match(view, /<label class="field-label" for="outlook-template-select">Vorlage auswählen<\/label>/);
    assert.match(view, /<select[\s\S]*?id="outlook-template-select"[\s\S]*?data-outlook-template-select/);
    assert.match(view, /data-outlook-template-name/);
    assert.match(view, /data-outlook-template-version/);
    assert.match(view, /data-outlook-maintenance-actions/);
    assert.match(view, /aria-live="polite"/);
    assert.match(view, /:focus-visible/);
    assert.match(view, /@media \(max-width: 340px\)/);

    assert.match(taskpane, /view\.login\.hidden = authenticatedBootstrap;/);
    assert.match(taskpane, /view\.signature\.hidden = authenticatedBootstrap\s*&& taskpaneState\.itemChangedMonitoringReady\s*&& taskpaneState\.signatureCurrent;/);
    assert.match(taskpane, /view\.maintenanceActions\.hidden = view\.login\.hidden && view\.signature\.hidden;/);
    assert.match(taskpane, /item\.body\.getAsync\(Office\.CoercionType\.Html/);
    assert.match(taskpane, /item\.getComposeTypeAsync/);
    assert.match(taskpane, /RT-SIGNATURE-VERSION:\(\[0-9a-f\]\{16\}\)/);
    assert.match(taskpane, /const currentBodyHtml = currentComposeBodyHtml\(bodyHtml, composeType\);[\s\S]*?return signatureVersionsFromBody\(currentBodyHtml\)\.includes\(expectedVersion\);/);
    assert.match(taskpane, /currentBootstrap = payload;[\s\S]*?await refreshSignatureCurrentState\(\);/);
    assert.match(taskpane, /window\.addEventListener\('focus', requestSilentBootstrapRefresh\);/);
    assert.match(taskpane, /document\.addEventListener\('visibilitychange',/);
    assert.match(taskpane, /Date\.now\(\) - lastBootstrapRefreshAt < SILENT_BOOTSTRAP_REFRESH_INTERVAL_MS/);
    assert.match(taskpane, /failOpenSignatureCurrentState\(\);\s*silentBootstrapRefreshPromise = \(async \(\) =>/);
    assert.match(taskpane, /const bootstrap = await loadBootstrap\(currentConfig, accessToken\);[\s\S]*?await acceptBootstrap\(bootstrap\);/);
    assert.match(taskpane, /const expectedRevision = bootstrapStateRevision;[\s\S]*?expectedRevision !== bootstrapStateRevision[\s\S]*?await acceptBootstrap\(bootstrap\);[\s\S]*?catch \(error\) \{\s*if \(expectedRevision !== bootstrapStateRevision \|\| taskpaneState\.busy\) \{\s*return;/);
    assert.match(taskpane, /await acceptBootstrap\(bootstrap\);\s*if \(bootstrapStateRevision !== expectedRevision \+ 1 \|\| taskpaneState\.busy\) \{\s*return;\s*\}\s*setStatus\('success', 'RailTime ist bereit'/);
    assert.match(taskpane, /authenticationWasLost\(error\)[\s\S]*?invalidateBootstrap\('locked', true\);[\s\S]*?failOpenSignatureCurrentState\(\);/);
    assert.match(taskpane, /Office\.EventType\?\.ItemChanged/);
    assert.match(taskpane, /void refreshSignatureCurrentState\(\)\.catch\(\(error\) =>/);
    assert.match(taskpane, /function handleMailboxItemChanged\(\) \{[\s\S]*?failOpenSignatureCurrentState\(\);\s*requestSignatureCurrentStateRefresh\(\);\s*\}/);
    assert.match(taskpane, /Office\.EventType\.ItemChanged,\s*handleMailboxItemChanged,\s*\(result\) =>/);
    assert.match(taskpane, /mailboxItemRevision \+= 1;\s*failOpenSignatureCurrentState\(\);/);
    assert.match(taskpane, /const target = captureComposeTarget\(\);[\s\S]*?await callback\(bootstrap, target\);/);
    assert.match(taskpane, /function assertComposeTarget\(target\) \{[\s\S]*?target\.revision !== mailboxItemRevision[\s\S]*?Office\.context\.mailbox\.item !== target\.item[\s\S]*?throw codedError\('ITEM_CHANGED'\);/);
    assert.match(taskpane, /await attachInlineMedia\(target, signature\.media\);\s*assertComposeTarget\(target\);\s*await setSignature\(item, signature\.html\);/);
    assert.match(taskpane, /await attachInlineMedia\(target, template\.media\);\s*assertComposeTarget\(target\);\s*await replaceBody\(item, template\.html\);/);
    assert.match(taskpane, /taskpaneState\.itemChangedMonitoringReady = result\?\.status === Office\.AsyncResultStatus\.Succeeded;[\s\S]*?if \(!taskpaneState\.itemChangedMonitoringReady\) \{[\s\S]*?failOpenSignatureCurrentState\(\);/);
    assert.match(taskpane, /\} else \{\s*taskpaneState\.itemChangedMonitoringReady = false;\s*failOpenSignatureCurrentState\(\);/);
    assert.doesNotMatch(taskpane, /localStorage/);
    assert.match(taskpane, /validatedDocument\(templateChoice\.document, 'template'/);
    assert.match(taskpane, /await replaceBody\(item, template\.html\);\s*await removeStaleManagedInlineMedia\(target, template\.media, previousAttachments\);/);
    assert.equal(occurrences(taskpane, /await refreshSignatureCurrentState\(\);/g), 3);
    assert.doesNotMatch(taskpane, /taskpaneState\.signatureCurrent = await signatureIsCurrent/);
});
